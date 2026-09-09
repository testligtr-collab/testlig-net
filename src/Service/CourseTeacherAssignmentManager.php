<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\ClassroomCourse;
use App\Entity\CourseTeacherActiveGuard;
use App\Entity\CourseTeacherAssignment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomCourseStatus;
use App\Enum\ClassroomStatus;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserStatus;
use App\Exception\CourseTeacherAssignmentException;
use App\Repository\CourseTeacherActiveGuardRepository;
use App\Repository\CourseTeacherAssignmentRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Course teacher assignments. No reactivate — end then assign again.
 *
 * Lock order: Institution → Year → Classroom → Course → Users UUID asc → Membership → Assignment/guard.
 */
final class CourseTeacherAssignmentManager
{
    public function __construct(
        private readonly CourseTeacherAssignmentRepository $assignments,
        private readonly CourseTeacherActiveGuardRepository $activeGuards,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function assign(
        ClassroomCourse $course,
        User $actor,
        InstitutionMembership $teacherMembership,
        string $reasonCode,
    ): CourseTeacherAssignment {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $courseId = $course->getId();
        $classroomId = $course->getClassroom()->getId();
        $institutionId = $course->getInstitution()->getId();
        $yearId = $course->getAcademicYear()->getId();
        $membershipId = $teacherMembership->getId();
        $actorId = $actor->getId();
        $subjectUserId = $teacherMembership->getUser()->getId();

        try {
            $assignment = $this->entityManager->wrapInTransaction(function () use (
                $courseId,
                $classroomId,
                $institutionId,
                $yearId,
                $membershipId,
                $actorId,
                $subjectUserId,
                $reasonCode,
            ): CourseTeacherAssignment {
                [$lockedInstitution, $lockedYear, $lockedClassroom, $lockedCourse] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                    $courseId,
                );
                $this->assertOperable($lockedInstitution, $lockedYear, $lockedClassroom, $lockedCourse);

                $users = $this->freshEntities->findFreshLockedUsers([$actorId, $subjectUserId]);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw CourseTeacherAssignmentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $freshSubject = $users[$subjectUserId->toRfc4122()] ?? null;
                if (!$freshSubject instanceof User) {
                    throw CourseTeacherAssignmentException::userNotFound();
                }

                $lockedMembership = $this->freshEntities->findFreshLockedMembership($membershipId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedMembership instanceof InstitutionMembership) {
                    throw CourseTeacherAssignmentException::notFound();
                }
                $this->assertEligibleTeacher($lockedMembership, $lockedInstitution, $freshSubject);

                if (CurriculumStatus::Retired === $lockedCourse->getCurriculumProgram()->getStatus()) {
                    throw CourseTeacherAssignmentException::curriculumNotPublished();
                }
                if (CurriculumStatus::Published !== $lockedCourse->getCurriculumProgram()->getStatus()) {
                    throw CourseTeacherAssignmentException::curriculumNotPublished();
                }

                if (null !== $this->freshEntities->findFreshCourseTeacherActiveGuard(
                    $lockedCourse->getId(),
                    $lockedMembership->getId(),
                )) {
                    throw CourseTeacherAssignmentException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $assignment = CourseTeacherAssignment::assign($lockedCourse, $lockedMembership, $now);
                $this->assignments->save($assignment, false);
                $this->activeGuards->save(
                    CourseTeacherActiveGuard::bind($lockedCourse, $lockedMembership, $assignment),
                    false,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CourseTeacherAssigned,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $lockedMembership->getUser(),
                    metadata: [
                        'source' => 'course_teacher_assignment_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                        'classroom_course_id' => $lockedCourse->getId()->toRfc4122(),
                        'membership_id' => $lockedMembership->getId()->toRfc4122(),
                        'course_teacher_assignment_id' => $assignment->getId()->toRfc4122(),
                        'new_status' => $assignment->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw CourseTeacherAssignmentException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CourseTeacherAssignmentException::conflict();
        }

        $this->authCache->invalidateClassroomCourse($courseId);
        $this->authCache->invalidateCourseTeacherAssignment($subjectUserId, $courseId);
        $this->authCache->invalidateClassroom($classroomId);

        return $assignment;
    }

    public function endAssignment(
        CourseTeacherAssignment $assignment,
        User $actor,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $assignmentId = $assignment->getId();
        $courseId = $assignment->getClassroomCourse()->getId();
        $classroomId = $assignment->getClassroomCourse()->getClassroom()->getId();
        $institutionId = $assignment->getInstitution()->getId();
        $yearId = $assignment->getClassroomCourse()->getAcademicYear()->getId();
        $actorId = $actor->getId();
        $subjectUserId = $assignment->getTeacherMembership()->getUser()->getId();
        $membershipId = $assignment->getTeacherMembership()->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $assignmentId,
                $courseId,
                $classroomId,
                $institutionId,
                $yearId,
                $actorId,
                $membershipId,
                $reasonCode,
            ): void {
                [$lockedInstitution, $lockedYear, $lockedClassroom, $lockedCourse] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                    $courseId,
                );
                unset($lockedYear, $lockedClassroom);
                $this->assertInstitutionActive($lockedInstitution);

                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw CourseTeacherAssignmentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $locked = $this->freshEntities->findFreshLockedCourseTeacherAssignment(
                    $assignmentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$locked instanceof CourseTeacherAssignment
                    || !$locked->getClassroomCourse()->getId()->equals($lockedCourse->getId())) {
                    throw CourseTeacherAssignmentException::notFound();
                }
                if (CourseTeacherAssignmentStatus::Active !== $locked->getStatus()) {
                    throw CourseTeacherAssignmentException::invalidTransition();
                }

                $oldStatus = $locked->getStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $locked->end($now);
                $this->assignments->save($locked, false);

                $guard = $this->freshEntities->findFreshCourseTeacherActiveGuard($lockedCourse->getId(), $membershipId);
                if ($guard instanceof CourseTeacherActiveGuard
                    && $guard->getAssignment()->getId()->equals($locked->getId())) {
                    $this->activeGuards->remove($guard, false);
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CourseTeacherAssignmentEnded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $locked->getTeacherMembership()->getUser(),
                    metadata: [
                        'source' => 'course_teacher_assignment_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'classroom_course_id' => $lockedCourse->getId()->toRfc4122(),
                        'membership_id' => $membershipId->toRfc4122(),
                        'course_teacher_assignment_id' => $locked->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $locked->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw CourseTeacherAssignmentException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CourseTeacherAssignmentException::conflict();
        }

        $this->authCache->invalidateClassroomCourse($courseId);
        $this->authCache->invalidateCourseTeacherAssignment($subjectUserId, $courseId);
        $this->authCache->invalidateClassroom($classroomId);
    }

    /**
     * @return array{0: Institution, 1: AcademicYear, 2: Classroom, 3: ClassroomCourse}
     */
    private function lockHierarchy(
        Uuid $institutionId,
        Uuid $yearId,
        Uuid $classroomId,
        Uuid $courseId,
    ): array {
        $lockedInstitution = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedInstitution instanceof Institution) {
            throw CourseTeacherAssignmentException::notFound();
        }
        $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedYear instanceof AcademicYear
            || !$lockedYear->getInstitution()->getId()->equals($lockedInstitution->getId())) {
            throw CourseTeacherAssignmentException::crossInstitution();
        }
        $lockedClassroom = $this->freshEntities->findFreshLockedClassroom($classroomId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedClassroom instanceof Classroom
            || !$lockedClassroom->getInstitution()->getId()->equals($lockedInstitution->getId())
            || !$lockedClassroom->getAcademicYear()->getId()->equals($lockedYear->getId())) {
            throw CourseTeacherAssignmentException::crossInstitution();
        }
        $lockedCourse = $this->freshEntities->findFreshLockedClassroomCourse($courseId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedCourse instanceof ClassroomCourse
            || !$lockedCourse->getClassroom()->getId()->equals($lockedClassroom->getId())) {
            throw CourseTeacherAssignmentException::notFound();
        }

        return [$lockedInstitution, $lockedYear, $lockedClassroom, $lockedCourse];
    }

    private function assertOperable(
        Institution $institution,
        AcademicYear $year,
        Classroom $classroom,
        ClassroomCourse $course,
    ): void {
        $this->assertInstitutionActive($institution);
        if (AcademicYearStatus::Closed === $year->getStatus()) {
            throw CourseTeacherAssignmentException::yearNotOperable();
        }
        if (ClassroomStatus::Active !== $classroom->getStatus()) {
            throw CourseTeacherAssignmentException::classroomNotOperable();
        }
        if (ClassroomCourseStatus::Active !== $course->getStatus()) {
            throw CourseTeacherAssignmentException::courseNotOperable();
        }
    }

    private function assertInstitutionActive(Institution $institution): void
    {
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw CourseTeacherAssignmentException::institutionNotOperable();
        }
    }

    private function assertEligibleTeacher(
        InstitutionMembership $membership,
        Institution $institution,
        User $subjectUser,
    ): void {
        if (!$membership->getInstitution()->getId()->equals($institution->getId())) {
            throw CourseTeacherAssignmentException::crossInstitution();
        }
        if (InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw CourseTeacherAssignmentException::unauthorized();
        }
        if (InstitutionMembershipRole::Teacher !== $membership->getRole()) {
            throw CourseTeacherAssignmentException::unauthorized();
        }
        if (UserStatus::Active !== $subjectUser->getStatus() || null === $subjectUser->getEmailVerifiedAt()) {
            throw CourseTeacherAssignmentException::unauthorized();
        }
    }

    private function assertActorMayManage(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw CourseTeacherAssignmentException::unauthorized();
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
            throw CourseTeacherAssignmentException::unauthorized();
        }

        $role = $actorMembership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw CourseTeacherAssignmentException::unauthorized();
        }
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw CourseTeacherAssignmentException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
