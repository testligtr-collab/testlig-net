<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CurriculumTopicException;
use App\Exception\InstitutionOperationException;
use App\Repository\CurriculumTopicRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Curriculum topic mutations. Draft programs only. Max depth 2. SUPER_ADMIN only.
 *
 * Lock order: Subject → Program → Unit → Topic.
 */
final class CurriculumTopicManager
{
    public function __construct(
        private readonly CurriculumTopicRepository $topics,
        private readonly InstitutionNameNormalizer $nameNormalizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createRoot(
        CurriculumUnit $unit,
        User $actor,
        string $code,
        string $title,
        int $position,
        string $reasonCode,
        ?int $estimatedMinutes = null,
    ): CurriculumTopic {
        return $this->create($unit, null, $actor, $code, $title, $position, $reasonCode, $estimatedMinutes);
    }

    public function createChild(
        CurriculumUnit $unit,
        CurriculumTopic $parent,
        User $actor,
        string $code,
        string $title,
        int $position,
        string $reasonCode,
        ?int $estimatedMinutes = null,
    ): CurriculumTopic {
        return $this->create($unit, $parent, $actor, $code, $title, $position, $reasonCode, $estimatedMinutes);
    }

    public function rename(CurriculumTopic $topic, User $actor, string $title, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $titles = $this->normalizeTitle($title);

        $this->mutate($topic, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $lockedUnit,
            CurriculumTopic $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($titles): void {
            $this->assertDraft($lockedProgram);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->rename($titles['title'], $titles['normalizedTitle'], $now);
            $this->topics->save($locked, false);
            $this->record(
                SecurityAuditAction::CurriculumTopicUpdated,
                $lockedSubject,
                $lockedProgram,
                $lockedUnit,
                $locked,
                $freshActor,
                $reasonCode,
            );
        });
    }

    public function reorder(CurriculumTopic $topic, User $actor, int $position, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        CurriculumTopic::assertValidPosition($position);

        $this->mutate($topic, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $lockedUnit,
            CurriculumTopic $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($position): void {
            $this->assertDraft($lockedProgram);
            $parent = $locked->getParent();
            if (null === $parent) {
                if ($this->topics->existsRootPosition($lockedUnit, $position)
                    && $locked->getPosition() !== $position) {
                    throw CurriculumTopicException::conflict();
                }
            } elseif ($this->topics->existsChildPosition($lockedUnit, $parent, $position)
                && $locked->getPosition() !== $position) {
                throw CurriculumTopicException::conflict();
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->reorder($position, $now);
            $this->topics->save($locked, false);
            $this->record(
                SecurityAuditAction::CurriculumTopicReordered,
                $lockedSubject,
                $lockedProgram,
                $lockedUnit,
                $locked,
                $freshActor,
                $reasonCode,
                ['position' => $position],
            );
        });
    }

    public function changeEstimatedMinutes(
        CurriculumTopic $topic,
        User $actor,
        ?int $estimatedMinutes,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        CurriculumTopic::assertValidEstimatedMinutes($estimatedMinutes);

        $this->mutate($topic, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $lockedUnit,
            CurriculumTopic $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($estimatedMinutes): void {
            $this->assertDraft($lockedProgram);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->changeEstimatedMinutes($estimatedMinutes, $now);
            $this->topics->save($locked, false);
            $this->record(
                SecurityAuditAction::CurriculumTopicUpdated,
                $lockedSubject,
                $lockedProgram,
                $lockedUnit,
                $locked,
                $freshActor,
                $reasonCode,
                ['estimated_minutes' => $estimatedMinutes],
            );
        });
    }

    public function archive(CurriculumTopic $topic, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        $this->mutate($topic, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $lockedUnit,
            CurriculumTopic $locked,
            User $freshActor,
            string $reasonCode,
        ): void {
            $this->assertDraft($lockedProgram);
            if ($this->topics->countActiveChildren($locked) > 0) {
                throw CurriculumTopicException::activeChildren();
            }
            $oldStatus = $locked->getStatus()->value;
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->archive($now);
            $this->topics->save($locked, false);
            $this->record(
                SecurityAuditAction::CurriculumTopicArchived,
                $lockedSubject,
                $lockedProgram,
                $lockedUnit,
                $locked,
                $freshActor,
                $reasonCode,
                ['old_status' => $oldStatus, 'new_status' => $locked->getStatus()->value],
            );
        });
    }

    private function create(
        CurriculumUnit $unit,
        ?CurriculumTopic $parent,
        User $actor,
        string $code,
        string $title,
        int $position,
        string $reasonCode,
        ?int $estimatedMinutes,
    ): CurriculumTopic {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $code = $this->normalizeCode($code);
        $titles = $this->normalizeTitle($title);
        CurriculumTopic::assertValidPosition($position);
        CurriculumTopic::assertValidEstimatedMinutes($estimatedMinutes);

        $unitId = $unit->getId();
        $programId = $unit->getProgram()->getId();
        $subjectId = $unit->getProgram()->getSubject()->getId();
        $parentId = $parent?->getId();
        $actorId = $actor->getId();

        try {
            $topic = $this->entityManager->wrapInTransaction(function () use (
                $unitId,
                $programId,
                $subjectId,
                $parentId,
                $actorId,
                $code,
                $titles,
                $position,
                $estimatedMinutes,
                $reasonCode,
            ): CurriculumTopic {
                [$lockedSubject, $lockedProgram, $lockedUnit] = $this->lockHierarchy($subjectId, $programId, $unitId);
                $this->assertDraft($lockedProgram);
                $freshActor = $this->lockSuperAdminActor($actorId);

                $lockedParent = null;
                if (null !== $parentId) {
                    $lockedParent = $this->freshEntities->findFreshLockedCurriculumTopic($parentId, LockMode::PESSIMISTIC_WRITE);
                    if (!$lockedParent instanceof CurriculumTopic
                        || !$lockedParent->getUnit()->getId()->equals($lockedUnit->getId())) {
                        throw CurriculumTopicException::crossUnit();
                    }
                    if (null !== $lockedParent->getParent()) {
                        throw CurriculumTopicException::depthExceeded();
                    }
                    if (CurriculumContentStatus::Archived === $lockedParent->getStatus()) {
                        throw CurriculumTopicException::invalidInput('Cannot create child under an archived parent topic.');
                    }
                    if ($this->topics->existsChildPosition($lockedUnit, $lockedParent, $position)) {
                        throw CurriculumTopicException::conflict();
                    }
                } elseif ($this->topics->existsRootPosition($lockedUnit, $position)) {
                    throw CurriculumTopicException::conflict();
                }

                if ($this->topics->existsWithCode($lockedUnit, $code)) {
                    throw CurriculumTopicException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $topic = null === $lockedParent
                    ? CurriculumTopic::createRoot(
                        $lockedUnit,
                        $code,
                        $titles['title'],
                        $titles['normalizedTitle'],
                        $position,
                        $estimatedMinutes,
                        $now,
                    )
                    : CurriculumTopic::createChild(
                        $lockedUnit,
                        $lockedParent,
                        $code,
                        $titles['title'],
                        $titles['normalizedTitle'],
                        $position,
                        $estimatedMinutes,
                        $now,
                    );
                $this->topics->save($topic, false);

                $extra = ['position' => $position, 'code' => $code, 'new_status' => $topic->getStatus()->value];
                if (null !== $lockedParent) {
                    $extra['parent_topic_id'] = $lockedParent->getId()->toRfc4122();
                }
                $this->record(
                    SecurityAuditAction::CurriculumTopicCreated,
                    $lockedSubject,
                    $lockedProgram,
                    $lockedUnit,
                    $topic,
                    $freshActor,
                    $reasonCode,
                    $extra,
                );

                $this->entityManager->flush();

                return $topic;
            });
        } catch (UniqueConstraintViolationException) {
            throw CurriculumTopicException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumTopicException::conflict();
        }

        return $topic;
    }

    /**
     * @param callable(Subject, CurriculumProgram, CurriculumUnit, CurriculumTopic, User, string): void $mutator
     */
    private function mutate(CurriculumTopic $topic, User $actor, string $reasonCode, callable $mutator): void
    {
        $topicId = $topic->getId();
        $unitId = $topic->getUnit()->getId();
        $programId = $topic->getUnit()->getProgram()->getId();
        $subjectId = $topic->getUnit()->getProgram()->getSubject()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $topicId,
                $unitId,
                $programId,
                $subjectId,
                $actorId,
                $reasonCode,
                $mutator,
            ): void {
                [$lockedSubject, $lockedProgram, $lockedUnit] = $this->lockHierarchy($subjectId, $programId, $unitId);
                $locked = $this->freshEntities->findFreshLockedCurriculumTopic($topicId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof CurriculumTopic
                    || !$locked->getUnit()->getId()->equals($lockedUnit->getId())) {
                    throw CurriculumTopicException::notFound();
                }
                $freshActor = $this->lockSuperAdminActor($actorId);
                $mutator($lockedSubject, $lockedProgram, $lockedUnit, $locked, $freshActor, $reasonCode);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw CurriculumTopicException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumTopicException::conflict();
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
                'source' => 'curriculum_topic_manager',
                'reason_code' => $reasonCode,
                'subject_id' => $subject->getId()->toRfc4122(),
                'curriculum_id' => $program->getId()->toRfc4122(),
                'curriculum_unit_id' => $unit->getId()->toRfc4122(),
                'curriculum_topic_id' => $topic->getId()->toRfc4122(),
            ], $extra),
            captureRequestHashes: false,
        ), false);
    }

    /**
     * @return array{0: Subject, 1: CurriculumProgram, 2: CurriculumUnit}
     */
    private function lockHierarchy(Uuid $subjectId, Uuid $programId, Uuid $unitId): array
    {
        $lockedSubject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedSubject instanceof Subject) {
            throw CurriculumTopicException::notFound();
        }
        $lockedProgram = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedProgram instanceof CurriculumProgram
            || !$lockedProgram->getSubject()->getId()->equals($lockedSubject->getId())) {
            throw CurriculumTopicException::notFound();
        }
        $lockedUnit = $this->freshEntities->findFreshLockedCurriculumUnit($unitId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedUnit instanceof CurriculumUnit
            || !$lockedUnit->getProgram()->getId()->equals($lockedProgram->getId())) {
            throw CurriculumTopicException::notFound();
        }

        return [$lockedSubject, $lockedProgram, $lockedUnit];
    }

    private function assertDraft(CurriculumProgram $program): void
    {
        if (CurriculumStatus::Draft !== $program->getStatus()) {
            throw CurriculumTopicException::programNotDraft();
        }
    }

    private function lockSuperAdminActor(Uuid $actorId): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw CurriculumTopicException::userNotFound();
        }
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
            || !$this->activeVerifiedUserPolicy->isSuperAdmin($freshActor)) {
            throw CurriculumTopicException::unauthorized();
        }

        return $freshActor;
    }

    /**
     * @return array{title: string, normalizedTitle: string}
     */
    private function normalizeTitle(string $title): array
    {
        try {
            $names = $this->nameNormalizer->normalize($title);
        } catch (InstitutionOperationException $e) {
            throw CurriculumTopicException::invalidInput($e->getMessage());
        }

        return [
            'title' => $names['name'],
            'normalizedTitle' => $names['normalizedName'],
        ];
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (1 !== preg_match('/^[a-z0-9_]+$/', $code) || \strlen($code) < 2 || \strlen($code) > 64) {
            throw CurriculumTopicException::invalidInput('Topic code must be lowercase snake_case [a-z0-9_]{2,64}.');
        }

        return $code;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw CurriculumTopicException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
