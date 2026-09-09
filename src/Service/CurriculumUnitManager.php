<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumUnit;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CurriculumUnitException;
use App\Exception\InstitutionOperationException;
use App\Repository\CurriculumUnitRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Curriculum unit mutations. Draft programs only. SUPER_ADMIN only.
 *
 * Lock order: Subject → Program → Unit.
 */
final class CurriculumUnitManager
{
    public function __construct(
        private readonly CurriculumUnitRepository $units,
        private readonly InstitutionNameNormalizer $nameNormalizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        CurriculumProgram $program,
        User $actor,
        string $code,
        string $title,
        int $position,
        string $reasonCode,
        ?int $estimatedMinutes = null,
    ): CurriculumUnit {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $code = $this->normalizeCode($code);
        $titles = $this->normalizeTitle($title);
        CurriculumUnit::assertValidPosition($position);
        CurriculumUnit::assertValidEstimatedMinutes($estimatedMinutes);

        $programId = $program->getId();
        $subjectId = $program->getSubject()->getId();
        $actorId = $actor->getId();

        try {
            $unit = $this->entityManager->wrapInTransaction(function () use (
                $programId,
                $subjectId,
                $actorId,
                $code,
                $titles,
                $position,
                $estimatedMinutes,
                $reasonCode,
            ): CurriculumUnit {
                [$lockedSubject, $lockedProgram] = $this->lockSubjectProgram($subjectId, $programId);
                $this->assertDraft($lockedProgram);
                $freshActor = $this->lockSuperAdminActor($actorId);

                if ($this->units->existsWithCode($lockedProgram, $code)
                    || $this->units->existsWithPosition($lockedProgram, $position)) {
                    throw CurriculumUnitException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $unit = CurriculumUnit::create(
                    $lockedProgram,
                    $code,
                    $titles['title'],
                    $titles['normalizedTitle'],
                    $position,
                    $estimatedMinutes,
                    $now,
                );
                $this->units->save($unit, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CurriculumUnitCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'curriculum_unit_manager',
                        'reason_code' => $reasonCode,
                        'subject_id' => $lockedSubject->getId()->toRfc4122(),
                        'curriculum_id' => $lockedProgram->getId()->toRfc4122(),
                        'curriculum_unit_id' => $unit->getId()->toRfc4122(),
                        'code' => $code,
                        'position' => $position,
                        'new_status' => $unit->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $unit;
            });
        } catch (UniqueConstraintViolationException) {
            throw CurriculumUnitException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumUnitException::conflict();
        }

        return $unit;
    }

    public function rename(CurriculumUnit $unit, User $actor, string $title, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $titles = $this->normalizeTitle($title);

        $this->mutate($unit, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($titles): void {
            $this->assertDraft($lockedProgram);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->rename($titles['title'], $titles['normalizedTitle'], $now);
            $this->units->save($locked, false);
            $this->recordUpdated($lockedSubject, $lockedProgram, $locked, $freshActor, $reasonCode, SecurityAuditAction::CurriculumUnitUpdated);
        });
    }

    public function reorder(CurriculumUnit $unit, User $actor, int $position, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        CurriculumUnit::assertValidPosition($position);

        $this->mutate($unit, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($position): void {
            $this->assertDraft($lockedProgram);
            if ($this->units->existsWithPosition($lockedProgram, $position)
                && $locked->getPosition() !== $position) {
                throw CurriculumUnitException::conflict();
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->reorder($position, $now);
            $this->units->save($locked, false);
            $this->recordUpdated(
                $lockedSubject,
                $lockedProgram,
                $locked,
                $freshActor,
                $reasonCode,
                SecurityAuditAction::CurriculumUnitReordered,
                ['position' => $position],
            );
        });
    }

    public function changeEstimatedMinutes(
        CurriculumUnit $unit,
        User $actor,
        ?int $estimatedMinutes,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        CurriculumUnit::assertValidEstimatedMinutes($estimatedMinutes);

        $this->mutate($unit, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($estimatedMinutes): void {
            $this->assertDraft($lockedProgram);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->changeEstimatedMinutes($estimatedMinutes, $now);
            $this->units->save($locked, false);
            $this->recordUpdated(
                $lockedSubject,
                $lockedProgram,
                $locked,
                $freshActor,
                $reasonCode,
                SecurityAuditAction::CurriculumUnitUpdated,
                ['estimated_minutes' => $estimatedMinutes],
            );
        });
    }

    public function archive(CurriculumUnit $unit, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        $this->mutate($unit, $actor, $reasonCode, function (
            Subject $lockedSubject,
            CurriculumProgram $lockedProgram,
            CurriculumUnit $locked,
            User $freshActor,
            string $reasonCode,
        ): void {
            $this->assertDraft($lockedProgram);
            $oldStatus = $locked->getStatus()->value;
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->archive($now);
            $this->units->save($locked, false);
            $this->recordUpdated(
                $lockedSubject,
                $lockedProgram,
                $locked,
                $freshActor,
                $reasonCode,
                SecurityAuditAction::CurriculumUnitArchived,
                ['old_status' => $oldStatus, 'new_status' => $locked->getStatus()->value],
            );
        });
    }

    /**
     * @param callable(Subject, CurriculumProgram, CurriculumUnit, User, string): void $mutator
     */
    private function mutate(CurriculumUnit $unit, User $actor, string $reasonCode, callable $mutator): void
    {
        $unitId = $unit->getId();
        $programId = $unit->getProgram()->getId();
        $subjectId = $unit->getProgram()->getSubject()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $unitId,
                $programId,
                $subjectId,
                $actorId,
                $reasonCode,
                $mutator,
            ): void {
                [$lockedSubject, $lockedProgram] = $this->lockSubjectProgram($subjectId, $programId);
                $locked = $this->freshEntities->findFreshLockedCurriculumUnit($unitId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof CurriculumUnit
                    || !$locked->getProgram()->getId()->equals($lockedProgram->getId())) {
                    throw CurriculumUnitException::notFound();
                }
                $freshActor = $this->lockSuperAdminActor($actorId);
                $mutator($lockedSubject, $lockedProgram, $locked, $freshActor, $reasonCode);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw CurriculumUnitException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumUnitException::conflict();
        }
    }

    /**
     * @param array<string, bool|float|int|string|null> $extra
     */
    private function recordUpdated(
        Subject $subject,
        CurriculumProgram $program,
        CurriculumUnit $unit,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        array $extra = [],
    ): void {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: array_merge([
                'source' => 'curriculum_unit_manager',
                'reason_code' => $reasonCode,
                'subject_id' => $subject->getId()->toRfc4122(),
                'curriculum_id' => $program->getId()->toRfc4122(),
                'curriculum_unit_id' => $unit->getId()->toRfc4122(),
                'code' => $unit->getCode(),
            ], $extra),
            captureRequestHashes: false,
        ), false);
    }

    /**
     * @return array{0: Subject, 1: CurriculumProgram}
     */
    private function lockSubjectProgram(Uuid $subjectId, Uuid $programId): array
    {
        $lockedSubject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedSubject instanceof Subject) {
            throw CurriculumUnitException::notFound();
        }
        $lockedProgram = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedProgram instanceof CurriculumProgram
            || !$lockedProgram->getSubject()->getId()->equals($lockedSubject->getId())) {
            throw CurriculumUnitException::notFound();
        }

        return [$lockedSubject, $lockedProgram];
    }

    private function assertDraft(CurriculumProgram $program): void
    {
        if (CurriculumStatus::Draft !== $program->getStatus()) {
            throw CurriculumUnitException::programNotDraft();
        }
    }

    private function lockSuperAdminActor(Uuid $actorId): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw CurriculumUnitException::userNotFound();
        }
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
            || !$this->activeVerifiedUserPolicy->isSuperAdmin($freshActor)) {
            throw CurriculumUnitException::unauthorized();
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
            throw CurriculumUnitException::invalidInput($e->getMessage());
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
            throw CurriculumUnitException::invalidInput('Unit code must be lowercase snake_case [a-z0-9_]{2,64}.');
        }

        return $code;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw CurriculumUnitException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
