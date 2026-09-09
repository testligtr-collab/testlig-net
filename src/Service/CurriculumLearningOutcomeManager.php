<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\LearningOutcomeException;
use App\Repository\CurriculumLearningOutcomeRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Learning outcome mutations. Draft programs only. SUPER_ADMIN only.
 *
 * Lock order: Subject → Program → Unit → Topic → Outcome.
 */
final class CurriculumLearningOutcomeManager
{
    public function __construct(
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        CurriculumTopic $topic,
        User $actor,
        string $code,
        string $description,
        int $position,
        string $reasonCode,
    ): CurriculumLearningOutcome {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $code = $this->normalizeCode($code);
        $descriptions = $this->normalizeDescription($description);
        CurriculumLearningOutcome::assertValidPosition($position);

        $topicId = $topic->getId();
        $unitId = $topic->getUnit()->getId();
        $programId = $topic->getUnit()->getProgram()->getId();
        $subjectId = $topic->getUnit()->getProgram()->getSubject()->getId();
        $actorId = $actor->getId();

        try {
            $outcome = $this->entityManager->wrapInTransaction(function () use (
                $topicId,
                $unitId,
                $programId,
                $subjectId,
                $actorId,
                $code,
                $descriptions,
                $position,
                $reasonCode,
            ): CurriculumLearningOutcome {
                [$lockedSubject, $lockedProgram, $lockedUnit, $lockedTopic] = $this->lockHierarchy(
                    $subjectId,
                    $programId,
                    $unitId,
                    $topicId,
                );
                $this->assertDraft($lockedProgram);
                $freshActor = $this->lockSuperAdminActor($actorId);

                if ($this->outcomes->existsWithCode($lockedProgram, $code)) {
                    throw LearningOutcomeException::conflict();
                }
                if ($this->outcomes->existsWithPosition($lockedTopic, $position)) {
                    throw LearningOutcomeException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $outcome = CurriculumLearningOutcome::create(
                    $lockedTopic,
                    $lockedProgram,
                    $code,
                    $descriptions['description'],
                    $descriptions['normalizedDescription'],
                    $position,
                    $now,
                );
                $this->outcomes->save($outcome, false);
                $this->record(
                    SecurityAuditAction::CurriculumLearningOutcomeCreated,
                    $lockedSubject,
                    $lockedProgram,
                    $lockedUnit,
                    $lockedTopic,
                    $outcome,
                    $freshActor,
                    $reasonCode,
                    ['position' => $position, 'code' => $code, 'new_status' => $outcome->getStatus()->value],
                );
                $this->entityManager->flush();

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            throw LearningOutcomeException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw LearningOutcomeException::conflict();
        }

        return $outcome;
    }

    public function rename(CurriculumLearningOutcome $outcome, User $actor, string $description, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $descriptions = $this->normalizeDescription($description);

        $this->mutate($outcome, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $lockedUnit,
            CurriculumTopic $lockedTopic,
            CurriculumLearningOutcome $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($descriptions): void {
            $this->assertDraft($lockedProgram);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->rename($descriptions['description'], $descriptions['normalizedDescription'], $now);
            $this->outcomes->save($locked, false);
            $this->record(
                SecurityAuditAction::CurriculumLearningOutcomeUpdated,
                $lockedSubject,
                $lockedProgram,
                $lockedUnit,
                $lockedTopic,
                $locked,
                $freshActor,
                $reasonCode,
            );
        });
    }

    public function reorder(CurriculumLearningOutcome $outcome, User $actor, int $position, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        CurriculumLearningOutcome::assertValidPosition($position);

        $this->mutate($outcome, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $lockedUnit,
            CurriculumTopic $lockedTopic,
            CurriculumLearningOutcome $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($position): void {
            $this->assertDraft($lockedProgram);
            if ($this->outcomes->existsWithPosition($lockedTopic, $position)
                && $locked->getPosition() !== $position) {
                throw LearningOutcomeException::conflict();
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->reorder($position, $now);
            $this->outcomes->save($locked, false);
            $this->record(
                SecurityAuditAction::CurriculumLearningOutcomeReordered,
                $lockedSubject,
                $lockedProgram,
                $lockedUnit,
                $lockedTopic,
                $locked,
                $freshActor,
                $reasonCode,
                ['position' => $position],
            );
        });
    }

    public function archive(CurriculumLearningOutcome $outcome, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        $this->mutate($outcome, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $lockedUnit,
            CurriculumTopic $lockedTopic,
            CurriculumLearningOutcome $locked,
            User $freshActor,
            string $reasonCode,
        ): void {
            $this->assertDraft($lockedProgram);
            $oldStatus = $locked->getStatus()->value;
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->archive($now);
            $this->outcomes->save($locked, false);
            $this->record(
                SecurityAuditAction::CurriculumLearningOutcomeArchived,
                $lockedSubject,
                $lockedProgram,
                $lockedUnit,
                $lockedTopic,
                $locked,
                $freshActor,
                $reasonCode,
                ['old_status' => $oldStatus, 'new_status' => $locked->getStatus()->value],
            );
        });
    }

    /**
     * @param callable(Subject, CurriculumProgram, CurriculumUnit, CurriculumTopic, CurriculumLearningOutcome, User, string): void $mutator
     */
    private function mutate(CurriculumLearningOutcome $outcome, User $actor, string $reasonCode, callable $mutator): void
    {
        $outcomeId = $outcome->getId();
        $topicId = $outcome->getTopic()->getId();
        $unitId = $outcome->getUnit()->getId();
        $programId = $outcome->getCurriculumProgram()->getId();
        $subjectId = $outcome->getCurriculumProgram()->getSubject()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $outcomeId,
                $topicId,
                $unitId,
                $programId,
                $subjectId,
                $actorId,
                $reasonCode,
                $mutator,
            ): void {
                [$lockedSubject, $lockedProgram, $lockedUnit, $lockedTopic] = $this->lockHierarchy(
                    $subjectId,
                    $programId,
                    $unitId,
                    $topicId,
                );
                $locked = $this->freshEntities->findFreshLockedCurriculumLearningOutcome(
                    $outcomeId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$locked instanceof CurriculumLearningOutcome
                    || !$locked->getTopic()->getId()->equals($lockedTopic->getId())) {
                    throw LearningOutcomeException::notFound();
                }
                $freshActor = $this->lockSuperAdminActor($actorId);
                $mutator($lockedSubject, $lockedProgram, $lockedUnit, $lockedTopic, $locked, $freshActor, $reasonCode);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw LearningOutcomeException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw LearningOutcomeException::conflict();
        }
    }

    /**
     * @param array<string, bool|float|int|string|null> $extra
     */
    private function record(
        SecurityAuditAction $action,
        Subject $subject,
        CurriculumProgram $program,
        CurriculumUnit $unit,
        CurriculumTopic $topic,
        CurriculumLearningOutcome $outcome,
        User $actor,
        string $reasonCode,
        array $extra = [],
    ): void {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: array_merge([
                'source' => 'curriculum_learning_outcome_manager',
                'reason_code' => $reasonCode,
                'subject_id' => $subject->getId()->toRfc4122(),
                'curriculum_id' => $program->getId()->toRfc4122(),
                'curriculum_unit_id' => $unit->getId()->toRfc4122(),
                'curriculum_topic_id' => $topic->getId()->toRfc4122(),
                'learning_outcome_id' => $outcome->getId()->toRfc4122(),
            ], $extra),
            captureRequestHashes: false,
        ), false);
    }

