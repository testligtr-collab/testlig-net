<?php

declare(strict_types=1);

namespace App\Tests\Security;

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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class ClassroomAuthorizationCacheInvalidationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private InstitutionMembershipRepository $memberships;
    private AccessDecisionManagerInterface $access;
    private RequestScopedInstitutionAuthLookup $lookup;

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
        $lookup = $c->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);
        $this->lookup = $lookup;
        $this->cleanup();
    }

    public function testTeacherAssignmentInvalidatesCachedDenial(): void
    {
        $sa = $this->activeUser('cache-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser('cache-owner@example.com');
        $institution = $this->creator()->create($sa, $owner, 'Cache School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate');
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            'Cache Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'year',
        );
        $classroom = $this->classroomManager()->create($year, $owner, 'Cache Class', GradeLevel::Grade7, 'cls', null, 20);
        $teacher = $this->activeUser('cache-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add');

        self::assertFalse($this->decide($teacher, ClassroomPermission::VIEW, $classroom));

        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->teacherManager()->assign($classroom, $owner, $membership, TeacherAssignmentRole::AssistantTeacher, 'assign');

        // Post-commit invalidation must allow a fresh grant in the same request.
        self::assertTrue($this->decide($teacher, ClassroomPermission::VIEW, $classroom));
    }

    public function testStudentEnrollmentInvalidatesCachedDenial(): void
    {
        $sa = $this->activeUser('cache2-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser('cache2-owner@example.com');
        $institution = $this->creator()->create($sa, $owner, 'Cache2 School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate');
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            'Cache2 Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'year',
        );
        $classroom = $this->classroomManager()->create($year, $owner, 'Cache2 Class', GradeLevel::Grade6, 'cls');
        $student = $this->activeUser('cache2-stu@example.com');
        $this->membershipManager()->addMember($institution, $owner, $student, InstitutionMembershipRole::Student, 'add');

        self::assertFalse($this->decide($student, ClassroomPermission::VIEW, $classroom));
        $membership = $this->memberships->findActiveMembership($student, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->enrollmentManager()->enroll($classroom, $owner, $membership, 'enroll');
        self::assertTrue($this->decide($student, ClassroomPermission::VIEW, $classroom));
    }

    public function testManualInvalidateClassroomClearsSnapshot(): void
    {
        $sa = $this->activeUser('cache3-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser('cache3-owner@example.com');
        $institution = $this->creator()->create($sa, $owner, 'Cache3 School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate');
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            'Cache3 Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'year',
        );
        $classroom = $this->classroomManager()->create($year, $owner, 'Cache3 Class', GradeLevel::Grade5, 'cls');

        $snap = $this->lookup->getClassroomSnapshot($classroom->getId());
        self::assertNotNull($snap);
        $this->lookup->invalidateClassroom($classroom->getId());
        $again = $this->lookup->getClassroomSnapshot($classroom->getId());
        self::assertNotNull($again);
        self::assertTrue($snap->id->equals($again->id));
    }

    private function decide(User $user, string $attribute, \App\Entity\Classroom $classroom): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $classroom);
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
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        foreach ([
            'course_teacher_active_guards',
            'course_teacher_assignments',
            'classroom_course_active_guards',
            'classroom_courses',
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
