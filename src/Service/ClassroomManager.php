<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\StudentEnrollmentStatus;
use App\Exception\ClassroomException;
use App\Exception\InstitutionOperationException;
use App\Repository\ClassroomRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Classroom mutations. Year must not be closed; institution must be active.
 *
 * Lock order: Institution → AcademicYear → Classroom → Users → Membership.
 */
final class ClassroomManager
{
    public function __construct(
        private readonly ClassroomRepository $classrooms,
        private readonly InstitutionNameNormalizer $nameNormalizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        AcademicYear $year,
        User $actor,
        string $name,
        GradeLevel $gradeLevel,
        string $reasonCode,
        ?string $sectionCode = null,
        ?int $capacity = null,
    ): Classroom {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $names = $this->normalizeName($name);
        $sectionCode = $this->normalizeSectionCode($sectionCode);
        Classroom::assertValidCapacity($capacity);

        $yearId = $year->getId();
        $institutionId = $year->getInstitution()->getId();
        $actorId = $actor->getId();

        try {
            $classroom = $this->entityManager->wrapInTransaction(function () use (
                $yearId,
                $institutionId,
                $actorId,
                $names,
                $gradeLevel,
                $sectionCode,
                $capacity,
                $reasonCode,
            ): Classroom {
                $lockedInstitution = $this->lockInstitution($institutionId);
                $this->assertInstitutionActive($lockedInstitution);

                $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedYear instanceof AcademicYear) {
                    throw ClassroomException::notFound();
                }
                if (!$lockedYear->getInstitution()->getId()->equals($lockedInstitution->getId())) {
                    throw ClassroomException::crossInstitution();
                }
                if (AcademicYearStatus::Closed === $lockedYear->getStatus()) {
                    throw ClassroomException::yearNotOperable();
                }

                $freshActor = $this->lockActor($actorId);
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                if ($this->classrooms->existsWithNormalizedName($lockedYear, $names['normalizedName'])) {
                    throw ClassroomException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $classroom = Classroom::create(
                    $lockedInstitution,
                    $lockedYear,
                    $names['name'],
                    $names['normalizedName'],
                    $gradeLevel,
                    $sectionCode,
                    $capacity,
                    $now,
                );
                $this->classrooms->save($classroom, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ClassroomCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'classroom_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                        'classroom_id' => $classroom->getId()->toRfc4122(),
                        'grade_level' => $gradeLevel->value,
                        'new_status' => $classroom->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $classroom;
            });
        } catch (UniqueConstraintViolationException) {
            throw ClassroomException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomException::conflict();
        }

        $this->authCache->invalidateClassroom($classroom->getId());
        $this->authCache->invalidateInstitution($institutionId);

        return $classroom;
    }

    public function rename(Classroom $classroom, User $actor, string $name, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $names = $this->normalizeName($name);

        $this->mutate($classroom, $actor, $reasonCode, function (
            Institution $lockedInstitution,
            AcademicYear $lockedYear,
            Classroom $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($names): void {
            if (ClassroomStatus::Archived === $locked->getStatus()) {
                throw ClassroomException::classroomNotOperable();
            }
            if (AcademicYearStatus::Closed === $lockedYear->getStatus()) {
                throw ClassroomException::yearNotOperable();
            }
            if ($this->classrooms->existsWithNormalizedName($lockedYear, $names['normalizedName'])
                && $locked->getNormalizedName() !== $names['normalizedName']) {
                throw ClassroomException::conflict();
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->rename($names['name'], $names['normalizedName'], $now);
            $this->classrooms->save($locked, false);

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ClassroomUpdated,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $freshActor,
                metadata: [
                    'source' => 'classroom_manager',
                    'reason_code' => $reasonCode,
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                    'classroom_id' => $locked->getId()->toRfc4122(),
                ],
                captureRequestHashes: false,
            ), false);
        });
    }

    public function changeCapacity(Classroom $classroom, User $actor, ?int $capacity, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        Classroom::assertValidCapacity($capacity);

        $this->mutate($classroom, $actor, $reasonCode, function (
            Institution $lockedInstitution,
            AcademicYear $lockedYear,
            Classroom $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($capacity): void {
            if (ClassroomStatus::Archived === $locked->getStatus()) {
                throw ClassroomException::classroomNotOperable();
            }
            if (AcademicYearStatus::Closed === $lockedYear->getStatus()) {
                throw ClassroomException::yearNotOperable();
            }

            $activeCount = $this->classrooms->countActiveEnrollments($locked);
            $this->assertEnrollmentGuardConsistencyForClassroom($locked, $activeCount);
            if (null !== $capacity && $capacity < $activeCount) {
                throw ClassroomException::capacityBelowEnrollment();
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->changeCapacity($capacity, $now);
            $this->classrooms->save($locked, false);

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ClassroomUpdated,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $freshActor,
                metadata: [
                    'source' => 'classroom_manager',
                    'reason_code' => $reasonCode,
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                    'classroom_id' => $locked->getId()->toRfc4122(),
                ],
                captureRequestHashes: false,
            ), false);
        });
    }

    public function archive(Classroom $classroom, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        $this->mutate($classroom, $actor, $reasonCode, function (
            Institution $lockedInstitution,
            AcademicYear $lockedYear,
            Classroom $locked,
            User $freshActor,
            string $reasonCode,
        ): void {
            unset($lockedYear);
            $oldStatus = $locked->getStatus()->value;
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->archive($now);
            $this->classrooms->save($locked, false);

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ClassroomArchived,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $freshActor,
                metadata: [
                    'source' => 'classroom_manager',
                    'reason_code' => $reasonCode,
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'classroom_id' => $locked->getId()->toRfc4122(),
                    'old_status' => $oldStatus,
                    'new_status' => $locked->getStatus()->value,
                ],
                captureRequestHashes: false,
            ), false);
        });
    }

    /**
     * @param callable(Institution, AcademicYear, Classroom, User, string): void $mutator
     */
    private function mutate(Classroom $classroom, User $actor, string $reasonCode, callable $mutator): void
    {
        $classroomId = $classroom->getId();
        $institutionId = $classroom->getInstitution()->getId();
        $yearId = $classroom->getAcademicYear()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $classroomId,
                $institutionId,
                $yearId,
                $actorId,
                $reasonCode,
                $mutator,
            ): void {
                $lockedInstitution = $this->lockInstitution($institutionId);
                $this->assertInstitutionActive($lockedInstitution);

                $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedYear instanceof AcademicYear) {
                    throw ClassroomException::notFound();
                }

                $locked = $this->freshEntities->findFreshLockedClassroom($classroomId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof Classroom) {
                    throw ClassroomException::notFound();
                }
                if (!$locked->getInstitution()->getId()->equals($lockedInstitution->getId())
                    || !$locked->getAcademicYear()->getId()->equals($lockedYear->getId())) {
                    throw ClassroomException::crossInstitution();
                }

                $freshActor = $this->lockActor($actorId);
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $mutator($lockedInstitution, $lockedYear, $locked, $freshActor, $reasonCode);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw ClassroomException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomException::conflict();
        }

        $this->authCache->invalidateClassroom($classroomId);
        $this->authCache->invalidateInstitution($institutionId);
    }

    private function lockInstitution(Uuid $institutionId): Institution
    {
        $locked = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof Institution) {
            throw ClassroomException::notFound();
        }

        return $locked;
    }

    private function lockActor(Uuid $actorId): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw ClassroomException::userNotFound();
        }

        return $freshActor;
    }

    private function assertInstitutionActive(Institution $institution): void
    {
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw ClassroomException::institutionNotOperable();
        }
    }

    private function assertActorMayManage(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw ClassroomException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        $actorMembership = $this->freshEntities->findFreshMembershipForUser(
            $actor->getId(),
            $institution->getId(),
        );
        if (!$actorMembership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $actorMembership->getStatus()) {
            throw ClassroomException::unauthorized();
        }

        $role = $actorMembership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw ClassroomException::unauthorized();
        }
    }

    /**
     * @return array{name: string, normalizedName: string}
     */
    private function normalizeName(string $name): array
    {
        try {
            $names = $this->nameNormalizer->normalize($name);
        } catch (InstitutionOperationException $e) {
            throw ClassroomException::invalidInput($e->getMessage());
        }

        return [
            'name' => $names['name'],
            'normalizedName' => $names['normalizedName'],
        ];
    }

    private function normalizeSectionCode(?string $sectionCode): ?string
    {
        if (null === $sectionCode) {
            return null;
        }
        $sectionCode = trim($sectionCode);
        if ('' === $sectionCode) {
            return null;
        }
        if (mb_strlen($sectionCode) > 32) {
            throw ClassroomException::invalidInput('section_code must be at most 32 characters.');
        }

        return $sectionCode;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw ClassroomException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }

    /**
     * Active enrollments in this classroom must match enrollment guards that point at them.
     * Mismatch is a typed conflict (do not silently continue).
     */
    private function assertEnrollmentGuardConsistencyForClassroom(Classroom $classroom, int $activeCount): void
    {
        $connection = $this->entityManager->getConnection();
        $classroomId = $classroom->getId()->toBinary();
        $active = StudentEnrollmentStatus::Active->value;

        $guardCount = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM academic_year_student_enrollment_guards g
             INNER JOIN classroom_student_enrollments e ON e.id = g.enrollment_id
             WHERE e.classroom_id = ?',
            [$classroomId],
            [ParameterType::BINARY],
        );

        $activeWithoutGuard = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM classroom_student_enrollments e
             LEFT JOIN academic_year_student_enrollment_guards g ON g.enrollment_id = e.id
             WHERE e.classroom_id = ?
               AND e.status = ?
               AND g.enrollment_id IS NULL',
            [$classroomId, $active],
            [ParameterType::BINARY, ParameterType::STRING],
        );

        $guardNotActive = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM academic_year_student_enrollment_guards g
             INNER JOIN classroom_student_enrollments e ON e.id = g.enrollment_id
             WHERE e.classroom_id = ?
               AND e.status <> ?',
            [$classroomId, $active],
            [ParameterType::BINARY, ParameterType::STRING],
        );

        if ($guardCount !== $activeCount || $activeWithoutGuard > 0 || $guardNotActive > 0) {
            throw ClassroomException::conflict();
        }
    }
}
