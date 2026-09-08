<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InstitutionMembershipException;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionNameNormalizer;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

final class InstitutionDomainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private InstitutionRepository $institutions;
    private InstitutionMembershipRepository $memberships;
    private SecurityAuditEventRepository $events;

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
        $institutions = $c->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $institutions);
        $this->institutions = $institutions;
        $memberships = $c->get(InstitutionMembershipRepository::class);
        self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
        $this->memberships = $memberships;
        $events = $c->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;
        $this->cleanup();
    }

    public function testNameAndSlugNormalization(): void
    {
        $normalizer = static::getContainer()->get(InstitutionNameNormalizer::class);
        self::assertInstanceOf(InstitutionNameNormalizer::class, $normalizer);
        $result = $normalizer->normalize('  Test   Lisesi  ');
        self::assertSame('Test Lisesi', $result['name']);
        self::assertSame('test lisesi', $result['normalizedName']);
        self::assertSame('test-lisesi', $result['slug']);
        self::assertInstanceOf(UuidV7::class, UuidV7::fromString((new UuidV7())->toRfc4122()));
    }

    public function testDefaultsLocaleTimezoneAndPendingStatus(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-defaults');
        $creator = $this->creator();
        $institution = $creator->create($sa, $owner, 'Defaults School', InstitutionType::School, 'platform_setup');
        self::assertSame(InstitutionStatus::Pending, $institution->getStatus());
        self::assertSame('tr_TR', $institution->getLocale());
        self::assertSame('Europe/Istanbul', $institution->getTimezone());
        self::assertInstanceOf(UuidV7::class, $institution->getId());
    }

    public function testStatusTransitionsAndArchivedTerminal(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-status');
        $institution = $this->creator()->create($sa, $owner, 'Status School', InstitutionType::School, 'platform_setup');
        $statusManager = $this->statusManager();
        $statusManager->activate($institution, $sa, 'activate_ok');
        self::assertSame(InstitutionStatus::Active, $institution->getStatus());
        $statusManager->suspend($institution, $sa, 'suspend_ok');
        $statusManager->activate($institution, $sa, 'reactivate_ok');
        $statusManager->archive($institution, $sa, 'archive_ok');
        self::assertSame(InstitutionStatus::Archived, $institution->getStatus());
        $this->expectException(InstitutionOperationException::class);
        $statusManager->activate($institution, $sa, 'archive_terminal');
    }

    public function testNonSuperAdminCannotCreateInstitution(): void
    {
        $actor = $this->activeUser('actor-nosuper@example.com');
        $owner = $this->activeUser('owner-nosuper@example.com');
        $this->expectException(InstitutionOperationException::class);
        $this->creator()->create($actor, $owner, 'Nope School', InstitutionType::School, 'platform_setup');
    }

    public function testSuperAdminCreatesInstitutionAndOwnerInOneTransaction(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-create');
        $institution = $this->creator()->create($sa, $owner, 'Create School', InstitutionType::CourseCenter, 'platform_setup');
        $membership = $this->memberships->findActiveMembership($owner, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        self::assertSame(InstitutionMembershipRole::Owner, $membership->getRole());
        self::assertNotNull($membership->getJoinedAt());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::InstitutionCreated->value));
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::InstitutionMemberAdded->value));
    }

    public function testDuplicateSlugRejected(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-slug');
        $this->creator()->create($sa, $owner, 'Same Name School', InstitutionType::School, 'platform_setup');
        $owner2 = $this->activeUser('owner-slug2@example.com');
        $this->expectException(InstitutionOperationException::class);
        $this->creator()->create($sa, $owner2, 'Same Name School', InstitutionType::School, 'platform_setup');
    }

    public function testAuditFailureRollsBackInstitutionCreate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-rollback');
        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');
        try {
            try {
                $this->creator()->create($sa, $owner, 'Rollback School', InstitutionType::School, 'platform_setup');
                self::fail('Expected failure');
            } catch (\Throwable) {
            }
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
            self::ensureKernelShutdown();
            self::bootKernel();
            $this->rebind();
        }
        self::assertNull($this->institutions->findOneBySlug('rollback-school'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institution_memberships'));
    }

    public function testMembershipManagerPolicies(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-members');
        $institution = $this->creator()->create($sa, $owner, 'Members School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $managerUser = $this->activeUser('mgr@example.com');
        $teacherUser = $this->activeUser('tch@example.com');
        $staffUser = $this->activeUser('stf@example.com');
        $mgrService = $this->membershipManager();

        $mgrService->addMember($institution, $owner, $managerUser, InstitutionMembershipRole::Manager, 'add_manager');
        $mgrService->addMember($institution, $owner, $teacherUser, InstitutionMembershipRole::Teacher, 'add_teacher');
        $managerMembership = $this->memberships->findActiveMembership($managerUser, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $managerMembership);

        $mgrService->addMember($institution, $managerUser, $staffUser, InstitutionMembershipRole::Staff, 'mgr_add_staff');

        try {
            $mgrService->addMember($institution, $managerUser, $this->activeUser('mgr2@example.com'), InstitutionMembershipRole::Manager, 'mgr_add_mgr');
            self::fail('Manager cannot add manager');
        } catch (InstitutionMembershipException) {
        }

        try {
            $mgrService->changeRole($managerMembership, $managerUser, InstitutionMembershipRole::Teacher, 'self_demote');
            self::fail('Cannot manage self');
        } catch (InstitutionMembershipException) {
        }

        try {
            $mgrService->changeRole($managerMembership, $owner, InstitutionMembershipRole::Owner, 'to_owner');
            self::fail('Owner role restricted');
        } catch (InstitutionMembershipException) {
        }

        $ownerMembership = $this->memberships->findActiveMembership($owner, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $ownerMembership);
        try {
            $mgrService->suspend($ownerMembership, $sa, 'last_owner');
            self::fail('Last owner protected');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(\App\Enum\InstitutionMembershipFailureReason::LastOwnerProtected, $e->getReason());
        }
    }

    public function testSameUserDifferentInstitutionsAllowedAndScopeWorks(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-multi');
        $a = $this->creator()->create($sa, $owner, 'School A', InstitutionType::School, 'platform_setup');
        $b = $this->creator()->create($sa, $owner, 'School B', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($a, $sa, 'activate_a');
        $this->statusManager()->activate($b, $sa, 'activate_b');
        self::assertTrue($this->memberships->hasActiveMembership($owner, $a));
        self::assertTrue($this->memberships->hasActiveMembership($owner, $b));
        self::assertSame(1, $this->memberships->countActiveOwners($a));
        $foreign = $this->memberships->findActiveMembership($owner, $a);
        self::assertInstanceOf(InstitutionMembership::class, $foreign);
        self::assertTrue($foreign->getInstitution()->getId()->equals($a->getId()));
    }

    public function testArchivedInstitutionBlocksMembershipOps(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-arch');
        $institution = $this->creator()->create($sa, $owner, 'Arch School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $this->statusManager()->archive($institution, $sa, 'archive_ok');
        $this->expectException(InstitutionMembershipException::class);
        $this->membershipManager()->addMember($institution, $owner, $this->activeUser('late@example.com'), InstitutionMembershipRole::Teacher, 'too_late');
    }

    public function testEndedMembershipCannotReactivate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('inst-ended');
        $institution = $this->creator()->create($sa, $owner, 'Ended School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $teacher = $this->activeUser('end-teacher@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_teacher');
        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->membershipManager()->endMembership($membership, $owner, 'end_teacher');
        $this->expectException(InstitutionMembershipException::class);
        $this->membershipManager()->reactivate($membership, $owner, 'reactivate_ended');
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function superAdminAndOwner(string $prefix): array
    {
        $sa = $this->activeUser($prefix.'-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser($prefix.'-owner@example.com');

        return [$sa, $owner];
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

    private function rebind(): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $institutions = $c->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $institutions);
        $this->institutions = $institutions;
        $memberships = $c->get(InstitutionMembershipRepository::class);
        self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
        $this->memberships = $memberships;
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $events = $c->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        $schema = $connection->createSchemaManager();
        if ($schema->tablesExist(['security_audit_events_bak']) && !$schema->tablesExist(['security_audit_events'])) {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
        }
        foreach (['institution_memberships', 'institutions', 'security_audit_events', 'security_bootstrap_guards', 'reset_password_requests', 'users'] as $table) {
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
