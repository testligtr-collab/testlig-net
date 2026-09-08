<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Classroom;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionType;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InstitutionMembershipException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MembershipClassroomLinkLifecycleTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private InstitutionMembershipRepository $memberships;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testTeacherToStaffBlockedWithActiveAssignment(): void
    {
        [$owner, , $institution, , $classroom] = $this->readyClassroom('ml-tch-role');
        $tm = $this->addTeacher($institution, $owner, 'ml-tch-role-t@example.com');
        $this->teacherManager()->assign($classroom, $owner, $tm, TeacherAssignmentRole::HomeroomTeacher, 'asg');

        try {
            $this->membershipManager()->changeRole($tm, $owner, InstitutionMembershipRole::Staff, 'to_staff');
            self::fail('teacher→staff should be blocked');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::ActiveClassroomLinkConflict, $e->getReason());
        }
        $this->resetDoctrine();

        $tm = $this->memberships->find($tm->getId());
        self::assertInstanceOf(InstitutionMembership::class, $tm);
        self::assertSame(InstitutionMembershipRole::Teacher, $tm->getRole());
        self::assertSame(InstitutionMembershipStatus::Active, $tm->getStatus());
    }

    public function testStudentToStaffBlockedWithActiveEnrollment(): void
    {
        [$owner, , $institution, , $classroom] = $this->readyClassroom('ml-stu-role');
        $sm = $this->addStudent($institution, $owner, 'ml-stu-role-s@example.com');
        $this->enrollmentManager()->enroll($classroom, $owner, $sm, 'enr');

        try {
            $this->membershipManager()->changeRole($sm, $owner, InstitutionMembershipRole::Staff, 'to_staff');
            self::fail('student→staff should be blocked');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::ActiveClassroomLinkConflict, $e->getReason());
        }
        $this->resetDoctrine();

        $sm = $this->memberships->find($sm->getId());
        self::assertInstanceOf(InstitutionMembership::class, $sm);
        self::assertSame(InstitutionMembershipRole::Student, $sm->getRole());
    }

    public function testTeacherSuspendAndEndBlockedWithActiveAssignment(): void
    {
        [$owner, , $institution, , $classroom] = $this->readyClassroom('ml-tch-stat');
        $tm = $this->addTeacher($institution, $owner, 'ml-tch-stat-t@example.com');
        $this->teacherManager()->assign($classroom, $owner, $tm, TeacherAssignmentRole::AssistantTeacher, 'asg');

        try {
            $this->membershipManager()->suspend($tm, $owner, 'suspend_tch');
            self::fail('teacher suspend should be blocked');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::ActiveClassroomLinkConflict, $e->getReason());
        }
        $this->resetDoctrine();

        $tm = $this->memberships->find($tm->getId());
        self::assertInstanceOf(InstitutionMembership::class, $tm);
        self::assertSame(InstitutionMembershipStatus::Active, $tm->getStatus());
        $owner = $this->users->find($owner->getId());
        self::assertInstanceOf(User::class, $owner);

        try {
            $this->membershipManager()->endMembership($tm, $owner, 'end_tch');
            self::fail('teacher end should be blocked');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::ActiveClassroomLinkConflict, $e->getReason());
        }
        $this->resetDoctrine();

        $tm = $this->memberships->find($tm->getId());
        self::assertInstanceOf(InstitutionMembership::class, $tm);
        self::assertSame(InstitutionMembershipStatus::Active, $tm->getStatus());
    }

    public function testStudentSuspendAndEndBlockedWithActiveEnrollment(): void
    {
        [$owner, , $institution, , $classroom] = $this->readyClassroom('ml-stu-stat');
        $sm = $this->addStudent($institution, $owner, 'ml-stu-stat-s@example.com');
        $this->enrollmentManager()->enroll($classroom, $owner, $sm, 'enr');

        try {
            $this->membershipManager()->suspend($sm, $owner, 'suspend_stu');
            self::fail('student suspend should be blocked');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::ActiveClassroomLinkConflict, $e->getReason());
        }
        $this->resetDoctrine();

        $sm = $this->memberships->find($sm->getId());
        self::assertInstanceOf(InstitutionMembership::class, $sm);
        self::assertSame(InstitutionMembershipStatus::Active, $sm->getStatus());
        $owner = $this->users->find($owner->getId());
        self::assertInstanceOf(User::class, $owner);

        try {
            $this->membershipManager()->endMembership($sm, $owner, 'end_stu');
            self::fail('student end should be blocked');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::ActiveClassroomLinkConflict, $e->getReason());
        }
        $this->resetDoctrine();

        $sm = $this->memberships->find($sm->getId());
        self::assertInstanceOf(InstitutionMembership::class, $sm);
        self::assertSame(InstitutionMembershipStatus::Active, $sm->getStatus());
    }

    public function testRoleAndStatusChangeSucceedAfterEndingClassroomLinks(): void
    {
        [$owner, , $institution, , $classroom] = $this->readyClassroom('ml-after-end');
        $tm = $this->addTeacher($institution, $owner, 'ml-after-end-t@example.com');
        $sm = $this->addStudent($institution, $owner, 'ml-after-end-s@example.com');
        $assignment = $this->teacherManager()->assign($classroom, $owner, $tm, TeacherAssignmentRole::HomeroomTeacher, 'asg');
        $enrollment = $this->enrollmentManager()->enroll($classroom, $owner, $sm, 'enr');

        $this->teacherManager()->endAssignment($assignment, $owner, 'end_asg');
        $this->enrollmentManager()->endEnrollment($enrollment, $owner, 'end_enr');

        $this->membershipManager()->changeRole($tm, $owner, InstitutionMembershipRole::Staff, 'tch_staff');
        $this->membershipManager()->changeRole($sm, $owner, InstitutionMembershipRole::Staff, 'stu_staff');

        $this->em->refresh($tm);
        $this->em->refresh($sm);
        self::assertSame(InstitutionMembershipRole::Staff, $tm->getRole());
        self::assertSame(InstitutionMembershipRole::Staff, $sm->getRole());
    }

    public function testReactivateDoesNotReviveEndedAssignmentOrEnrollment(): void
    {
        [$owner, , $institution, , $classroom] = $this->readyClassroom('ml-react');
        $tm = $this->addTeacher($institution, $owner, 'ml-react-t@example.com');
        $sm = $this->addStudent($institution, $owner, 'ml-react-s@example.com');
        $assignment = $this->teacherManager()->assign($classroom, $owner, $tm, TeacherAssignmentRole::AssistantTeacher, 'asg');
        $enrollment = $this->enrollmentManager()->enroll($classroom, $owner, $sm, 'enr');

        $this->teacherManager()->endAssignment($assignment, $owner, 'end_asg');
        $this->enrollmentManager()->endEnrollment($enrollment, $owner, 'end_enr');
        $this->membershipManager()->suspend($tm, $owner, 'sus_tch');
        $this->membershipManager()->suspend($sm, $owner, 'sus_stu');
        $this->membershipManager()->reactivate($tm, $owner, 're_tch');
        $this->membershipManager()->reactivate($sm, $owner, 're_stu');

        $this->em->refresh($assignment);
        $this->em->refresh($enrollment);
        $this->em->refresh($tm);
        $this->em->refresh($sm);

        self::assertSame(InstitutionMembershipStatus::Active, $tm->getStatus());
        self::assertSame(InstitutionMembershipStatus::Active, $sm->getStatus());
        self::assertSame(TeacherAssignmentStatus::Ended, $assignment->getStatus());
        self::assertSame(StudentEnrollmentStatus::Ended, $enrollment->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM classroom_teacher_active_guards'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM academic_year_student_enrollment_guards'));
    }

    public function testOtherInstitutionAssignmentDoesNotBlock(): void
    {
        [$ownerA, , $instA] = $this->activeOwnerInstitution('ml-iso-a');
        [$ownerB, , $instB, , $classroomB] = $this->readyClassroom('ml-iso-b');

        $sharedTeacher = $this->activeUser('ml-iso-shared-t@example.com');
        $this->membershipManager()->addMember($instA, $ownerA, $sharedTeacher, InstitutionMembershipRole::Teacher, 'a_tch');
        $this->membershipManager()->addMember($instB, $ownerB, $sharedTeacher, InstitutionMembershipRole::Teacher, 'b_tch');
        $tmA = $this->memberships->findActiveMembership($sharedTeacher, $instA);
        $tmB = $this->memberships->findActiveMembership($sharedTeacher, $instB);
        self::assertInstanceOf(InstitutionMembership::class, $tmA);
        self::assertInstanceOf(InstitutionMembership::class, $tmB);

        $this->teacherManager()->assign($classroomB, $ownerB, $tmB, TeacherAssignmentRole::HomeroomTeacher, 'b_asg');

        $this->membershipManager()->changeRole($tmA, $ownerA, InstitutionMembershipRole::Staff, 'a_to_staff');
        $this->em->refresh($tmA);
        self::assertSame(InstitutionMembershipRole::Staff, $tmA->getRole());

        try {
            $this->membershipManager()->changeRole($tmB, $ownerB, InstitutionMembershipRole::Staff, 'b_to_staff');
            self::fail('institution B teacher still blocked');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::ActiveClassroomLinkConflict, $e->getReason());
        }
    }

    public function testStaffWithoutClassroomLinksUnaffected(): void
    {
        [$owner, , $institution] = $this->activeOwnerInstitution('ml-staff');
        $staffUser = $this->activeUser('ml-staff-u@example.com');
        $this->membershipManager()->addMember($institution, $owner, $staffUser, InstitutionMembershipRole::Staff, 'add_staff');
        $staff = $this->memberships->findActiveMembership($staffUser, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $staff);

        $this->membershipManager()->changeRole($staff, $owner, InstitutionMembershipRole::Teacher, 'to_tch');
        $this->membershipManager()->changeRole($staff, $owner, InstitutionMembershipRole::Staff, 'back_staff');
        $this->membershipManager()->suspend($staff, $owner, 'sus');
        $this->membershipManager()->reactivate($staff, $owner, 're');
        $this->membershipManager()->endMembership($staff, $owner, 'end');

        $this->em->refresh($staff);
        self::assertSame(InstitutionMembershipStatus::Ended, $staff->getStatus());
    }

    /**
     * @return array{0: User, 1: User, 2: \App\Entity\Institution}
     */
    private function activeOwnerInstitution(string $prefix): array
    {
        $sa = $this->activeUser($prefix.'-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser($prefix.'-owner@example.com');
        $institution = $this->creator()->create($sa, $owner, $prefix.' School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate');

        return [$owner, $sa, $institution];
    }

    /**
     * @return array{0: User, 1: User, 2: \App\Entity\Institution, 3: \App\Entity\AcademicYear, 4: Classroom}
     */
    private function readyClassroom(string $prefix, ?int $capacity = 30): array
    {
        [$owner, $sa, $institution] = $this->activeOwnerInstitution($prefix);
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            $prefix.' Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'year',
        );
        $this->yearManager()->activate($year, $owner, 'act');
        $classroom = $this->classroomManager()->create(
            $year,
            $owner,
            $prefix.' 10-A',
            GradeLevel::Grade10,
            'cls',
            'A',
            $capacity,
        );

        return [$owner, $sa, $institution, $year, $classroom];
    }

    private function addTeacher(\App\Entity\Institution $institution, User $owner, string $email): InstitutionMembership
    {
        $user = $this->activeUser($email);
        $this->membershipManager()->addMember($institution, $owner, $user, InstitutionMembershipRole::Teacher, 'add_tch');
        $membership = $this->memberships->findActiveMembership($user, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);

        return $membership;
    }

    private function addStudent(\App\Entity\Institution $institution, User $owner, string $email): InstitutionMembership
    {
        $user = $this->activeUser($email);
        $this->membershipManager()->addMember($institution, $owner, $user, InstitutionMembershipRole::Student, 'add_stu');
        $membership = $this->memberships->findActiveMembership($user, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);

        return $membership;
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

    private function teacherManager(): ClassroomTeacherAssignmentManager
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

    private function rebind(): void
    {
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
    }

    private function resetDoctrine(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebind();
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        foreach ([
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
