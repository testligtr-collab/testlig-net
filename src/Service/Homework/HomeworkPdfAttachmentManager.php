<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\SecurityAuditContext;
use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkRecipient;
use App\Entity\HomeworkSubmission;
use App\Entity\HomeworkSubmissionAttachment;
use App\Entity\User;
use App\Enum\HomeworkSubmissionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\HomeworkSubmissionException;
use App\Homework\Pdf\HomeworkPdfDurableStore;
use App\Homework\Pdf\HomeworkPdfScanException;
use App\Homework\Pdf\HomeworkPdfScanPipeline;
use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Homework\Pdf\HomeworkPdfUploadGate;
use App\LearningContent\Document\PdfDocumentInspector;
use App\Repository\HomeworkSubmissionAttachmentRepository;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\FreshUserLoader;
use App\Service\InstitutionalFreshEntityLoader;
use App\Service\SecurityAuditRecorder;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Optional single PDF attach on a writable student draft. Never auto-submits.
 *
 * Order: gate → scan pipeline → re-check writable draft under locks → durable commit → DB.
 * Existing attachment is never replaced silently.
 */
final class HomeworkPdfAttachmentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly HomeworkStudentScope $scope,
        private readonly HomeworkPdfUploadGate $gate,
        private readonly HomeworkPdfScanPipeline $pipeline,
        private readonly HomeworkPdfDurableStore $durable,
        private readonly HomeworkSubmissionAttachmentRepository $attachments,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function attach(User $actor, Uuid $assignmentId, string $bytes, string $clientName, int $expectedRevision): HomeworkSubmissionAttachment
    {
        if (!$this->gate->mayAcceptUpload()) {
            throw HomeworkSubmissionException::pdfClosed();
        }
        $size = \strlen($bytes);
        if ($size < 1 || $size > PdfDocumentInspector::MAX_BYTES) {
            throw HomeworkSubmissionException::pdfRejected(PdfDocumentInspector::MESSAGE_SIZE);
        }

        try {
            $staging = $this->pipeline->stageCleanCandidate($bytes, $clientName);
        } catch (HomeworkPdfScanException $exception) {
            throw $this->mapScanException($exception);
        }

        $durableKey = null;
        try {
            // Re-assert gate after scan (flag/readiness must still be open before durable+DB).
            if (!$this->gate->mayAcceptUpload()) {
                $staging->abandon();
                throw HomeworkSubmissionException::pdfClosed();
            }

            $attachment = $this->entityManager->wrapInTransaction(function () use (
                $actor,
                $assignmentId,
                $expectedRevision,
                $staging,
                &$durableKey,
            ): HomeworkSubmissionAttachment|HomeworkSubmissionException {
                $actorId = $actor->getId();
                $peek = $this->entityManager->createQueryBuilder()
                    ->select('a', 'c', 'i', 'y')
                    ->from(HomeworkAssignment::class, 'a')
                    ->innerJoin('a.classroom', 'c')
                    ->innerJoin('a.institution', 'i')
                    ->innerJoin('a.academicYear', 'y')
                    ->andWhere('a.id = :id')
                    ->setParameter('id', $assignmentId, 'uuid')
                    ->getQuery()
                    ->getOneOrNullResult();
                if (!$peek instanceof HomeworkAssignment) {
                    return HomeworkSubmissionException::notFound();
                }

                $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                    $peek->getInstitution()->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                $lockedYear = $this->freshEntities->findFreshLockedAcademicYear(
                    $peek->getAcademicYear()->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                $lockedClassroom = $this->freshEntities->findFreshLockedClassroom(
                    $peek->getClassroom()->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (null === $lockedInstitution || null === $lockedYear || null === $lockedClassroom) {
                    return HomeworkSubmissionException::notFound();
                }
                if (!$lockedClassroom->getInstitution()->getId()->equals($lockedInstitution->getId())
                    || !$lockedClassroom->getAcademicYear()->getId()->equals($lockedYear->getId())
                ) {
                    return HomeworkSubmissionException::notFound();
                }

                $assignment = $this->lockAssignment($assignmentId);
                $freshActor = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$assignment instanceof HomeworkAssignment) {
                    return HomeworkSubmissionException::notFound();
                }
                if (!$freshActor instanceof User || !$this->activeUsers->isActiveAndVerified($freshActor)) {
                    return HomeworkSubmissionException::forbidden();
                }
                if (!$assignment->getClassroom()->getId()->equals($lockedClassroom->getId())) {
                    return HomeworkSubmissionException::notFound();
                }

                $recipient = $this->scope->findRecipient($freshActor, $assignmentId, true);
                if (!$recipient instanceof HomeworkRecipient) {
                    return HomeworkSubmissionException::notFound();
                }
                if (!$recipient->getAssignment()->getId()->equals($assignment->getId())
                    || !$recipient->getStudentUser()->getId()->equals($freshActor->getId())
                ) {
                    return HomeworkSubmissionException::notFound();
                }

                $enrollment = $this->scope->findOpenEnrollment($freshActor, $assignment, true, true);
                if (null === $enrollment) {
                    return HomeworkSubmissionException::closed();
                }
                $membership = $enrollment->getStudentMembership();
                $lockedMembership = $this->freshEntities->findFreshLockedMembership(
                    $membership->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (null === $lockedMembership || !$this->scope->membershipStillActive($lockedMembership)) {
                    return HomeworkSubmissionException::closed();
                }

                $now = UtcInstant::ensure($this->clock->now());
                if ($now > $assignment->getDueAt()) {
                    return HomeworkSubmissionException::duePassed();
                }

                $submission = $this->scope->findSubmissionForRecipient($recipient, true);
                if ($submission instanceof HomeworkSubmission) {
                    if (HomeworkSubmissionStatus::Submitted === $submission->getStatus()) {
                        return HomeworkSubmissionException::alreadySubmitted();
                    }
                    if (!$submission->getStudentUser()->getId()->equals($freshActor->getId())
                        || !$submission->getAssignment()->getId()->equals($assignment->getId())
                        || !$submission->getRecipient()->getId()->equals($recipient->getId())
                    ) {
                        return HomeworkSubmissionException::notFound();
                    }
                    if (null !== $this->attachments->findForSubmission($submission)) {
                        return HomeworkSubmissionException::pdfExists();
                    }
                    try {
                        $submission->bumpRevisionForAttachment($expectedRevision, $now);
                    } catch (HomeworkSubmissionException $e) {
                        return $e;
                    }
                } else {
                    if (0 !== $expectedRevision) {
                        return HomeworkSubmissionException::staleRevision();
                    }
                    $submission = HomeworkSubmission::openDraft($recipient, '', $now);
                    $this->entityManager->persist($submission);
                    $this->entityManager->flush();
                    try {
                        $submission->bumpRevisionForAttachment($submission->getRevisionNumber(), $now);
                    } catch (HomeworkSubmissionException $e) {
                        return $e;
                    }
                }

                try {
                    $durableKey = $this->durable->commitFromQuarantine($staging->file());
                } catch (HomeworkPdfScanException $exception) {
                    return $this->mapScanException($exception);
                }

                $attachment = HomeworkSubmissionAttachment::register(
                    $submission,
                    $durableKey,
                    $staging->displayName(),
                    $staging->file()->byteSize(),
                    $staging->file()->sha256(),
                    $now,
                );
                $this->entityManager->persist($attachment);
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::HomeworkSubmissionPdfAttached,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'resource_type' => 'homework_submission_attachment',
                        'resource_id' => $attachment->getId()->toRfc4122(),
                        'submission_id' => $submission->getId()->toRfc4122(),
                        'assignment_id' => $assignment->getId()->toRfc4122(),
                        'institution_id' => $assignment->getInstitution()->getId()->toRfc4122(),
                        'byte_size' => $attachment->getByteSize(),
                        'revision_number' => $submission->getRevisionNumber(),
                    ],
                    correlationId: Uuid::v7()->toRfc4122(),
                ));

                return $attachment;
            });
        } catch (UniqueConstraintViolationException) {
            $staging->abandon();
            if (\is_string($durableKey)) {
                $this->durable->deleteQuietly($durableKey);
            }
            throw HomeworkSubmissionException::pdfExists();
        } catch (DeadlockException|LockWaitTimeoutException) {
            $staging->abandon();
            if (\is_string($durableKey)) {
                $this->durable->deleteQuietly($durableKey);
            }
            throw HomeworkSubmissionException::conflict();
        } catch (HomeworkSubmissionException $e) {
            $staging->abandon();
            if (\is_string($durableKey)) {
                $this->durable->deleteQuietly($durableKey);
            }
            throw $e;
        } catch (\Throwable) {
            $staging->abandon();
            if (\is_string($durableKey)) {
                $this->durable->deleteQuietly($durableKey);
            }
            throw HomeworkSubmissionException::pdfRejected();
        }

        if ($attachment instanceof HomeworkSubmissionException) {
            $staging->abandon();
            if (\is_string($durableKey)) {
                $this->durable->deleteQuietly($durableKey);
            }
            throw $attachment;
        }

        $staging->abandon();

        return $attachment;
    }

    private function mapScanException(HomeworkPdfScanException $exception): HomeworkSubmissionException
    {
        $code = $exception->getMessage();
        if (HomeworkPdfUploadGate::DENY_CODE === $code) {
            return HomeworkSubmissionException::pdfClosed();
        }
        if (str_starts_with($code, 'homework_pdf_scan_')) {
            $verdict = substr($code, \strlen('homework_pdf_scan_'));

            return match ($verdict) {
                HomeworkPdfScanVerdict::SizeLimit->value => HomeworkSubmissionException::pdfRejected(PdfDocumentInspector::MESSAGE_SIZE),
                HomeworkPdfScanVerdict::Infected->value => HomeworkSubmissionException::pdfRejected('PDF güvenlik taramasından geçemedi.'),
                HomeworkPdfScanVerdict::TimedOut->value,
                HomeworkPdfScanVerdict::Unavailable->value => HomeworkSubmissionException::pdfRejected('PDF taraması tamamlanamadı. Sonra tekrar dene.'),
                default => HomeworkSubmissionException::pdfRejected(PdfDocumentInspector::MESSAGE_TYPE),
            };
        }

        return HomeworkSubmissionException::pdfRejected();
    }

    private function lockAssignment(Uuid $assignmentId): ?HomeworkAssignment
    {
        return $this->entityManager->createQueryBuilder()
            ->select('a', 'c', 'i', 'y')
            ->from(HomeworkAssignment::class, 'a')
            ->innerJoin('a.classroom', 'c')
            ->innerJoin('a.institution', 'i')
            ->innerJoin('a.academicYear', 'y')
            ->andWhere('a.id = :id')
            ->setParameter('id', $assignmentId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }
}
