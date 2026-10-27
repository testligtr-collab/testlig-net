<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumOperation;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\SubjectStatus;
use App\Exception\InstitutionOperationException;
use App\Exception\SubjectException;
use App\Repository\SubjectRepository;
use App\Security\CurriculumActorPolicy;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Platform-global subject catalog. Curriculum actor policy.
 *
 * Lock order: Subject → User (actor).
 */
final class SubjectManager
{
    public function __construct(
        private readonly SubjectRepository $subjects,
        private readonly InstitutionNameNormalizer $nameNormalizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly CurriculumActorPolicy $actorPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(User $actor, string $code, string $name, string $reasonCode): Subject
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $code = $this->normalizeCode($code);
        $names = $this->normalizeName($name);
        $actorId = $actor->getId();

        try {
            $subject = $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $code,
                $names,
                $reasonCode,
            ): Subject {
                $freshActor = $this->lockActor($actorId);

                if ($this->subjects->existsWithCode($code) || $this->subjects->existsWithSlug($names['slug'])) {
                    throw SubjectException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $subject = Subject::create(
                    $code,
                    $names['name'],
                    $names['normalizedName'],
                    $names['slug'],
                    $now,
                );
                $this->subjects->save($subject, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::SubjectCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'subject_manager',
                        'reason_code' => $reasonCode,
                        'subject_id' => $subject->getId()->toRfc4122(),
                        'code' => $subject->getCode(),
                        'new_status' => $subject->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $subject;
            });
        } catch (UniqueConstraintViolationException) {
            throw SubjectException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw SubjectException::conflict();
        }

        return $subject;
    }

