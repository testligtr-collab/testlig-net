<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\ClassroomCourse;
use App\Entity\ClassroomCourseActiveGuard;
use App\Entity\CurriculumProgram;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomCourseStatus;
use App\Enum\ClassroomStatus;
use App\Enum\CurriculumStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\SubjectStatus;
use App\Exception\ClassroomCourseException;
use App\Repository\ClassroomCourseActiveGuardRepository;
use App\Repository\ClassroomCourseRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Classroom course offerings. Owner/Manager or SUPER_ADMIN.
 *
 * Lock order: Institution → AcademicYear → Classroom → ClassroomCourse → Users.
 */
final class ClassroomCourseManager
{
    public function __construct(
        private readonly ClassroomCourseRepository $courses,
        private readonly ClassroomCourseActiveGuardRepository $activeGuards,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        Classroom $classroom,
        User $actor,
        Subject $subject,
        CurriculumProgram $curriculumProgram,
        string $reasonCode,
        ?int $weeklyLessonHours = null,
    ): ClassroomCourse {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        ClassroomCourse::assertValidWeeklyLessonHours($weeklyLessonHours);

        $classroomId = $classroom->getId();
        $institutionId = $classroom->getInstitution()->getId();
        $yearId = $classroom->getAcademicYear()->getId();
        $subjectId = $subject->getId();
        $programId = $curriculumProgram->getId();
        $actorId = $actor->getId();

        try {
            $course = $this->entityManager->wrapInTransaction(function () use (
                $classroomId,
                $institutionId,
                $yearId,
                $subjectId,
                $programId,
                $actorId,
                $weeklyLessonHours,
                $reasonCode,
            ): ClassroomCourse {
                [$lockedInstitution, $lockedYear, $lockedClassroom] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                );
                $this->assertOperable($lockedInstitution, $lockedYear, $lockedClassroom);

                $lockedSubject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedSubject instanceof Subject) {
                    throw ClassroomCourseException::notFound();
                }
                if (SubjectStatus::Archived === $lockedSubject->getStatus()) {
                    throw ClassroomCourseException::subjectArchived();
                }

                $lockedProgram = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedProgram instanceof CurriculumProgram) {
                    throw ClassroomCourseException::notFound();
                }
                $this->assertProgramAssignable($lockedProgram, $lockedSubject, $lockedClassroom);

                $freshActor = $this->lockActor($actorId);
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                if (null !== $this->freshEntities->findFreshClassroomCourseActiveGuard(
                    $lockedClassroom->getId(),
                    $lockedSubject->getId(),
                )) {
                    throw ClassroomCourseException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $course = ClassroomCourse::create(
                    $lockedClassroom,
                    $lockedSubject,
                    $lockedProgram,
                    $weeklyLessonHours,
                    $now,
                );
                $this->courses->save($course, false);
                $this->activeGuards->save(
                    ClassroomCourseActiveGuard::bind($lockedClassroom, $lockedSubject, $course),
                    false,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ClassroomCourseCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'classroom_course_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                        'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                        'classroom_course_id' => $course->getId()->toRfc4122(),
                        'subject_id' => $lockedSubject->getId()->toRfc4122(),
                        'curriculum_id' => $lockedProgram->getId()->toRfc4122(),
                        'weekly_lesson_hours' => $weeklyLessonHours,
                        'new_status' => $course->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $course;
            });
        } catch (UniqueConstraintViolationException) {
            throw ClassroomCourseException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomCourseException::conflict();
        }

        $this->authCache->invalidateClassroomCourse($course->getId());
        $this->authCache->invalidateClassroom($classroomId);
        $this->authCache->invalidateInstitution($institutionId);

