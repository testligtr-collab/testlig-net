<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearFailureReason;
use App\Enum\ClassroomFailureReason;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AcademicYearException;
use App\Exception\ClassroomException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Managed-stale actor/membership must not authorize academic classroom mutations.
 */
final class AcademicClassroomStaleAuthorizationTest extends KernelTestCase
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

    public function testStaleSuspendedOwnerCannotCreateAcademicYear(): void
    {
        [$owner, $sa, $institution] = $this->activeOwnerInstitution('stale-ay');
        $this->setUserStatusInDb($owner, UserStatus::Suspended);
        $stale = $this->detachKeepingMemory($owner);
        self::assertSame(UserStatus::Active, $stale->getStatus());

        try {
            $this->yearManager()->createPlanned(
                $institution,
                $stale,
                'Stale Year',
                new \DateTimeImmutable('2024-09-01'),
                new \DateTimeImmutable('2025-06-15'),
                'stale',
            );
            self::fail('Expected unauthorized');
        } catch (AcademicYearException $e) {
            self::assertSame(AcademicYearFailureReason::Unauthorized, $e->getReason());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM academic_years'));
        unset($sa);
    }

    public function testStaleDemotedManagerCannotCreateClassroom(): void
    {
        [$owner, $sa, $institution] = $this->activeOwnerInstitution('stale-cls');
        $manager = $this->activeUser('stale-cls-mgr@example.com');
        $this->membershipManager()->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_mgr');
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            'Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'create_year',
        );

        $membership = $this->memberships->findActiveMembership($manager, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->setMembershipRoleInDb($membership, InstitutionMembershipRole::Staff);
        // Managed membership still Manager in memory (no detach/clear).
        self::assertSame(InstitutionMembershipRole::Manager, $membership->getRole());
        $staleManager = $this->detachKeepingMemory($manager);

        try {
            $this->classroomManager()->create($year, $staleManager, 'Blocked', GradeLevel::Grade5, 'nope');
            self::fail('Expected unauthorized');
        } catch (ClassroomException $e) {
            self::assertSame(ClassroomFailureReason::Unauthorized, $e->getReason());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM classrooms'));
        unset($sa);
    }

    public function testStaleEndedMembershipCannotManageYear(): void
    {
        [$owner, $sa, $institution] = $this->activeOwnerInstitution('stale-end');
        $manager = $this->activeUser('stale-end-mgr@example.com');
        $this->membershipManager()->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_mgr');
        $membership = $this->memberships->findActiveMembership($manager, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->setMembershipStatusInDb($membership, InstitutionMembershipStatus::Ended);
        self::assertSame(InstitutionMembershipStatus::Active, $membership->getStatus());
        $staleManager = $this->detachKeepingMemory($manager);

        try {
            $this->yearManager()->createPlanned(
                $institution,
                $staleManager,
                'Ended Actor Year',
                new \DateTimeImmutable('2024-09-01'),
                new \DateTimeImmutable('2025-06-15'),
                'ended',
            );
            self::fail('Expected unauthorized');
        } catch (AcademicYearException $e) {
            self::assertSame(AcademicYearFailureReason::Unauthorized, $e->getReason());
        }
        unset($sa);
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

    private function activeUser(string $email): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    /**
     * Keep managed entity in memory with stale state; DB already updated out-of-band.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function detachKeepingMemory(object $entity): object
    {
        // Intentionally do NOT clear/detach — identity map retains stale values.
        return $entity;
    }

    private function setUserStatusInDb(User $user, UserStatus $status): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = :status WHERE id = :id',
            ['status' => $status->value, 'id' => $user->getId()->toBinary()],
        );
    }

    private function setMembershipRoleInDb(InstitutionMembership $membership, InstitutionMembershipRole $role): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET role = :role WHERE id = :id',
            ['role' => $role->value, 'id' => $membership->getId()->toBinary()],
        );
    }

    private function setMembershipStatusInDb(InstitutionMembership $membership, InstitutionMembershipStatus $status): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = :status WHERE id = :id',
            ['status' => $status->value, 'id' => $membership->getId()->toBinary()],
        );
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

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('UPDATE curriculum_topics SET parent_id = NULL');
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
