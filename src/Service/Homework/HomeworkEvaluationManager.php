<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\SecurityAuditContext;
use App\Entity\HomeworkEvaluation;
use App\Entity\HomeworkRecipient;
use App\Entity\HomeworkSubmission;
use App\Entity\User;
use App\Enum\HomeworkEvaluationLabel;
use App\Enum\HomeworkSubmissionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\HomeworkEvaluationException;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\FreshUserLoader;
use App\Service\InstitutionalFreshEntityLoader;
use App\Service\SecurityAuditRecorder;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only teacher evaluation. Student enrollment is never locked or required.
 *
 * Lock order (#192/#193): institution WRITE → year WRITE → classroom WRITE →
 * assignment WRITE → recipient WRITE → submission WRITE → teacher assignment READ.
 */
final class HomeworkEvaluationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly HomeworkTeacherEvaluationScope $scope,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function record(
        User $actor,
        Uuid $submissionId,
        string $rawLabel,
        string $rawFeedback,
        ?Uuid $expectedCurrentEvaluationId,
    ): HomeworkEvaluation {
        $actorId = $actor->getId();

        try {
            $result = $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $submissionId,
                $rawLabel,
                $rawFeedback,
                $expectedCurrentEvaluationId,
            ): HomeworkEvaluation|HomeworkEvaluationException {
                $peek = $this->entityManager->createQueryBuilder()
                    ->select('s', 'a', 'c', 'i', 'y')
                    ->from(HomeworkSubmission::class, 's')
                    ->innerJoin('s.assignment', 'a')
                    ->innerJoin('a.classroom', 'c')
                    ->innerJoin('a.institution', 'i')
                    ->innerJoin('a.academicYear', 'y')
                    ->andWhere('s.id = :id')
                    ->setParameter('id', $submissionId, 'uuid')
                    ->getQuery()
                    ->getOneOrNullResult();
                if (!$peek instanceof HomeworkSubmission) {
                    return HomeworkEvaluationException::notFound();
                }

                $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                    $peek->getAssignment()->getInstitution()->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                $lockedYear = $this->freshEntities->findFreshLockedAcademicYear(
                    $peek->getAssignment()->getAcademicYear()->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                $lockedClassroom = $this->freshEntities->findFreshLockedClassroom(
                    $peek->getAssignment()->getClassroom()->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (null === $lockedInstitution || null === $lockedYear || null === $lockedClassroom) {
                    return HomeworkEvaluationException::notFound();
                }
                if (!$lockedClassroom->getInstitution()->getId()->equals($lockedInstitution->getId())
                    || !$lockedClassroom->getAcademicYear()->getId()->equals($lockedYear->getId())
                ) {
                    return HomeworkEvaluationException::notFound();
                }

                $assignment = $this->lockAssignment($peek->getAssignment()->getId());
                $freshActor = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$assignment instanceof \App\Entity\HomeworkAssignment) {
                    return HomeworkEvaluationException::notFound();
                }
                if (!$freshActor instanceof User || !$this->activeUsers->isActiveAndVerified($freshActor)) {
                    return HomeworkEvaluationException::forbidden();
                }
                if (!$assignment->getClassroom()->getId()->equals($lockedClassroom->getId())) {
                    return HomeworkEvaluationException::notFound();
                }
                if (!$this->scope->ownsAssignment($freshActor, $assignment)) {
                    return HomeworkEvaluationException::notFound();
                }

                $recipient = $this->lockRecipient($peek->getRecipient()->getId());
                if (!$recipient instanceof HomeworkRecipient
                    || !$recipient->getAssignment()->getId()->equals($assignment->getId())
                ) {
                    return HomeworkEvaluationException::notFound();
                }

                $membership = $this->scope->lockActiveTeacherMembership($freshActor, $assignment, true);
                if (null === $membership) {
                    return HomeworkEvaluationException::forbidden();
                }

                $submission = $this->scope->lockSubmission($peek);
                if (!$submission instanceof HomeworkSubmission
                    || !$submission->getAssignment()->getId()->equals($assignment->getId())
                    || !$submission->getRecipient()->getId()->equals($recipient->getId())
                ) {
                    return HomeworkEvaluationException::notFound();
                }
                if (HomeworkSubmissionStatus::Submitted !== $submission->getStatus()) {
                    return HomeworkEvaluationException::notSubmitted();
                }

                $current = $submission->getCurrentEvaluation();
                $currentId = $current?->getId();
                if (null === $expectedCurrentEvaluationId) {
                    if (null !== $currentId) {
                        return HomeworkEvaluationException::stale();
                    }
                } elseif (null === $currentId || !$currentId->equals($expectedCurrentEvaluationId)) {
                    return HomeworkEvaluationException::stale();
                } elseif (null !== $current && !$current->getSubmission()->getId()->equals($submission->getId())) {
                    return HomeworkEvaluationException::conflict();
                }

                $label = HomeworkEvaluationLabel::tryFrom(trim($rawLabel));
                if (null === $label) {
                    return HomeworkEvaluationException::invalidInput([
                        'label' => 'Geçerli bir değerlendirme etiketi seç.',
                    ]);
                }
                try {
                    $feedback = HomeworkText::feedback($rawFeedback, $label);
                } catch (\InvalidArgumentException $e) {
                    return HomeworkEvaluationException::invalidInput(['feedback' => $e->getMessage()]);
                }

                $now = UtcInstant::ensure($this->clock->now());
                $evaluation = HomeworkEvaluation::record(
                    $submission,
                    $freshActor,
                    $membership,
                    $label,
                    $feedback,
                    $current,
                    $now,
                );
                $this->entityManager->persist($evaluation);
                $this->entityManager->flush();
                $submission->assignCurrentEvaluation($evaluation);
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::HomeworkEvaluationRecorded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'resource_type' => 'homework_evaluation',
                        'resource_id' => $evaluation->getId()->toRfc4122(),
                        'submission_id' => $submission->getId()->toRfc4122(),
                        'assignment_id' => $assignment->getId()->toRfc4122(),
                        'institution_id' => $assignment->getInstitution()->getId()->toRfc4122(),
                        'classroom_id' => $assignment->getClassroom()->getId()->toRfc4122(),
                        'label' => $label->value,
                        'supersedes_evaluation_id' => $currentId?->toRfc4122(),
                    ],
                    correlationId: Uuid::v7()->toRfc4122(),
                ));

                return $evaluation;
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw HomeworkEvaluationException::conflict();
        } catch (HomeworkEvaluationException $e) {
            throw $e;
        }

        if ($result instanceof HomeworkEvaluationException) {
            throw $result;
        }

        return $result;
    }

    private function lockAssignment(Uuid $assignmentId): ?\App\Entity\HomeworkAssignment
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('a', 'd')
            ->from(\App\Entity\HomeworkAssignment::class, 'a')
            ->innerJoin('a.definition', 'd')
            ->andWhere('a.id = :id')
            ->setParameter('id', $assignmentId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row instanceof \App\Entity\HomeworkAssignment ? $row : null;
    }

    private function lockRecipient(Uuid $recipientId): ?HomeworkRecipient
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(HomeworkRecipient::class, 'r')
            ->andWhere('r.id = :id')
            ->setParameter('id', $recipientId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row instanceof HomeworkRecipient ? $row : null;
    }
}
