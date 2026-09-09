<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Classroom;
use App\Entity\ClassroomCourse;
use App\Entity\CurriculumProgram;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\UserRepository;
use App\Security\ClassroomCoursePermission;
use App\Security\RequestScopedInstitutionAuthLookup;
use App\Service\AcademicYearManager;
use App\Service\ClassroomCourseManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\CourseTeacherAssignmentManager;
use App\Service\CurriculumProgramManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class ClassroomCourseVoterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private InstitutionMembershipRepository $memberships;
    private AccessDecisionManagerInterface $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $memberships = $c->get(InstitutionMembershipRepository::class);
        self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
        $this->memberships = $memberships;
        $access = $c->get(AccessDecisionManagerInterface::class);
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $access);
        $this->access = $access;
        $this->cleanup();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function ownerManagerMatrix(): iterable
    {
        foreach (ClassroomCoursePermission::all() as $permission) {
            yield 'owner_'.$permission => ['owner', $permission, true];
            yield 'manager_'.$permission => ['manager', $permission, true];
        }
    }

    #[DataProvider('ownerManagerMatrix')]
    public function testOwnerAndManagerHaveFullAccess(string $roleLabel, string $attribute, bool $expected): void
    {
        $role = 'owner' === $roleLabel ? InstitutionMembershipRole::Owner : InstitutionMembershipRole::Manager;
        [$course, $user] = $this->courseWithMember($role);
        self::assertSame($expected, $this->decide($user, $attribute, $course));
    }

    public function testCourseTeacherViewAndCurriculumViewOnly(): void
    {
        [$course, $teacher, $owner, $institution] = $this->courseWithTeacherMembership();
        self::assertFalse($this->decide($teacher, ClassroomCoursePermission::VIEW, $course));

        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->courseTeacherManager()->assign($course, $owner, $membership, 'assign');
        $this->resetLookup();

        self::assertTrue($this->decide($teacher, ClassroomCoursePermission::VIEW, $course));
        self::assertTrue($this->decide($teacher, ClassroomCoursePermission::CURRICULUM_VIEW, $course));
        self::assertFalse($this->decide($teacher, ClassroomCoursePermission::TEACHERS_VIEW, $course));
        self::assertFalse($this->decide($teacher, ClassroomCoursePermission::MANAGE, $course));
        self::assertFalse($this->decide($teacher, ClassroomCoursePermission::TEACHERS_MANAGE, $course));
    }

    public function testHomeroomTeacherViewCurriculumAndTeachersView(): void
    {
        [$course, $teacher, $owner, $institution, $classroom] = $this->courseWithTeacherMembershipExtended();
        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->classroomTeacherManager()->assign(
            $classroom,
            $owner,
            $membership,
            TeacherAssignmentRole::HomeroomTeacher,
            'homeroom',
        );
        $this->resetLookup();

        self::assertTrue($this->decide($teacher, ClassroomCoursePermission::VIEW, $course));
        self::assertTrue($this->decide($teacher, ClassroomCoursePermission::CURRICULUM_VIEW, $course));
        self::assertTrue($this->decide($teacher, ClassroomCoursePermission::TEACHERS_VIEW, $course));
        self::assertFalse($this->decide($teacher, ClassroomCoursePermission::MANAGE, $course));
        self::assertFalse($this->decide($teacher, ClassroomCoursePermission::TEACHERS_MANAGE, $course));
    }

    public function testStaffViewOnly(): void
    {
        [$course, $staff] = $this->courseWithMember(InstitutionMembershipRole::Staff);
        self::assertTrue($this->decide($staff, ClassroomCoursePermission::VIEW, $course));
        self::assertFalse($this->decide($staff, ClassroomCoursePermission::CURRICULUM_VIEW, $course));
        self::assertFalse($this->decide($staff, ClassroomCoursePermission::MANAGE, $course));
        self::assertFalse($this->decide($staff, ClassroomCoursePermission::TEACHERS_VIEW, $course));
    }

    public function testStudentEnrolledInSameClassroom(): void
    {
        [$course, $student, $owner, $institution, $classroom] = $this->courseWithStudentMembership();
        self::assertFalse($this->decide($student, ClassroomCoursePermission::VIEW, $course));

        $membership = $this->memberships->findActiveMembership($student, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->enrollmentManager()->enroll($classroom, $owner, $membership, 'enroll');
        $this->resetLookup();

        self::assertTrue($this->decide($student, ClassroomCoursePermission::VIEW, $course));
        self::assertTrue($this->decide($student, ClassroomCoursePermission::CURRICULUM_VIEW, $course));
        self::assertFalse($this->decide($student, ClassroomCoursePermission::MANAGE, $course));
        self::assertFalse($this->decide($student, ClassroomCoursePermission::TEACHERS_VIEW, $course));
    }

    public function testStudentInOtherClassroomDenied(): void
    {
        [$course, $student, $owner, $institution, $classroom] = $this->courseWithStudentMembership();
        $otherClassroom = $this->classroomManager()->create(
            $classroom->getAcademicYear(),
            $owner,
            'Other Class',
            GradeLevel::Grade9,
            'other_cls',
            'B',
            25,
        );
        $membership = $this->memberships->findActiveMembership($student, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->enrollmentManager()->enroll($otherClassroom, $owner, $membership, 'enroll_other');
        $this->resetLookup();

        self::assertFalse($this->decide($student, ClassroomCoursePermission::VIEW, $course));
        self::assertFalse($this->decide($student, ClassroomCoursePermission::CURRICULUM_VIEW, $course));
    }

    public function testAdminModeratorNoAutomaticAccessAndSuperAdminOverride(): void
    {
        [$course] = $this->courseWithMember(InstitutionMembershipRole::Owner);
        $admin = $this->activeUser('ccv-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $mod = $this->activeUser('ccv-mod@example.com');
        $mod->addGlobalRole(UserRole::Moderator);
        $this->users->save($mod);
        $sa = $this->activeUser('ccv-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);

        self::assertFalse($this->decide($admin, ClassroomCoursePermission::VIEW, $course));
        self::assertFalse($this->decide($mod, ClassroomCoursePermission::MANAGE, $course));
        self::assertTrue($this->decide($sa, ClassroomCoursePermission::MANAGE, $course));
        self::assertTrue($this->decide($sa, ClassroomCoursePermission::TEACHERS_MANAGE, $course));
    }

    public function testPendingSuspendedUserDenied(): void
    {
        [$course, $owner] = $this->courseWithMember(InstitutionMembershipRole::Owner);

        foreach ([UserStatus::PendingVerification, UserStatus::Suspended] as $status) {
            $this->setUserStatusInDb($owner, $status);
            $stale = $this->detachKeepingMemory($owner);
            self::assertSame(UserStatus::Active, $stale->getStatus());
            $this->resetLookup();
            self::assertFalse($this->decide($stale, ClassroomCoursePermission::VIEW, $course));
            $this->setUserStatusInDb($owner, UserStatus::Active);
            $this->em->clear();
            $reloaded = $this->users->find($owner->getId());
            self::assertInstanceOf(User::class, $reloaded);
            $owner = $reloaded;
            $course = $this->em->find(ClassroomCourse::class, $course->getId());
            self::assertInstanceOf(ClassroomCourse::class, $course);
        }
    }

    public function testAnonymousDenied(): void
    {
        [$course] = $this->courseWithMember(InstitutionMembershipRole::Owner);
        self::assertFalse($this->access->decide(new NullToken(), [ClassroomCoursePermission::VIEW], $course));
    }

    /**
     * @return array{0: ClassroomCourse, 1: User}
     */
    private function courseWithMember(InstitutionMembershipRole $role): array
    {
        [$course, $owner, $institution] = $this->baseCourse('ccv_'.$role->value);
        if (InstitutionMembershipRole::Owner === $role) {
            return [$course, $owner];
        }
        $user = $this->activeUser('ccv-'.$role->value.'@example.com');
        $this->membershipManager()->addMember($institution, $owner, $user, $role, 'add');

        return [$course, $user];
    }

    /**
     * @return array{0: ClassroomCourse, 1: User, 2: User, 3: Institution}
     */
    private function courseWithTeacherMembership(): array
    {
        [$course, $owner, $institution] = $this->baseCourse('ccv_tch');
        $teacher = $this->activeUser('ccv-tch-user@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add');

        return [$course, $teacher, $owner, $institution];
    }

    /**
     * @return array{0: ClassroomCourse, 1: User, 2: User, 3: Institution, 4: Classroom}
     */
    private function courseWithTeacherMembershipExtended(): array
    {
        [$course, $owner, $institution, $classroom] = $this->baseCourseExtended('ccv_hr');
        $teacher = $this->activeUser('ccv-hr-user@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add');

        return [$course, $teacher, $owner, $institution, $classroom];
    }

    /**
     * @return array{0: ClassroomCourse, 1: User, 2: User, 3: Institution, 4: Classroom}
     */
    private function courseWithStudentMembership(): array
    {
        [$course, $owner, $institution, $classroom] = $this->baseCourseExtended('ccv_stu');
        $student = $this->activeUser('ccv-stu-user@example.com');
        $this->membershipManager()->addMember($institution, $owner, $student, InstitutionMembershipRole::Student, 'add');

        return [$course, $student, $owner, $institution, $classroom];
    }

    /**
     * @return array{0: ClassroomCourse, 1: User, 2: Institution}
     */
    private function baseCourse(string $prefix): array
    {
        [$course, $owner, $institution] = $this->baseCourseExtended($prefix);

        return [$course, $owner, $institution];
    }

    /**
     * @return array{0: ClassroomCourse, 1: User, 2: Institution, 3: Classroom, 4: Subject, 5: CurriculumProgram}
     */
    private function baseCourseExtended(string $prefix): array
    {
        $sa = $this->activeUser($prefix.'-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser($prefix.'-owner@example.com');
        $institution = $this->creator()->create($sa, $owner, $prefix.' School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate');
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            $prefix.' Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'year',
        );
        $this->yearManager()->activate($year, $owner, 'act');
        $classroom = $this->classroomManager()->create($year, $owner, $prefix.' Class', GradeLevel::Grade9, 'cls', 'A', 25);
        $subject = $this->subjectManager()->create($sa, $prefix.'_math', 'Math '.$prefix, 'subj');
        $program = $this->programManager()->createDraft(
            $subject,
            $sa,
            GradeLevel::Grade9,
            $prefix.'_prog',
            'Prog '.$prefix,
            '1.0',
            'prog',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );
        $this->programManager()->publish($program, $sa, 'pub');
        $course = $this->courseManager()->create($classroom, $owner, $subject, $program, 'create_course', 3);

        return [$course, $owner, $institution, $classroom, $subject, $program];
    }

    private function decide(User $user, string $attribute, ClassroomCourse $course): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $course);
    }

    private function resetLookup(): void
    {
        $lookup = static::getContainer()->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);
        $lookup->reset();
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function detachKeepingMemory(object $entity): object
    {
        $this->em->detach($entity);

        return $entity;
    }

    private function setUserStatusInDb(User $user, UserStatus $status): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = :status WHERE id = :id',
            [
                'status' => $status->value,
                'id' => $user->getId()->toBinary(),
            ],
        );
    }

    private function activeUser(string $email): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function creator(): InstitutionCreator
    {
        $service = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $service);

        return $service;
    }

    private function statusManager(): InstitutionStatusManager
    {
        $service = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $service);

        return $service;
    }

    private function membershipManager(): InstitutionMembershipManager
    {
        $service = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $service);

        return $service;
    }

    private function yearManager(): AcademicYearManager
    {
        $service = static::getContainer()->get(AcademicYearManager::class);
        self::assertInstanceOf(AcademicYearManager::class, $service);

        return $service;
    }

    private function classroomManager(): ClassroomManager
    {
        $service = static::getContainer()->get(ClassroomManager::class);
        self::assertInstanceOf(ClassroomManager::class, $service);

        return $service;
    }

    private function subjectManager(): SubjectManager
    {
        $service = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $service);

        return $service;
    }

    private function programManager(): CurriculumProgramManager
    {
        $service = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $service);

        return $service;
    }

    private function courseManager(): ClassroomCourseManager
    {
        $service = static::getContainer()->get(ClassroomCourseManager::class);
        self::assertInstanceOf(ClassroomCourseManager::class, $service);

        return $service;
    }

    private function courseTeacherManager(): CourseTeacherAssignmentManager
    {
        $service = static::getContainer()->get(CourseTeacherAssignmentManager::class);
        self::assertInstanceOf(CourseTeacherAssignmentManager::class, $service);

        return $service;
    }

    private function classroomTeacherManager(): ClassroomTeacherAssignmentManager
    {
        $service = static::getContainer()->get(ClassroomTeacherAssignmentManager::class);
        self::assertInstanceOf(ClassroomTeacherAssignmentManager::class, $service);

        return $service;
    }

    private function enrollmentManager(): ClassroomStudentEnrollmentManager
    {
        $service = static::getContainer()->get(ClassroomStudentEnrollmentManager::class);
        self::assertInstanceOf(ClassroomStudentEnrollmentManager::class, $service);

        return $service;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        foreach ([
            'course_teacher_active_guards',
            'course_teacher_assignments',
            'classroom_course_active_guards',
            'classroom_courses',
            'question_revision_primary_alignment_guards',
            'question_revision_alignments',
            'question_answer_keys',
            'question_revision_options',
            'question_revisions',
            'questions',
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'academic_year_student_enrollment_guards',
            'classroom_student_enrollments',
            'classroom_teacher_active_guards',
            'classroom_homeroom_guards',
            'classroom_teacher_assignments',
            'classrooms',
            'institution_active_academic_year_guards',
            'academic_years',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'security_bootstrap_guards',
            'reset_password_requests',
            'users',
        ] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}
