<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AcademicYear;
use App\Entity\AcademicYearStudentEnrollmentGuard;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\StudentEnrollmentStatus;
use App\Exception\ClassroomStudentEnrollmentException;
use App\Repository\AcademicYearStudentEnrollmentGuardRepository;
use App\Repository\ClassroomRepository;
use App\Repository\ClassroomStudentEnrollmentRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Student classroom enrollments. Transfer ends old row and creates a new enrollment (history preserved).
 * No reactivate — re-enroll after end when no active guard remains.
 *
 * Lock order: Institution → AcademicYear → Classroom → Users → Membership → Enrollment/Guards.
 */
final class ClassroomStudentEnrollmentManager
{
    public function __construct(
        private readonly ClassroomStudentEnrollmentRepository $enrollments,
        private readonly AcademicYearStudentEnrollmentGuardRepository $enrollmentGuards,
        private readonly ClassroomRepository $classrooms,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function enroll(
        Classroom $classroom,
        User $actor,
        InstitutionMembership $studentMembership,
        string $reasonCode,
    ): ClassroomStudentEnrollment {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $classroomId = $classroom->getId();
        $institutionId = $classroom->getInstitution()->getId();
        $yearId = $classroom->getAcademicYear()->getId();
        $membershipId = $studentMembership->getId();
        $actorId = $actor->getId();
        $subjectUserId = $studentMembership->getUser()->getId();

        try {
            $enrollment = $this->entityManager->wrapInTransaction(function () use (
                $classroomId,
                $institutionId,
                $yearId,
                $membershipId,
                $actorId,
                $subjectUserId,
                $reasonCode,
            ): ClassroomStudentEnrollment {
                [$lockedInstitution, $lockedYear, $lockedClassroom] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                );
                $this->assertOperable($lockedInstitution, $lockedYear, $lockedClassroom);

                $users = $this->freshEntities->findFreshLockedUsers([$actorId, $subjectUserId]);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw ClassroomStudentEnrollmentException::userNotFound();
                }
                $freshSubject = $users[$subjectUserId->toRfc4122()] ?? null;
                if (!$freshSubject instanceof User) {
                    throw ClassroomStudentEnrollmentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $lockedMembership = $this->freshEntities->findFreshLockedMembership($membershipId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedMembership instanceof InstitutionMembership) {
                    throw ClassroomStudentEnrollmentException::notFound();
                }
                $this->assertEligibleStudent($lockedMembership, $lockedInstitution, $freshSubject);

                if ($this->enrollmentGuardExists($lockedYear->getId(), $lockedMembership->getId())) {
                    throw ClassroomStudentEnrollmentException::conflict();
                }

                $this->assertCapacityAvailable($lockedClassroom);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $enrollment = ClassroomStudentEnrollment::enroll(
                    $lockedClassroom,
                    $lockedYear,
                    $lockedMembership,
                    $now,
                );
                $this->enrollments->save($enrollment, false);
                $this->enrollmentGuards->save(
                    AcademicYearStudentEnrollmentGuard::bind($lockedYear, $lockedMembership, $enrollment),
                    false,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ClassroomStudentEnrolled,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $freshSubject,
                    metadata: [
                        'source' => 'classroom_student_enrollment_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                        'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                        'membership_id' => $lockedMembership->getId()->toRfc4122(),
                        'new_status' => $enrollment->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $enrollment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ClassroomStudentEnrollmentException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomStudentEnrollmentException::conflict();
        }

        $this->authCache->invalidateClassroom($classroomId);
        $this->authCache->invalidateStudentEnrollment($subjectUserId, $classroomId);

        return $enrollment;
    }

    public function transfer(
        ClassroomStudentEnrollment $enrollment,
        User $actor,
        Classroom $targetClassroom,
        string $reasonCode,
    ): ClassroomStudentEnrollment {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $enrollmentId = $enrollment->getId();
        $sourceClassroomId = $enrollment->getClassroom()->getId();
        $targetClassroomId = $targetClassroom->getId();
        $institutionId = $enrollment->getClassroom()->getInstitution()->getId();
        $yearId = $enrollment->getAcademicYear()->getId();
        $membershipId = $enrollment->getStudentMembership()->getId();
        $actorId = $actor->getId();
        $subjectUserId = $enrollment->getStudentMembership()->getUser()->getId();

        try {
            $newEnrollment = $this->entityManager->wrapInTransaction(function () use (
                $enrollmentId,
                $sourceClassroomId,
                $targetClassroomId,
                $institutionId,
                $yearId,
                $membershipId,
                $actorId,
                $subjectUserId,
                $reasonCode,
            ): ClassroomStudentEnrollment {
                if ($sourceClassroomId->equals($targetClassroomId)) {
                    throw ClassroomStudentEnrollmentException::invalidInput('Source and target classroom must differ.');
                }

                $lockedInstitution = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedInstitution instanceof Institution) {
                    throw ClassroomStudentEnrollmentException::notFound();
                }
                $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedYear instanceof AcademicYear) {
                    throw ClassroomStudentEnrollmentException::notFound();
                }

                // Lock classrooms by UUID ascending to avoid deadlocks.
                $firstId = ($sourceClassroomId->toRfc4122() <=> $targetClassroomId->toRfc4122()) <= 0
                    ? $sourceClassroomId
                    : $targetClassroomId;
                $secondId = ($sourceClassroomId->toRfc4122() <=> $targetClassroomId->toRfc4122()) <= 0
                    ? $targetClassroomId
                    : $sourceClassroomId;
                $first = $this->freshEntities->findFreshLockedClassroom($firstId, LockMode::PESSIMISTIC_WRITE);
                $second = $this->freshEntities->findFreshLockedClassroom($secondId, LockMode::PESSIMISTIC_WRITE);
                if (!$first instanceof Classroom || !$second instanceof Classroom) {
                    throw ClassroomStudentEnrollmentException::notFound();
                }
                $source = $sourceClassroomId->equals($first->getId()) ? $first : $second;
                $target = $targetClassroomId->equals($first->getId()) ? $first : $second;

                if (!$source->getInstitution()->getId()->equals($lockedInstitution->getId())
                    || !$target->getInstitution()->getId()->equals($lockedInstitution->getId())
                    || !$source->getAcademicYear()->getId()->equals($lockedYear->getId())
                    || !$target->getAcademicYear()->getId()->equals($lockedYear->getId())) {
                    throw ClassroomStudentEnrollmentException::crossInstitution();
                }

                $this->assertOperable($lockedInstitution, $lockedYear, $target);
                if (ClassroomStatus::Active !== $source->getStatus()) {
                    throw ClassroomStudentEnrollmentException::classroomNotOperable();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId, $subjectUserId]);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw ClassroomStudentEnrollmentException::userNotFound();
                }
                $freshSubject = $users[$subjectUserId->toRfc4122()] ?? null;
                if (!$freshSubject instanceof User) {
                    throw ClassroomStudentEnrollmentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $lockedMembership = $this->freshEntities->findFreshLockedMembership($membershipId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedMembership instanceof InstitutionMembership) {
                    throw ClassroomStudentEnrollmentException::notFound();
                }
                $this->assertEligibleStudent($lockedMembership, $lockedInstitution, $freshSubject);

                $lockedEnrollment = $this->freshEntities->findFreshLockedStudentEnrollment($enrollmentId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedEnrollment instanceof ClassroomStudentEnrollment) {
                    throw ClassroomStudentEnrollmentException::notFound();
                }
                if (!$lockedEnrollment->getClassroom()->getId()->equals($source->getId())
                    || StudentEnrollmentStatus::Active !== $lockedEnrollment->getStatus()) {
                    throw ClassroomStudentEnrollmentException::invalidTransition();
                }

                $guard = $this->enrollmentGuards->find([
                    'academicYear' => $lockedYear->getId(),
                    'studentMembership' => $lockedMembership->getId(),
                ]);
                if (!$guard instanceof AcademicYearStudentEnrollmentGuard
                    || !$guard->getEnrollment()->getId()->equals($lockedEnrollment->getId())) {
                    throw ClassroomStudentEnrollmentException::conflict();
                }

                $this->assertCapacityAvailable($target);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lockedEnrollment->markTransferred($now);
                $this->enrollments->save($lockedEnrollment, false);

                $newEnrollment = ClassroomStudentEnrollment::enroll($target, $lockedYear, $lockedMembership, $now);
                $this->enrollments->save($newEnrollment, false);
                $guard->swapTo($newEnrollment);
                $this->enrollmentGuards->save($guard, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ClassroomStudentTransferred,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $freshSubject,
                    metadata: [
                        'source' => 'classroom_student_enrollment_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                        'membership_id' => $lockedMembership->getId()->toRfc4122(),
                        'source_classroom_id' => $source->getId()->toRfc4122(),
                        'target_classroom_id' => $target->getId()->toRfc4122(),
                        'old_status' => StudentEnrollmentStatus::Active->value,
                        'new_status' => $newEnrollment->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $newEnrollment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ClassroomStudentEnrollmentException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomStudentEnrollmentException::conflict();
        }

        $this->authCache->invalidateClassroom($sourceClassroomId);
        $this->authCache->invalidateClassroom($targetClassroomId);
        $this->authCache->invalidateStudentEnrollment($subjectUserId, $sourceClassroomId);
        $this->authCache->invalidateStudentEnrollment($subjectUserId, $targetClassroomId);

        return $newEnrollment;
    }

    public function endEnrollment(ClassroomStudentEnrollment $enrollment, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $enrollmentId = $enrollment->getId();
        $classroomId = $enrollment->getClassroom()->getId();
        $institutionId = $enrollment->getClassroom()->getInstitution()->getId();
        $yearId = $enrollment->getAcademicYear()->getId();
        $membershipId = $enrollment->getStudentMembership()->getId();
        $actorId = $actor->getId();
        $subjectUserId = $enrollment->getStudentMembership()->getUser()->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $enrollmentId,
                $classroomId,
                $institutionId,
                $yearId,
                $membershipId,
                $actorId,
                $subjectUserId,
                $reasonCode,
            ): void {
                [$lockedInstitution, $lockedYear, $lockedClassroom] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                );
                if (InstitutionStatus::Active !== $lockedInstitution->getStatus()) {
                    throw ClassroomStudentEnrollmentException::institutionNotOperable();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId, $subjectUserId]);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw ClassroomStudentEnrollmentException::userNotFound();
                }
                $freshSubject = $users[$subjectUserId->toRfc4122()] ?? null;
                if (!$freshSubject instanceof User) {
                    throw ClassroomStudentEnrollmentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $lockedEnrollment = $this->freshEntities->findFreshLockedStudentEnrollment($enrollmentId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedEnrollment instanceof ClassroomStudentEnrollment) {
                    throw ClassroomStudentEnrollmentException::notFound();
                }
                if (!$lockedEnrollment->getClassroom()->getId()->equals($lockedClassroom->getId())) {
                    throw ClassroomStudentEnrollmentException::crossInstitution();
                }
                if (StudentEnrollmentStatus::Ended === $lockedEnrollment->getStatus()) {
                    throw ClassroomStudentEnrollmentException::invalidTransition();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $oldStatus = $lockedEnrollment->getStatus()->value;
                $lockedEnrollment->end($now);
                $this->enrollments->save($lockedEnrollment, false);

                $guard = $this->enrollmentGuards->find([
                    'academicYear' => $lockedYear->getId(),
                    'studentMembership' => $membershipId,
                ]);
                if ($guard instanceof AcademicYearStudentEnrollmentGuard
                    && $guard->getEnrollment()->getId()->equals($lockedEnrollment->getId())) {
                    $this->enrollmentGuards->remove($guard, false);
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ClassroomStudentEnrollmentEnded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $freshSubject,
                    metadata: [
                        'source' => 'classroom_student_enrollment_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                        'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                        'membership_id' => $membershipId->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $lockedEnrollment->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomStudentEnrollmentException::conflict();
        }

        $this->authCache->invalidateClassroom($classroomId);
        $this->authCache->invalidateStudentEnrollment($subjectUserId, $classroomId);
    }

    /**
     * @return array{0: Institution, 1: AcademicYear, 2: Classroom}
     */
    private function lockHierarchy(Uuid $institutionId, Uuid $yearId, Uuid $classroomId): array
    {
        $lockedInstitution = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedInstitution instanceof Institution) {
            throw ClassroomStudentEnrollmentException::notFound();
        }
        $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedYear instanceof AcademicYear) {
            throw ClassroomStudentEnrollmentException::notFound();
        }
        $lockedClassroom = $this->freshEntities->findFreshLockedClassroom($classroomId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedClassroom instanceof Classroom) {
            throw ClassroomStudentEnrollmentException::notFound();
        }
        if (!$lockedClassroom->getInstitution()->getId()->equals($lockedInstitution->getId())
            || !$lockedClassroom->getAcademicYear()->getId()->equals($lockedYear->getId())) {
            throw ClassroomStudentEnrollmentException::crossInstitution();
        }

        return [$lockedInstitution, $lockedYear, $lockedClassroom];
    }

    private function assertOperable(Institution $institution, AcademicYear $year, Classroom $classroom): void
    {
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw ClassroomStudentEnrollmentException::institutionNotOperable();
        }
        if (AcademicYearStatus::Closed === $year->getStatus()) {
            throw ClassroomStudentEnrollmentException::yearNotOperable();
        }
        if (ClassroomStatus::Active !== $classroom->getStatus()) {
            throw ClassroomStudentEnrollmentException::classroomNotOperable();
        }
    }

    private function assertCapacityAvailable(Classroom $classroom): void
    {
        $capacity = $classroom->getCapacity();
        if (null === $capacity) {
            return;
        }
        $activeCount = $this->classrooms->countActiveEnrollments($classroom);
        if ($activeCount >= $capacity) {
            throw ClassroomStudentEnrollmentException::capacityExceeded();
        }
    }

    private function assertEligibleStudent(
        InstitutionMembership $membership,
        Institution $institution,
        User $freshSubject,
    ): void {
        if (!$membership->getInstitution()->getId()->equals($institution->getId())) {
            throw ClassroomStudentEnrollmentException::crossInstitution();
        }
        if (InstitutionMembershipStatus::Active !== $membership->getStatus()
            || InstitutionMembershipRole::Student !== $membership->getRole()) {
            throw ClassroomStudentEnrollmentException::membershipNotEligible();
        }
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshSubject)) {
            throw ClassroomStudentEnrollmentException::membershipNotEligible();
        }
    }

    private function assertActorMayManage(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw ClassroomStudentEnrollmentException::unauthorized();
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
            throw ClassroomStudentEnrollmentException::unauthorized();
        }

        $role = $actorMembership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw ClassroomStudentEnrollmentException::unauthorized();
        }
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw ClassroomStudentEnrollmentException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }

    private function enrollmentGuardExists(Uuid $academicYearId, Uuid $studentMembershipId): bool
    {
        $found = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM academic_year_student_enrollment_guards WHERE academic_year_id = ? AND student_membership_id = ?',
            [$academicYearId->toBinary(), $studentMembershipId->toBinary()],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY],
        );

        return false !== $found && null !== $found;
    }
}
