<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\ClassroomHomeroomGuard;
use App\Entity\ClassroomTeacherActiveGuard;
use App\Entity\ClassroomTeacherAssignment;
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
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Exception\ClassroomTeacherAssignmentException;
use App\Repository\ClassroomHomeroomGuardRepository;
use App\Repository\ClassroomTeacherActiveGuardRepository;
use App\Repository\ClassroomTeacherAssignmentRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher classroom assignments. No reactivate — end then assign again.
 *
 * Lock order: Institution → AcademicYear → Classroom → Users → Membership → Assignment/Guards.
 */
final class ClassroomTeacherAssignmentManager
{
    public function __construct(
        private readonly ClassroomTeacherAssignmentRepository $assignments,
        private readonly ClassroomHomeroomGuardRepository $homeroomGuards,
        private readonly ClassroomTeacherActiveGuardRepository $activeGuards,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function assign(
        Classroom $classroom,
        User $actor,
        InstitutionMembership $teacherMembership,
        TeacherAssignmentRole $role,
        string $reasonCode,
    ): ClassroomTeacherAssignment {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $classroomId = $classroom->getId();
        $institutionId = $classroom->getInstitution()->getId();
        $yearId = $classroom->getAcademicYear()->getId();
        $membershipId = $teacherMembership->getId();
        $actorId = $actor->getId();
        $subjectUserId = $teacherMembership->getUser()->getId();

        try {
            $assignment = $this->entityManager->wrapInTransaction(function () use (
                $classroomId,
                $institutionId,
                $yearId,
                $membershipId,
                $actorId,
                $subjectUserId,
                $role,
                $reasonCode,
            ): ClassroomTeacherAssignment {
                [$lockedInstitution, $lockedYear, $lockedClassroom] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                );
                $this->assertOperable($lockedInstitution, $lockedYear, $lockedClassroom);

                $users = $this->freshEntities->findFreshLockedUsers([$actorId, $subjectUserId]);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw ClassroomTeacherAssignmentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $freshSubject = $users[$subjectUserId->toRfc4122()] ?? null;
                if (!$freshSubject instanceof User) {
                    throw ClassroomTeacherAssignmentException::userNotFound();
                }

                $lockedMembership = $this->freshEntities->findFreshLockedMembership($membershipId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedMembership instanceof InstitutionMembership) {
                    throw ClassroomTeacherAssignmentException::notFound();
                }
                $this->assertEligibleTeacher($lockedMembership, $lockedInstitution, $freshSubject);

                if ($this->teacherActiveGuardExists($lockedClassroom->getId(), $lockedMembership->getId())) {
                    throw ClassroomTeacherAssignmentException::conflict();
                }

                if (TeacherAssignmentRole::HomeroomTeacher === $role
                    && $this->homeroomGuardExists($lockedClassroom->getId())) {
                    throw ClassroomTeacherAssignmentException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $assignment = ClassroomTeacherAssignment::assign($lockedClassroom, $lockedMembership, $role, $now);
                $this->assignments->save($assignment, false);

                $this->activeGuards->save(
                    ClassroomTeacherActiveGuard::bind($lockedClassroom, $lockedMembership, $assignment),
                    false,
                );
                if (TeacherAssignmentRole::HomeroomTeacher === $role) {
                    $this->homeroomGuards->save(
                        ClassroomHomeroomGuard::bind($lockedClassroom, $assignment),
                        false,
                    );
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ClassroomTeacherAssigned,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $lockedMembership->getUser(),
                    metadata: [
                        'source' => 'classroom_teacher_assignment_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                        'membership_id' => $lockedMembership->getId()->toRfc4122(),
                        'new_role' => $role->value,
                        'new_status' => $assignment->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ClassroomTeacherAssignmentException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomTeacherAssignmentException::conflict();
        }

        $this->authCache->invalidateClassroom($classroomId);
        $this->authCache->invalidateTeacherAssignment($subjectUserId, $classroomId);

        return $assignment;
    }

    public function changeRole(
        ClassroomTeacherAssignment $assignment,
        User $actor,
        TeacherAssignmentRole $newRole,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $assignmentId = $assignment->getId();
        $classroomId = $assignment->getClassroom()->getId();
        $institutionId = $assignment->getClassroom()->getInstitution()->getId();
        $yearId = $assignment->getClassroom()->getAcademicYear()->getId();
        $actorId = $actor->getId();
        $subjectUserId = $assignment->getTeacherMembership()->getUser()->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $assignmentId,
                $classroomId,
                $institutionId,
                $yearId,
                $actorId,
                $newRole,
                $reasonCode,
            ): void {
                [$lockedInstitution, $lockedYear, $lockedClassroom] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                );
                $this->assertOperable($lockedInstitution, $lockedYear, $lockedClassroom);

                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw ClassroomTeacherAssignmentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $locked = $this->freshEntities->findFreshLockedTeacherAssignment($assignmentId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof ClassroomTeacherAssignment) {
                    throw ClassroomTeacherAssignmentException::notFound();
                }
                if (!$locked->getClassroom()->getId()->equals($lockedClassroom->getId())) {
                    throw ClassroomTeacherAssignmentException::crossInstitution();
                }
                if (TeacherAssignmentStatus::Active !== $locked->getStatus()) {
                    throw ClassroomTeacherAssignmentException::invalidTransition();
                }

                $oldRole = $locked->getRole();
                if ($oldRole === $newRole) {
                    return;
                }

                $homeroomGuard = $this->homeroomGuards->find($lockedClassroom->getId());

                if (TeacherAssignmentRole::HomeroomTeacher === $newRole) {
                    if ($homeroomGuard instanceof ClassroomHomeroomGuard
                        && !$homeroomGuard->getAssignment()->getId()->equals($locked->getId())) {
                        throw ClassroomTeacherAssignmentException::conflict();
                    }
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $locked->changeRole($newRole, $now);
                $this->assignments->save($locked, false);

                if (TeacherAssignmentRole::HomeroomTeacher === $oldRole
                    && TeacherAssignmentRole::HomeroomTeacher !== $newRole
                    && $homeroomGuard instanceof ClassroomHomeroomGuard
                    && $homeroomGuard->getAssignment()->getId()->equals($locked->getId())) {
                    $this->homeroomGuards->remove($homeroomGuard, false);
                }

                if (TeacherAssignmentRole::HomeroomTeacher === $newRole) {
                    if ($homeroomGuard instanceof ClassroomHomeroomGuard) {
                        $homeroomGuard->swapTo($locked);
                        $this->homeroomGuards->save($homeroomGuard, false);
                    } else {
                        $this->homeroomGuards->save(ClassroomHomeroomGuard::bind($lockedClassroom, $locked), false);
                    }
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ClassroomTeacherRoleChanged,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $locked->getTeacherMembership()->getUser(),
                    metadata: [
                        'source' => 'classroom_teacher_assignment_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                        'membership_id' => $locked->getTeacherMembership()->getId()->toRfc4122(),
                        'old_role' => $oldRole->value,
                        'new_role' => $newRole->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw ClassroomTeacherAssignmentException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomTeacherAssignmentException::conflict();
        }

        $this->authCache->invalidateClassroom($classroomId);
        $this->authCache->invalidateTeacherAssignment($subjectUserId, $classroomId);
    }

    public function endAssignment(ClassroomTeacherAssignment $assignment, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $assignmentId = $assignment->getId();
        $classroomId = $assignment->getClassroom()->getId();
        $institutionId = $assignment->getClassroom()->getInstitution()->getId();
        $yearId = $assignment->getClassroom()->getAcademicYear()->getId();
        $actorId = $actor->getId();
        $subjectUserId = $assignment->getTeacherMembership()->getUser()->getId();
        $membershipId = $assignment->getTeacherMembership()->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $assignmentId,
                $classroomId,
                $institutionId,
                $yearId,
                $actorId,
                $membershipId,
                $reasonCode,
            ): void {
                [$lockedInstitution, $lockedYear, $lockedClassroom] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                );
                // Ending is allowed on archived classroom / closed year for cleanup by authorized actors.
                if (InstitutionStatus::Active !== $lockedInstitution->getStatus()) {
                    throw ClassroomTeacherAssignmentException::institutionNotOperable();
                }
                unset($lockedYear);

                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw ClassroomTeacherAssignmentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $locked = $this->freshEntities->findFreshLockedTeacherAssignment($assignmentId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof ClassroomTeacherAssignment) {
                    throw ClassroomTeacherAssignmentException::notFound();
                }
                if (!$locked->getClassroom()->getId()->equals($lockedClassroom->getId())) {
                    throw ClassroomTeacherAssignmentException::crossInstitution();
                }
                if (TeacherAssignmentStatus::Ended === $locked->getStatus()) {
                    throw ClassroomTeacherAssignmentException::invalidTransition();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $oldStatus = $locked->getStatus()->value;
                $locked->end($now);
                $this->assignments->save($locked, false);

                $activeGuard = $this->activeGuards->find([
                    'classroom' => $lockedClassroom->getId(),
                    'teacherMembership' => $membershipId,
                ]);
                if ($activeGuard instanceof ClassroomTeacherActiveGuard
                    && $activeGuard->getAssignment()->getId()->equals($locked->getId())) {
                    $this->activeGuards->remove($activeGuard, false);
                }

                $homeroomGuard = $this->homeroomGuards->find($lockedClassroom->getId());
                if ($homeroomGuard instanceof ClassroomHomeroomGuard
                    && $homeroomGuard->getAssignment()->getId()->equals($locked->getId())) {
                    $this->homeroomGuards->remove($homeroomGuard, false);
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ClassroomTeacherAssignmentEnded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $locked->getTeacherMembership()->getUser(),
                    metadata: [
                        'source' => 'classroom_teacher_assignment_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                        'membership_id' => $locked->getTeacherMembership()->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $locked->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomTeacherAssignmentException::conflict();
        }

        $this->authCache->invalidateClassroom($classroomId);
        $this->authCache->invalidateTeacherAssignment($subjectUserId, $classroomId);
    }

    /**
     * @return array{0: Institution, 1: AcademicYear, 2: Classroom}
     */
    private function lockHierarchy(Uuid $institutionId, Uuid $yearId, Uuid $classroomId): array
    {
        $lockedInstitution = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedInstitution instanceof Institution) {
            throw ClassroomTeacherAssignmentException::notFound();
        }
        $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedYear instanceof AcademicYear) {
            throw ClassroomTeacherAssignmentException::notFound();
        }
        $lockedClassroom = $this->freshEntities->findFreshLockedClassroom($classroomId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedClassroom instanceof Classroom) {
            throw ClassroomTeacherAssignmentException::notFound();
        }
        if (!$lockedClassroom->getInstitution()->getId()->equals($lockedInstitution->getId())
            || !$lockedClassroom->getAcademicYear()->getId()->equals($lockedYear->getId())) {
            throw ClassroomTeacherAssignmentException::crossInstitution();
        }

        return [$lockedInstitution, $lockedYear, $lockedClassroom];
    }

    private function assertOperable(Institution $institution, AcademicYear $year, Classroom $classroom): void
    {
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw ClassroomTeacherAssignmentException::institutionNotOperable();
        }
        if (AcademicYearStatus::Closed === $year->getStatus()) {
            throw ClassroomTeacherAssignmentException::yearNotOperable();
        }
        if (ClassroomStatus::Active !== $classroom->getStatus()) {
            throw ClassroomTeacherAssignmentException::classroomNotOperable();
        }
    }

    private function assertEligibleTeacher(
        InstitutionMembership $membership,
        Institution $institution,
        User $freshSubject,
    ): void {
        if (!$membership->getInstitution()->getId()->equals($institution->getId())) {
            throw ClassroomTeacherAssignmentException::crossInstitution();
        }
        if (InstitutionMembershipStatus::Active !== $membership->getStatus()
            || InstitutionMembershipRole::Teacher !== $membership->getRole()) {
            throw ClassroomTeacherAssignmentException::membershipNotEligible();
        }
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshSubject)) {
            throw ClassroomTeacherAssignmentException::membershipNotEligible();
        }
    }

    private function assertActorMayManage(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw ClassroomTeacherAssignmentException::unauthorized();
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
            throw ClassroomTeacherAssignmentException::unauthorized();
        }

        $role = $actorMembership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw ClassroomTeacherAssignmentException::unauthorized();
        }
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw ClassroomTeacherAssignmentException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }

    private function homeroomGuardExists(Uuid $classroomId): bool
    {
        // Classroom row is already WRITE-locked by the caller; a plain existence check is enough.
        $found = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM classroom_homeroom_guards WHERE classroom_id = ?',
            [$classroomId->toBinary()],
            [\Doctrine\DBAL\ParameterType::BINARY],
        );

        return false !== $found && null !== $found;
    }

    private function teacherActiveGuardExists(Uuid $classroomId, Uuid $teacherMembershipId): bool
    {
        $found = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM classroom_teacher_active_guards WHERE classroom_id = ? AND teacher_membership_id = ?',
            [$classroomId->toBinary(), $teacherMembershipId->toBinary()],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY],
        );

        return false !== $found && null !== $found;
    }
}