        return $course;
    }

    public function changeWeeklyLessonHours(
        ClassroomCourse $course,
        User $actor,
        ?int $weeklyLessonHours,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        ClassroomCourse::assertValidWeeklyLessonHours($weeklyLessonHours);

        $this->mutate($course, $actor, $reasonCode, function (
            Institution $lockedInstitution,
            AcademicYear $lockedYear,
            Classroom $lockedClassroom,
            ClassroomCourse $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($weeklyLessonHours): void {
            unset($lockedYear, $lockedClassroom);
            if (ClassroomCourseStatus::Archived === $locked->getStatus()) {
                throw ClassroomCourseException::courseNotOperable();
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->changeWeeklyLessonHours($weeklyLessonHours, $now);
            $this->courses->save($locked, false);

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ClassroomCourseUpdated,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $freshActor,
                metadata: [
                    'source' => 'classroom_course_manager',
                    'reason_code' => $reasonCode,
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'classroom_course_id' => $locked->getId()->toRfc4122(),
                    'weekly_lesson_hours' => $weeklyLessonHours,
                ],
                captureRequestHashes: false,
            ), false);
        });
    }

    public function changeCurriculum(
        ClassroomCourse $course,
        User $actor,
        CurriculumProgram $curriculumProgram,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $programId = $curriculumProgram->getId();

        $this->mutate($course, $actor, $reasonCode, function (
            Institution $lockedInstitution,
            AcademicYear $lockedYear,
            Classroom $lockedClassroom,
            ClassroomCourse $locked,
            User $freshActor,
            string $reasonCode,
        ) use ($programId): void {
            unset($lockedYear);
            if (ClassroomCourseStatus::Archived === $locked->getStatus()) {
                throw ClassroomCourseException::courseNotOperable();
            }

            $lockedProgram = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::PESSIMISTIC_WRITE);
            if (!$lockedProgram instanceof CurriculumProgram) {
                throw ClassroomCourseException::notFound();
            }
            $this->assertProgramAssignable($lockedProgram, $locked->getSubject(), $lockedClassroom);

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->changeCurriculum($lockedProgram, $now);
            $this->courses->save($locked, false);

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ClassroomCourseCurriculumChanged,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $freshActor,
                metadata: [
                    'source' => 'classroom_course_manager',
                    'reason_code' => $reasonCode,
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                    'classroom_course_id' => $locked->getId()->toRfc4122(),
                    'subject_id' => $locked->getSubject()->getId()->toRfc4122(),
                    'curriculum_id' => $lockedProgram->getId()->toRfc4122(),
                ],
                captureRequestHashes: false,
            ), false);
        });
    }

    public function archive(ClassroomCourse $course, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        $this->mutate($course, $actor, $reasonCode, function (
            Institution $lockedInstitution,
            AcademicYear $lockedYear,
            Classroom $lockedClassroom,
            ClassroomCourse $locked,
            User $freshActor,
            string $reasonCode,
        ): void {
            unset($lockedYear);
            if ($this->courses->countActiveTeacherAssignments($locked) > 0) {
                throw ClassroomCourseException::activeTeachers();
            }

            $oldStatus = $locked->getStatus()->value;
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->archive($now);
            $this->courses->save($locked, false);

            $guard = $this->freshEntities->findFreshClassroomCourseActiveGuard(
                $lockedClassroom->getId(),
                $locked->getSubject()->getId(),
            );
            if ($guard instanceof ClassroomCourseActiveGuard
                && $guard->getCourse()->getId()->equals($locked->getId())) {
                $this->activeGuards->remove($guard, false);
            }

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ClassroomCourseArchived,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $freshActor,
                metadata: [
                    'source' => 'classroom_course_manager',
                    'reason_code' => $reasonCode,
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'classroom_id' => $lockedClassroom->getId()->toRfc4122(),
                    'classroom_course_id' => $locked->getId()->toRfc4122(),
                    'old_status' => $oldStatus,
                    'new_status' => $locked->getStatus()->value,
                ],
                captureRequestHashes: false,
            ), false);
        });
    }

    /**
     * @param callable(Institution, AcademicYear, Classroom, ClassroomCourse, User, string): void $mutator
     */
    private function mutate(ClassroomCourse $course, User $actor, string $reasonCode, callable $mutator): void
    {
        $courseId = $course->getId();
        $classroomId = $course->getClassroom()->getId();
        $institutionId = $course->getInstitution()->getId();
        $yearId = $course->getAcademicYear()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $courseId,
                $classroomId,
                $institutionId,
                $yearId,
                $actorId,
                $reasonCode,
                $mutator,
            ): void {
                [$lockedInstitution, $lockedYear, $lockedClassroom] = $this->lockHierarchy(
                    $institutionId,
                    $yearId,
                    $classroomId,
                );
                $this->assertInstitutionActive($lockedInstitution);

                $locked = $this->freshEntities->findFreshLockedClassroomCourse($courseId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof ClassroomCourse
                    || !$locked->getClassroom()->getId()->equals($lockedClassroom->getId())
                    || !$locked->getInstitution()->getId()->equals($lockedInstitution->getId())) {
                    throw ClassroomCourseException::notFound();
                }

                $freshActor = $this->lockActor($actorId);
                $this->assertActorMayManage($freshActor, $lockedInstitution);
                $mutator($lockedInstitution, $lockedYear, $lockedClassroom, $locked, $freshActor, $reasonCode);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw ClassroomCourseException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw ClassroomCourseException::conflict();
        }

        $this->authCache->invalidateClassroomCourse($courseId);
        $this->authCache->invalidateClassroom($classroomId);
        $this->authCache->invalidateInstitution($institutionId);
    }

    /**
     * @return array{0: Institution, 1: AcademicYear, 2: Classroom}
     */
    private function lockHierarchy(Uuid $institutionId, Uuid $yearId, Uuid $classroomId): array
    {
        $lockedInstitution = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedInstitution instanceof Institution) {
            throw ClassroomCourseException::notFound();
        }
        $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedYear instanceof AcademicYear
            || !$lockedYear->getInstitution()->getId()->equals($lockedInstitution->getId())) {
            throw ClassroomCourseException::crossInstitution();
        }
        $lockedClassroom = $this->freshEntities->findFreshLockedClassroom($classroomId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedClassroom instanceof Classroom
            || !$lockedClassroom->getInstitution()->getId()->equals($lockedInstitution->getId())
            || !$lockedClassroom->getAcademicYear()->getId()->equals($lockedYear->getId())) {
            throw ClassroomCourseException::crossInstitution();
        }

        return [$lockedInstitution, $lockedYear, $lockedClassroom];
    }

    private function assertOperable(Institution $institution, AcademicYear $year, Classroom $classroom): void
    {
        $this->assertInstitutionActive($institution);
        if (AcademicYearStatus::Closed === $year->getStatus()) {
            throw ClassroomCourseException::yearNotOperable();
        }
        if (ClassroomStatus::Active !== $classroom->getStatus()) {
            throw ClassroomCourseException::classroomNotOperable();
        }
    }

    private function assertInstitutionActive(Institution $institution): void
    {
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw ClassroomCourseException::institutionNotOperable();
        }
    }

    private function assertProgramAssignable(
        CurriculumProgram $program,
        Subject $subject,
        Classroom $classroom,
    ): void {
        if (CurriculumStatus::Published !== $program->getStatus()) {
            throw ClassroomCourseException::curriculumNotPublished();
        }
        if (!$program->getSubject()->getId()->equals($subject->getId())) {
            throw ClassroomCourseException::subjectMismatch();
        }
        if ($program->getGradeLevel() !== $classroom->getGradeLevel()) {
            throw ClassroomCourseException::gradeMismatch();
        }
    }

    private function lockActor(Uuid $actorId): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw ClassroomCourseException::userNotFound();
        }

        return $freshActor;
    }

    private function assertActorMayManage(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw ClassroomCourseException::unauthorized();
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
            throw ClassroomCourseException::unauthorized();
        }

        $role = $actorMembership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw ClassroomCourseException::unauthorized();
        }
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw ClassroomCourseException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