    /**
     * @return array{0: Subject, 1: CurriculumProgram, 2: CurriculumUnit, 3: CurriculumTopic}
     */
    private function lockHierarchy(Uuid $subjectId, Uuid $programId, Uuid $unitId, Uuid $topicId): array
    {
        $lockedSubject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedSubject instanceof Subject) {
            throw LearningOutcomeException::notFound();
        }
        $lockedProgram = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedProgram instanceof CurriculumProgram
            || !$lockedProgram->getSubject()->getId()->equals($lockedSubject->getId())) {
            throw LearningOutcomeException::notFound();
        }
        $lockedUnit = $this->freshEntities->findFreshLockedCurriculumUnit($unitId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedUnit instanceof CurriculumUnit
            || !$lockedUnit->getProgram()->getId()->equals($lockedProgram->getId())) {
            throw LearningOutcomeException::notFound();
        }
        $lockedTopic = $this->freshEntities->findFreshLockedCurriculumTopic($topicId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedTopic instanceof CurriculumTopic
            || !$lockedTopic->getUnit()->getId()->equals($lockedUnit->getId())) {
            throw LearningOutcomeException::notFound();
        }

        return [$lockedSubject, $lockedProgram, $lockedUnit, $lockedTopic];
    }

    private function assertDraft(CurriculumProgram $program): void
    {
        if (CurriculumStatus::Draft !== $program->getStatus()) {
            throw LearningOutcomeException::programNotDraft();
        }
    }

    private function lockSuperAdminActor(Uuid $actorId): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw LearningOutcomeException::userNotFound();
        }
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
            || !$this->activeVerifiedUserPolicy->isSuperAdmin($freshActor)) {
            throw LearningOutcomeException::unauthorized();
        }

        return $freshActor;
    }

    /**
     * @return array{description: string, normalizedDescription: string}
     */
    private function normalizeDescription(string $description): array
    {
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
        if ('' === $description || mb_strlen($description) > 500) {
            throw LearningOutcomeException::invalidInput('Learning outcome description must be 1-500 characters.');
        }

        return [
            'description' => $description,
            'normalizedDescription' => mb_strtolower($description, 'UTF-8'),
        ];
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (1 !== preg_match('/^[a-z0-9_]+$/', $code) || \strlen($code) < 2 || \strlen($code) > 64) {
            throw LearningOutcomeException::invalidInput('Learning outcome code must be lowercase snake_case [a-z0-9_]{2,64}.');
        }

        return $code;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw LearningOutcomeException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