    public function rename(Subject $subject, User $actor, string $name, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $names = $this->normalizeName($name);
        $subjectId = $subject->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $subjectId,
                $actorId,
                $names,
                $reasonCode,
            ): void {
                $locked = $this->lockSubject($subjectId);
                $freshActor = $this->lockActor($actorId);

                if (SubjectStatus::Archived === $locked->getStatus()) {
                    throw SubjectException::subjectArchived();
                }
                if ($this->subjects->existsWithSlug($names['slug']) && $locked->getSlug() !== $names['slug']) {
                    throw SubjectException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $locked->rename($names['name'], $names['normalizedName'], $names['slug'], $now);
                $this->subjects->save($locked, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::SubjectUpdated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'subject_manager',
                        'reason_code' => $reasonCode,
                        'subject_id' => $locked->getId()->toRfc4122(),
                        'code' => $locked->getCode(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw SubjectException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw SubjectException::conflict();
        }
    }

    public function archive(Subject $subject, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $subjectId = $subject->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $subjectId,
                $actorId,
                $reasonCode,
            ): void {
                $locked = $this->lockSubject($subjectId);
                $freshActor = $this->lockActor($actorId, CurriculumOperation::Archive);

                $oldStatus = $locked->getStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $locked->archive($now);
                $this->subjects->save($locked, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::SubjectArchived,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'subject_manager',
                        'reason_code' => $reasonCode,
                        'subject_id' => $locked->getId()->toRfc4122(),
                        'code' => $locked->getCode(),
                        'old_status' => $oldStatus,
                        'new_status' => $locked->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw SubjectException::conflict();
        }
    }

    public function deactivate(Subject $subject, User $actor, string $reasonCode): void
    {
        $this->changeAvailability($subject, $actor, $reasonCode, false);
    }

    public function reactivate(Subject $subject, User $actor, string $reasonCode): void
    {
        $this->changeAvailability($subject, $actor, $reasonCode, true);
    }

    public function deleteUnused(Subject $subject, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $subjectId = $subject->getId();
        $actorId = $actor->getId();
        $rejected = null;

        try {
            $this->entityManager->wrapInTransaction(function () use ($subjectId, $actorId, $reasonCode, &$rejected): void {
                try {
                    $locked = $this->lockSubject($subjectId);
                    $freshActor = $this->lockActor($actorId, CurriculumOperation::Archive);
                    if (SubjectStatus::Active !== $locked->getStatus()) {
                        throw SubjectException::invalidTransition();
                    }
                    if ($this->dependencyCount($locked->getId()) > 0) {
                        throw SubjectException::dependent();
                    }
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::SubjectDeleted,
                        actorType: SecurityAuditActorType::User,
                        outcome: SecurityAuditOutcome::Success,
                        actorUser: $freshActor,
                        metadata: [
                            'source' => 'subject_manager',
                            'reason_code' => $reasonCode,
                            'subject_id' => $locked->getId()->toRfc4122(),
                            'code' => $locked->getCode(),
                            'old_status' => $locked->getStatus()->value,
                        ],
                        captureRequestHashes: false,
                    ), false);
                    $this->entityManager->remove($locked);
                    $this->entityManager->flush();
                } catch (SubjectException $exception) {
                    $rejected = $exception;
                }
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw SubjectException::conflict();
        }
        if ($rejected instanceof SubjectException) {
            throw $rejected;
        }
    }

    private function changeAvailability(Subject $subject, User $actor, string $reasonCode, bool $active): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $subjectId = $subject->getId();
        $actorId = $actor->getId();
        $rejected = null;

        try {
            $this->entityManager->wrapInTransaction(function () use ($subjectId, $actorId, $reasonCode, $active, &$rejected): void {
                try {
                    $locked = $this->lockSubject($subjectId);
                    $freshActor = $this->lockActor($actorId, CurriculumOperation::Availability);
                    $oldStatus = $locked->getStatus()->value;
                    $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                    if ($active) {
                        $locked->reactivate($now);
                    } else {
                        $locked->deactivate($now);
                    }
                    $this->subjects->save($locked, false);
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::SubjectUpdated,
                        actorType: SecurityAuditActorType::User,
                        outcome: SecurityAuditOutcome::Success,
                        actorUser: $freshActor,
                        metadata: [
                            'source' => 'subject_manager',
                            'reason_code' => $reasonCode,
                            'subject_id' => $locked->getId()->toRfc4122(),
                            'code' => $locked->getCode(),
                            'old_status' => $oldStatus,
                            'new_status' => $locked->getStatus()->value,
                        ],
                        captureRequestHashes: false,
                    ), false);
                    $this->entityManager->flush();
                } catch (SubjectException $exception) {
                    $rejected = $exception;
                }
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw SubjectException::conflict();
        }
        if ($rejected instanceof SubjectException) {
            throw $rejected;
        }
    }

    private function dependencyCount(Uuid $subjectId): int
    {
        $pairs = [
            ['curriculum_programs', 'subject_id'],
            ['questions', 'subject_id'],
            ['learning_contents', 'subject_id'],
            ['classroom_courses', 'subject_id'],
            ['classroom_course_active_guards', 'subject_id'],
            ['teacher_professional_profiles', 'subject_id'],
            ['question_revision_alignments', 'subject_id'],
            ['learning_content_outcome_alignments', 'subject_id'],
            ['assessments', 'subject_id'],
            ['access_package_catalog_grants', 'subject_id'],
            ['catalog_subjects', 'canonical_subject_id'],
        ];
        $total = 0;
        foreach ($pairs as [$table, $column]) {
            $total += (int) $this->entityManager->getConnection()->fetchOne(
                \sprintf('SELECT COUNT(*) FROM %s WHERE %s = :id', $table, $column),
                ['id' => $subjectId->toBinary()],
                ['id' => ParameterType::BINARY],
            );
        }

        return $total;
    }

    private function lockSubject(Uuid $subjectId): Subject
    {
        $locked = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof Subject) {
            throw SubjectException::notFound();
        }

        return $locked;
    }

    private function lockActor(Uuid $actorId, CurriculumOperation $operation = CurriculumOperation::Manage): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw SubjectException::userNotFound();
        }
        if (!$this->actorPolicy->allowsFresh($freshActor, $operation)) {
            throw SubjectException::unauthorized();
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
            throw SubjectException::invalidInput($e->getMessage());
        }
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (1 !== preg_match('/^[a-z0-9_]+$/', $code) || \strlen($code) < 2 || \strlen($code) > 64) {
            throw SubjectException::invalidInput('Subject code must be lowercase snake_case [a-z0-9_]{2,64}.');
        }

        return $code;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw SubjectException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
