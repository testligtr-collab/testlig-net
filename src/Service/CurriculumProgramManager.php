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
use App\Enum\CurriculumContentStatus;
use App\Enum\GradeLevel;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\SubjectStatus;
use App\Exception\CurriculumException;
use App\Exception\InstitutionOperationException;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\CurriculumTopicRepository;
use App\Repository\CurriculumUnitRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Curriculum program lifecycle. SUPER_ADMIN only.
 *
 * Lock order: Subject → CurriculumProgram → (units/topics when cloning).
 */
final class CurriculumProgramManager
{
    public function __construct(
        private readonly CurriculumProgramRepository $programs,
        private readonly CurriculumUnitRepository $units,
        private readonly CurriculumTopicRepository $topics,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly InstitutionNameNormalizer $nameNormalizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createDraft(
        Subject $subject,
        User $actor,
        GradeLevel $gradeLevel,
        string $code,
        string $name,
        string $version,
        string $reasonCode,
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validUntil = null,
    ): CurriculumProgram {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $code = $this->normalizeCode($code);
        $version = $this->normalizeVersion($version);
        $names = $this->normalizeName($name);
        $this->assertValidDateRange($validFrom, $validUntil);

        $subjectId = $subject->getId();
        $actorId = $actor->getId();

        try {
            $program = $this->entityManager->wrapInTransaction(function () use (
                $subjectId,
                $actorId,
                $gradeLevel,
                $code,
                $names,
                $version,
                $validFrom,
                $validUntil,
                $reasonCode,
            ): CurriculumProgram {
                $lockedSubject = $this->lockSubject($subjectId);
                if (SubjectStatus::Archived === $lockedSubject->getStatus()) {
                    throw CurriculumException::subjectArchived();
                }
                $freshActor = $this->lockSuperAdminActor($actorId);

                if ($this->programs->existsWithIdentity($lockedSubject, $gradeLevel, $code, $version)) {
                    throw CurriculumException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $program = CurriculumProgram::createDraft(
                    $lockedSubject,
                    $gradeLevel,
                    $code,
                    $names['name'],
                    $names['normalizedName'],
                    $version,
                    $validFrom,
                    $validUntil,
                    $now,
                );
                $this->programs->save($program, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CurriculumCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'curriculum_program_manager',
                        'reason_code' => $reasonCode,
                        'subject_id' => $lockedSubject->getId()->toRfc4122(),
                        'curriculum_id' => $program->getId()->toRfc4122(),
                        'grade_level' => $gradeLevel->value,
                        'code' => $code,
                        'version' => $version,
                        'new_status' => $program->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $program;
            });
        } catch (UniqueConstraintViolationException) {
            throw CurriculumException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumException::conflict();
        }

        $this->authCache->invalidateCurriculumProgram($program->getId());

        return $program;
    }

    public function publish(CurriculumProgram $program, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $programId = $program->getId();
        $subjectId = $program->getSubject()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $programId,
                $subjectId,
                $actorId,
                $reasonCode,
            ): void {
                $lockedSubject = $this->lockSubject($subjectId);
                $locked = $this->lockProgram($programId);
                if (!$locked->getSubject()->getId()->equals($lockedSubject->getId())) {
                    throw CurriculumException::conflict();
                }
                $freshActor = $this->lockSuperAdminActor($actorId);

                $this->assertValidDateRange($locked->getValidFrom(), $locked->getValidUntil());
                $this->assertNoPublishedOverlap($lockedSubject, $locked);

                $oldStatus = $locked->getStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $locked->publish($now);
                $this->programs->save($locked, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CurriculumPublished,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'curriculum_program_manager',
                        'reason_code' => $reasonCode,
                        'subject_id' => $lockedSubject->getId()->toRfc4122(),
                        'curriculum_id' => $locked->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $locked->getStatus()->value,
                        'version' => $locked->getVersion(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumException::conflict();
        }

        $this->authCache->invalidateCurriculumProgram($programId);
    }

    public function retire(CurriculumProgram $program, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $programId = $program->getId();
        $subjectId = $program->getSubject()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $programId,
                $subjectId,
                $actorId,
                $reasonCode,
            ): void {
                $lockedSubject = $this->lockSubject($subjectId);
                $locked = $this->lockProgram($programId);
                if (!$locked->getSubject()->getId()->equals($lockedSubject->getId())) {
                    throw CurriculumException::conflict();
                }
                $freshActor = $this->lockSuperAdminActor($actorId);

                $oldStatus = $locked->getStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $locked->retire($now);
                $this->programs->save($locked, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CurriculumRetired,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'curriculum_program_manager',
                        'reason_code' => $reasonCode,
                        'subject_id' => $lockedSubject->getId()->toRfc4122(),
                        'curriculum_id' => $locked->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $locked->getStatus()->value,
                        'version' => $locked->getVersion(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumException::conflict();
        }

        $this->authCache->invalidateCurriculumProgram($programId);
    }

    public function cloneAsNewVersion(
        CurriculumProgram $source,
        User $actor,
        string $newVersion,
        string $reasonCode,
    ): CurriculumProgram {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $newVersion = $this->normalizeVersion($newVersion);
        $sourceId = $source->getId();
        $subjectId = $source->getSubject()->getId();
        $actorId = $actor->getId();

        try {
            $clone = $this->entityManager->wrapInTransaction(function () use (
                $sourceId,
                $subjectId,
                $actorId,
                $newVersion,
                $reasonCode,
            ): CurriculumProgram {
                $lockedSubject = $this->lockSubject($subjectId);
                $lockedSource = $this->lockProgram($sourceId);
                if (!$lockedSource->getSubject()->getId()->equals($lockedSubject->getId())) {
                    throw CurriculumException::conflict();
                }
                $freshActor = $this->lockSuperAdminActor($actorId);

                if (SubjectStatus::Archived === $lockedSubject->getStatus()) {
                    throw CurriculumException::subjectArchived();
                }
                if ($this->programs->existsWithIdentity(
                    $lockedSubject,
                    $lockedSource->getGradeLevel(),
                    $lockedSource->getCode(),
                    $newVersion,
                )) {
                    throw CurriculumException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $clone = CurriculumProgram::createDraft(
                    $lockedSubject,
                    $lockedSource->getGradeLevel(),
                    $lockedSource->getCode(),
                    $lockedSource->getName(),
                    $lockedSource->getNormalizedName(),
                    $newVersion,
                    $lockedSource->getValidFrom(),
                    $lockedSource->getValidUntil(),
                    $now,
                );
                $this->programs->save($clone, false);

                $unitMap = [];
                foreach ($this->units->findByProgram($lockedSource) as $sourceUnit) {
                    $this->freshEntities->findFreshLockedCurriculumUnit($sourceUnit->getId(), LockMode::PESSIMISTIC_WRITE);
                    $clonedUnit = CurriculumUnit::create(
                        $clone,
                        $sourceUnit->getCode(),
                        $sourceUnit->getTitle(),
                        $sourceUnit->getNormalizedTitle(),
                        $sourceUnit->getPosition(),
                        $sourceUnit->getEstimatedMinutes(),
                        $now,
                    );
                    if (CurriculumContentStatus::Archived === $sourceUnit->getStatus()) {
                        $clonedUnit->archive($now);
                    }
                    $this->units->save($clonedUnit, false);
                    $unitMap[$sourceUnit->getId()->toRfc4122()] = $clonedUnit;
                }

                $topicMap = [];
                foreach ($this->units->findByProgram($lockedSource) as $sourceUnit) {
                    $clonedUnit = $unitMap[$sourceUnit->getId()->toRfc4122()];
                    $roots = [];
                    $children = [];
                    foreach ($this->topics->findByUnit($sourceUnit) as $sourceTopic) {
                        $this->freshEntities->findFreshLockedCurriculumTopic($sourceTopic->getId(), LockMode::PESSIMISTIC_WRITE);
                        if (null === $sourceTopic->getParent()) {
                            $roots[] = $sourceTopic;
                        } else {
                            $children[] = $sourceTopic;
                        }
                    }
                    foreach ($roots as $sourceTopic) {
                        $clonedTopic = CurriculumTopic::createRoot(
                            $clonedUnit,
                            $sourceTopic->getCode(),
                            $sourceTopic->getTitle(),
                            $sourceTopic->getNormalizedTitle(),
                            $sourceTopic->getPosition(),
                            $sourceTopic->getEstimatedMinutes(),
                            $now,
                        );
                        $this->topics->save($clonedTopic, false);
                        $topicMap[$sourceTopic->getId()->toRfc4122()] = $clonedTopic;
                    }
                    foreach ($children as $sourceTopic) {
                        $parent = $sourceTopic->getParent();
                        if (!$parent instanceof CurriculumTopic) {
                            throw CurriculumException::conflict();
                        }
                        $clonedParent = $topicMap[$parent->getId()->toRfc4122()] ?? null;
                        if (!$clonedParent instanceof CurriculumTopic) {
                            throw CurriculumException::conflict();
                        }
                        $clonedTopic = CurriculumTopic::createChild(
                            $clonedUnit,
                            $clonedParent,
                            $sourceTopic->getCode(),
                            $sourceTopic->getTitle(),
                            $sourceTopic->getNormalizedTitle(),
                            $sourceTopic->getPosition(),
                            $sourceTopic->getEstimatedMinutes(),
                            $now,
                        );
                        $this->topics->save($clonedTopic, false);
                        $topicMap[$sourceTopic->getId()->toRfc4122()] = $clonedTopic;
                    }
                    foreach ($roots as $sourceTopic) {
                        if (CurriculumContentStatus::Archived === $sourceTopic->getStatus()) {
                            $topicMap[$sourceTopic->getId()->toRfc4122()]->archive($now);
                        }
                    }
                    foreach ($children as $sourceTopic) {
                        if (CurriculumContentStatus::Archived === $sourceTopic->getStatus()) {
                            $topicMap[$sourceTopic->getId()->toRfc4122()]->archive($now);
                        }
                    }
                }

                foreach ($this->outcomes->findByProgram($lockedSource) as $sourceOutcome) {
                    $this->freshEntities->findFreshLockedCurriculumLearningOutcome(
                        $sourceOutcome->getId(),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    $clonedTopic = $topicMap[$sourceOutcome->getTopic()->getId()->toRfc4122()] ?? null;
                    if (!$clonedTopic instanceof CurriculumTopic) {
                        throw CurriculumException::conflict();
                    }
                    $clonedOutcome = CurriculumLearningOutcome::create(
                        $clonedTopic,
                        $clone,
                        $sourceOutcome->getCode(),
                        $sourceOutcome->getDescription(),
                        $sourceOutcome->getNormalizedDescription(),
                        $sourceOutcome->getPosition(),
                        $now,
                    );
                    if (CurriculumContentStatus::Archived === $sourceOutcome->getStatus()) {
                        $clonedOutcome->archive($now);
                    }
                    $this->outcomes->save($clonedOutcome, false);
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CurriculumCloned,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'curriculum_program_manager',
                        'reason_code' => $reasonCode,
                        'subject_id' => $lockedSubject->getId()->toRfc4122(),
                        'source_curriculum_id' => $lockedSource->getId()->toRfc4122(),
                        'curriculum_id' => $clone->getId()->toRfc4122(),
                        'version' => $newVersion,
                        'new_status' => $clone->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $clone;
            });
        } catch (UniqueConstraintViolationException) {
            throw CurriculumException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumException::conflict();
        }

        $this->authCache->invalidateCurriculumProgram($clone->getId());

        return $clone;
    }

    private function assertNoPublishedOverlap(Subject $subject, CurriculumProgram $candidate): void
    {
        $from = $candidate->getValidFrom();
        $until = $candidate->getValidUntil();

        foreach ($this->programs->findPublishedForSubjectAndGrade($subject, $candidate->getGradeLevel()) as $existing) {
            if ($existing->getId()->equals($candidate->getId())) {
                continue;
            }
            if ($this->rangesOverlap($from, $until, $existing->getValidFrom(), $existing->getValidUntil())) {
                throw CurriculumException::dateOverlap();
            }
        }
    }

    private function rangesOverlap(
        ?\DateTimeImmutable $aFrom,
        ?\DateTimeImmutable $aUntil,
        ?\DateTimeImmutable $bFrom,
        ?\DateTimeImmutable $bUntil,
    ): bool {
        // Null bounds mean unbounded on that side.
        $aStart = $aFrom?->format('Y-m-d') ?? '0001-01-01';
        $aEnd = $aUntil?->format('Y-m-d') ?? '9999-12-31';
        $bStart = $bFrom?->format('Y-m-d') ?? '0001-01-01';
        $bEnd = $bUntil?->format('Y-m-d') ?? '9999-12-31';

        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    private function assertValidDateRange(?\DateTimeImmutable $validFrom, ?\DateTimeImmutable $validUntil): void
    {
        if (null !== $validFrom && null !== $validUntil && $validFrom > $validUntil) {
            throw CurriculumException::invalidInput('valid_from must be on or before valid_until.');
        }
    }

    private function lockSubject(Uuid $subjectId): Subject
    {
        $locked = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof Subject) {
            throw CurriculumException::notFound();
        }

        return $locked;
    }

    private function lockProgram(Uuid $programId): CurriculumProgram
    {
        $locked = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof CurriculumProgram) {
            throw CurriculumException::notFound();
        }

        return $locked;
    }

    private function lockSuperAdminActor(Uuid $actorId): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw CurriculumException::userNotFound();
        }
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
            || !$this->activeVerifiedUserPolicy->isSuperAdmin($freshActor)) {
            throw CurriculumException::unauthorized();
        }

        return $freshActor;
    }

    /**
     * @return array{name: string, normalizedName: string, slug: string}
     */
    private function normalizeName(string $name): array
    {
        try {
            return $this->nameNormalizer->normalize($name);
        } catch (InstitutionOperationException $e) {
            throw CurriculumException::invalidInput($e->getMessage());
        }
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (1 !== preg_match('/^[a-z0-9_]+$/', $code) || \strlen($code) < 2 || \strlen($code) > 64) {
            throw CurriculumException::invalidInput('Curriculum code must be lowercase snake_case [a-z0-9_]{2,64}.');
        }

        return $code;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ('' === $version || mb_strlen($version) > 64) {
            throw CurriculumException::invalidInput('version must be 1-64 characters.');
        }

        return $version;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw CurriculumException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
