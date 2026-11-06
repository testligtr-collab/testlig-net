<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\SecurityAuditContext;
use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkRecipient;
use App\Entity\HomeworkSubmission;
use App\Entity\User;
use App\Enum\HomeworkSubmissionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\HomeworkSubmissionException;
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
 * Student draft save and one-shot submit.
 *
 * Lock protocol (aligned with HomeworkAssignmentManager / enrollment hierarchy):
 * institution WRITE → academic year WRITE → classroom WRITE → assignment WRITE →
 * recipient WRITE → existing submission WRITE (if any) → current enrollment WRITE →
 * current membership WRITE. Actor is locked READ. First-create races serialize on the
 * recipient row; UNIQUE(recipient_id) / UNIQUE(assignment_id, student_user_id) catch
 * insert races. Snapshot enrollment_id is historical; live enrollment is re-resolved.
 */
final class HomeworkSubmissionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly HomeworkStudentScope $scope,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function saveDraft(User $actor, Uuid $assignmentId, string $rawBody, int $expectedRevision): HomeworkSubmission
    {
        return $this->write($actor, $assignmentId, $rawBody, $expectedRevision, submit: false, confirmed: false);
    }

    public function submit(User $actor, Uuid $assignmentId, string $rawBody, int $expectedRevision, bool $confirmed): HomeworkSubmission
    {
        return $this->write($actor, $assignmentId, $rawBody, $expectedRevision, submit: true, confirmed: $confirmed);
    }

    private function write(
        User $actor,
        Uuid $assignmentId,
        string $rawBody,
        int $expectedRevision,
        bool $submit,
        bool $confirmed,
    ): HomeworkSubmission {
        $actorId = $actor->getId();

        try {
            $result = $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $assignmentId,
                $rawBody,
                $expectedRevision,
                $submit,
                $confirmed,
            ): HomeworkSubmission|HomeworkSubmissionException {
                if ($submit && !$confirmed) {
                    return HomeworkSubmissionException::confirmationRequired();
                }

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
                // Assignment status is Assigned-only in this slice (see HomeworkAssignmentStatus).
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

                try {
                    $body = $submit
                        ? HomeworkText::submittedBody($rawBody)
                        : HomeworkText::draftBody($rawBody);
                } catch (\InvalidArgumentException $e) {
                    return HomeworkSubmissionException::invalidInput(['body' => $e->getMessage()]);
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
                    // Return domain errors (do not throw) so Doctrine keeps the EM open for error pages.
                    try {
                        if ($submit) {
                            $submission->markSubmitted($body, $expectedRevision, $now);
                        } else {
                            $submission->reviseDraft($body, $expectedRevision, $now);
                        }
                    } catch (HomeworkSubmissionException $e) {
                        return $e;
                    }
                } else {
                    // First row: expectedRevision must be 0 (no prior version).
                    if (0 !== $expectedRevision) {
                        return HomeworkSubmissionException::staleRevision();
                    }
                    $submission = HomeworkSubmission::openDraft($recipient, $body, $now);
                    if ($submit) {
                        try {
                            $submission->markSubmitted($body, $submission->getRevisionNumber(), $now);
                        } catch (HomeworkSubmissionException $e) {
                            return $e;
                        }
                    }
                    $this->entityManager->persist($submission);
                }

                $this->entityManager->flush();

                if ($submit) {
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::HomeworkSubmissionSubmitted,
                        actorType: SecurityAuditActorType::User,
                        outcome: SecurityAuditOutcome::Success,
                        actorUser: $freshActor,
                        metadata: [
                            'resource_type' => 'homework_submission',
                            'resource_id' => $submission->getId()->toRfc4122(),
                            'assignment_id' => $assignment->getId()->toRfc4122(),
                            'recipient_id' => $recipient->getId()->toRfc4122(),
                            'institution_id' => $assignment->getInstitution()->getId()->toRfc4122(),
                            'classroom_id' => $assignment->getClassroom()->getId()->toRfc4122(),
                            'academic_year_id' => $assignment->getAcademicYear()->getId()->toRfc4122(),
                            'status' => $submission->getStatus()->value,
                            'revision_number' => $submission->getRevisionNumber(),
                        ],
                        correlationId: Uuid::v7()->toRfc4122(),
                    ));
                } else {
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::HomeworkSubmissionDraftSaved,
                        actorType: SecurityAuditActorType::User,
                        outcome: SecurityAuditOutcome::Success,
                        actorUser: $freshActor,
                        metadata: [
                            'resource_type' => 'homework_submission',
                            'resource_id' => $submission->getId()->toRfc4122(),
                            'assignment_id' => $assignment->getId()->toRfc4122(),
                            'recipient_id' => $recipient->getId()->toRfc4122(),
                            'institution_id' => $assignment->getInstitution()->getId()->toRfc4122(),
                            'classroom_id' => $assignment->getClassroom()->getId()->toRfc4122(),
                            'academic_year_id' => $assignment->getAcademicYear()->getId()->toRfc4122(),
                            'status' => $submission->getStatus()->value,
                            'revision_number' => $submission->getRevisionNumber(),
                        ],
                        correlationId: Uuid::v7()->toRfc4122(),
                    ));
                }

                return $submission;
            });
        } catch (UniqueConstraintViolationException) {
            throw HomeworkSubmissionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw HomeworkSubmissionException::conflict();
        } catch (HomeworkSubmissionException $e) {
            throw $e;
        }

        if ($result instanceof HomeworkSubmissionException) {
            throw $result;
        }

        return $result;
    }

    private function lockAssignment(Uuid $assignmentId): ?HomeworkAssignment
    {
        $assignment = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(HomeworkAssignment::class, 'a')
            ->andWhere('a.id = :id')
            ->setParameter('id', $assignmentId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $assignment instanceof HomeworkAssignment ? $assignment : null;
    }
}
