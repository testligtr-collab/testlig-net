<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\SecurityAuditContext;
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
use App\Repository\HomeworkSubmissionAttachmentRepository;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\FreshUserLoader;
use App\Service\SecurityAuditRecorder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Uid\Uuid;

/**
 * Assigning teacher may stream a submitted homework PDF. Private storage; no public URL.
 */
final class HomeworkPdfTeacherDownload
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly HomeworkTeacherEvaluationScope $scope,
        private readonly HomeworkSubmissionAttachmentRepository $attachments,
        private readonly HomeworkPdfDurableStore $durable,
        private readonly SecurityAuditRecorder $auditRecorder,
    ) {
    }

    public function download(User $actor, Uuid $submissionId): BinaryFileResponse
    {
        $freshActor = $this->freshUsers->findFreshLockedUser($actor->getId(), LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User || !$this->activeUsers->isActiveAndVerified($freshActor)) {
            throw HomeworkSubmissionException::forbidden();
        }

        /** @var HomeworkSubmission|null $submission */
        $submission = $this->entityManager->createQueryBuilder()
            ->select('s', 'a', 'r', 'd', 'c', 'i')
            ->from(HomeworkSubmission::class, 's')
            ->innerJoin('s.assignment', 'a')
            ->innerJoin('s.recipient', 'r')
            ->innerJoin('a.definition', 'd')
            ->innerJoin('a.classroom', 'c')
            ->innerJoin('a.institution', 'i')
            ->andWhere('s.id = :id')
            ->andWhere('s.status = :submitted')
            ->setParameter('id', $submissionId, 'uuid')
            ->setParameter('submitted', HomeworkSubmissionStatus::Submitted)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$submission instanceof HomeworkSubmission) {
            throw HomeworkSubmissionException::notFound();
        }
        if (!$this->scope->canManageSubmitted($freshActor, $submission, true)) {
            throw HomeworkSubmissionException::notFound();
        }

        $attachment = $this->attachments->findForSubmission($submission);
        if (!$attachment instanceof HomeworkSubmissionAttachment) {
            throw HomeworkSubmissionException::notFound();
        }

        try {
            $path = $this->durable->assertReadable(
                $attachment->getStorageKey(),
                $attachment->getSha256(),
                $attachment->getByteSize(),
            );
        } catch (HomeworkPdfScanException) {
            throw HomeworkSubmissionException::notFound();
        }

        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::HomeworkSubmissionPdfDownloaded,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $freshActor,
            metadata: [
                'resource_type' => 'homework_submission_attachment',
                'resource_id' => $attachment->getId()->toRfc4122(),
                'submission_id' => $submission->getId()->toRfc4122(),
                'assignment_id' => $submission->getAssignment()->getId()->toRfc4122(),
                'institution_id' => $submission->getAssignment()->getInstitution()->getId()->toRfc4122(),
                'byte_size' => $attachment->getByteSize(),
            ],
            correlationId: Uuid::v7()->toRfc4122(),
        ));

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->getDisplayName(),
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setPrivate();

        return $response;
    }
}
