<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Classroom;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\UserRepository;
use App\Security\ClassroomPermission;
use App\Security\RequestScopedInstitutionAuthLookup;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class ClassroomVoterTest extends KernelTestCase
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
        foreach (ClassroomPermission::all() as $permission) {
            yield 'owner_'.$permission => ['owner', $permission, true];
            yield 'manager_'.$permission => ['manager', $permission, true];
        }
    }

    #[DataProvider('ownerManagerMatrix')]
    public function testOwnerAndManagerHaveFullAccess(string $roleLabel, string $attribute, bool $expected): void
    {
        $role = 'owner' === $roleLabel ? InstitutionMembershipRole::Owner : InstitutionMembershipRole::Manager;
        [$classroom, $user] = $this->classroomWithMember($role);
        self::assertSame($expected, $this->decide($user, $attribute, $classroom));
    }

    public function testTeacherNeedsActiveAssignment(): void
    {
        [$classroom, $teacher, $owner, $institution] = $this->classroomWithTeacherMembership();
        self::assertFalse($this->decide($teacher, ClassroomPermission::VIEW, $classroom));
        self::assertFalse($this->decide($teacher, ClassroomPermission::STUDENTS_VIEW, $classroom));
        self::assertFalse($this->decide($teacher, ClassroomPermission::MANAGE, $classroom));

        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->teacherManager()->assign($classroom, $owner, $membership, TeacherAssignmentRole::HomeroomTeacher, 'assign');
        $this->resetLookup();

        self::assertTrue($this->decide($teacher, ClassroomPermission::VIEW, $classroom));
        self::assertTrue($this->decide($teacher, ClassroomPermission::STUDENTS_VIEW, $classroom));
        self::assertFalse($this->decide($teacher, ClassroomPermission::MANAGE, $classroom));
        self::assertFalse($this->decide($teacher, ClassroomPermission::TEACHERS_MANAGE, $classroom));
    }

    public function testStaffViewOnly(): void
    {
        [$classroom, $staff] = $this->classroomWithMember(InstitutionMembershipRole::Staff);
        self::assertTrue($this->decide($staff, ClassroomPermission::VIEW, $classroom));
        self::assertFalse($this->decide($staff, ClassroomPermission::MANAGE, $classroom));
        self::assertFalse($this->decide($staff, ClassroomPermission::STUDENTS_VIEW, $classroom));
    }

    public function testStudentNeedsActiveEnrollment(): void
    {
        [$classroom, $student, $owner, $institution] = $this->classroomWithStudentMembership();
        self::assertFalse($this->decide($student, ClassroomPermission::VIEW, $classroom));
        self::assertFalse($this->decide($student, ClassroomPermission::STUDENTS_VIEW, $classroom));

        $membership = $this->memberships->findActiveMembership($student, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->enrollmentManager()->enroll($classroom, $owner, $membership, 'enroll');
        $this->resetLookup();

        self::assertTrue($this->decide($student, ClassroomPermission::VIEW, $classroom));
        self::assertFalse($this->decide($student, ClassroomPermission::STUDENTS_VIEW, $classroom));
        self::assertFalse($this->decide($student, ClassroomPermission::MANAGE, $classroom));
    }

    public function testAnonymousDenied(): void
    {
        [$classroom] = $this->classroomWithMember(InstitutionMembershipRole::Owner);
        self::assertFalse($this->access->decide(new NullToken(), [ClassroomPermission::VIEW], $classroom));
    }

    public function testAdminModeratorNoAutomaticAccessAndSuperAdminOverride(): void
    {
        [$classroom] = $this->classroomWithMember(InstitutionMembershipRole::Owner);
        $admin = $this->activeUser('cv-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $mod = $this->activeUser('cv-mod@example.com');
        $mod->addGlobalRole(UserRole::Moderator);
        $this->users->save($mod);
        $sa = $this->activeUser('cv-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);

        self::assertFalse($this->decide($admin, ClassroomPermission::VIEW, $classroom));
        self::assertFalse($this->decide($mod, ClassroomPermission::MANAGE, $classroom));
        self::assertTrue($this->decide($sa, ClassroomPermission::MANAGE, $classroom));
    }

    /**
     * @return array{0: Classroom, 1: User}
     */
    private function classroomWithMember(InstitutionMembershipRole $role): array
    {
        [$classroom, $owner, $institution] = $this->baseClassroom('cv-'.$role->value);
        if (InstitutionMembershipRole::Owner === $role) {
            return [$classroom, $owner];
        }
        $user = $this->activeUser('cv-'.$role->value.'@example.com');
        $this->membershipManager()->addMember($institution, $owner, $user, $role, 'add');

        return [$classroom, $user];
    }

    /**
     * @return array{0: Classroom, 1: User, 2: User, 3: \App\Entity\Institution}
     */
    private function classroomWithTeacherMembership(): array
    {
        [$classroom, $owner, $institution] = $this->baseClassroom('cv-tch');
        $teacher = $this->activeUser('cv-tch-user@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add');

        return [$classroom, $teacher, $owner, $institution];
    }

    /**
     * @return array{0: Classroom, 1: User, 2: User, 3: \App\Entity\Institution}
     */
    private function classroomWithStudentMembership(): array
    {
        [$classroom, $owner, $institution] = $this->baseClassroom('cv-stu');
        $student = $this->activeUser('cv-stu-user@example.com');
        $this->membershipManager()->addMember($institution, $owner, $student, InstitutionMembershipRole::Student, 'add');

        return [$classroom, $student, $owner, $institution];
    }

    /**
     * @return array{0: Classroom, 1: User, 2: \App\Entity\Institution}
     */
    private function baseClassroom(string $prefix): array
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
        $classroom = $this->classroomManager()->create($year, $owner, $prefix.' Class', GradeLevel::Grade8, 'cls', 'A', 25);

        return [$classroom, $owner, $institution];
    }

    private function decide(User $user, string $attribute, Classroom $classroom): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $classroom);
    }

    private function resetLookup(): void
    {
        $lookup = static::getContainer()->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);
        $lookup->reset();
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
