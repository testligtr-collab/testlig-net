<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionFailureReason;
use App\Enum\InstitutionMembershipFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
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
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regression: authorization must use DB-fresh actor/subject state, not stale in-memory entities.
 */
final class InstitutionStaleAuthorizationTest extends KernelTestCase
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
        $this->rebind();
        $this->cleanup();
    }

    public function testStaleSuperAdminRoleRemovedCannotCreateInstitution(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-create-role');
        self::assertContains(UserRole::SuperAdmin->value, $sa->getRoles());
        $this->stripSuperAdminInDb($sa);
        $stale = $this->detachKeepingMemory($sa);
        self::assertContains(UserRole::SuperAdmin->value, $stale->getRoles());

        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institutions');
        try {
            $this->creator()->create($stale, $owner, 'Stale Role School', InstitutionType::School, 'platform_setup');
            self::fail('Expected unauthorized');
        } catch (InstitutionOperationException $e) {
            self::assertSame(InstitutionFailureReason::Unauthorized, $e->getReason());
        }
        self::assertSame($before, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institutions'));
        self::assertSame(0, $this->events->countByAction(SecurityAuditAction::InstitutionCreated->value));
    }

    public function testStaleSuperAdminSuspendedCannotCreateInstitution(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-create-susp');
        $this->setUserStatusInDb($sa, UserStatus::Suspended);
        $stale = $this->detachKeepingMemory($sa);
        self::assertSame(UserStatus::Active, $stale->getStatus());

        try {
            $this->creator()->create($stale, $owner, 'Stale Susp School', InstitutionType::School, 'platform_setup');
            self::fail('Expected unauthorized');
        } catch (InstitutionOperationException $e) {
            self::assertSame(InstitutionFailureReason::Unauthorized, $e->getReason());
        }
        self::assertNull($this->institutions->findOneBySlug('stale-susp-school'));
    }

    public function testStaleOwnerSuspendedRollsBackInstitutionCreate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-owner-susp');
        $this->setUserStatusInDb($owner, UserStatus::Suspended);
        $staleOwner = $this->detachKeepingMemory($owner);
        self::assertSame(UserStatus::Active, $staleOwner->getStatus());

        try {
            $this->creator()->create($sa, $staleOwner, 'Owner Susp School', InstitutionType::School, 'platform_setup');
            self::fail('Expected invalid input');
        } catch (InstitutionOperationException $e) {
            self::assertSame(InstitutionFailureReason::InvalidInput, $e->getReason());
        }
        self::assertNull($this->institutions->findOneBySlug('owner-susp-school'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institution_memberships'));
        self::assertSame(0, $this->events->countByAction(SecurityAuditAction::InstitutionCreated->value));
    }

    public function testStaleSuperAdminRoleRemovedCannotChangeStatus(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-status-role');
        $institution = $this->creator()->create($sa, $owner, 'Status Role School', InstitutionType::School, 'platform_setup');
        $this->stripSuperAdminInDb($sa);
        $stale = $this->detachKeepingMemory($sa);
        self::assertContains(UserRole::SuperAdmin->value, $stale->getRoles());

        try {
            $this->statusManager()->activate($institution, $stale, 'activate_ok');
            self::fail('Expected unauthorized');
        } catch (InstitutionOperationException $e) {
            self::assertSame(InstitutionFailureReason::Unauthorized, $e->getReason());
        }
        $this->em->clear();
        $reloaded = $this->institutions->find($institution->getId());
        self::assertNotNull($reloaded);
        self::assertSame(\App\Enum\InstitutionStatus::Pending, $reloaded->getStatus());
        self::assertSame(0, $this->events->countByAction(SecurityAuditAction::InstitutionStatusChanged->value));
    }

    public function testStaleSuperAdminSuspendedCannotChangeStatus(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-status-susp');
        $institution = $this->creator()->create($sa, $owner, 'Status Susp School', InstitutionType::School, 'platform_setup');
        $this->setUserStatusInDb($sa, UserStatus::Suspended);
        $stale = $this->detachKeepingMemory($sa);
        self::assertSame(UserStatus::Active, $stale->getStatus());

        $this->expectException(InstitutionOperationException::class);
        $this->statusManager()->activate($institution, $stale, 'activate_ok');
    }

    public function testStaleOwnerActorSuspendedCannotMutateMembership(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-actor-susp');
        $institution = $this->creator()->create($sa, $owner, 'Actor Susp School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $teacher = $this->activeUser('stale-actor-teacher@example.com');
        $this->setUserStatusInDb($owner, UserStatus::Suspended);
        $staleOwner = $this->detachKeepingMemory($owner);
        self::assertSame(UserStatus::Active, $staleOwner->getStatus());

        try {
            $this->membershipManager()->addMember($institution, $staleOwner, $teacher, InstitutionMembershipRole::Teacher, 'add_teacher');
            self::fail('Expected unauthorized');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::Unauthorized, $e->getReason());
        }
        self::assertFalse($this->memberships->hasActiveMembership($teacher, $institution));
    }

    public function testStaleActorMembershipEndedCannotMutate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-actor-ended');
        $institution = $this->creator()->create($sa, $owner, 'Actor Ended School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $manager = $this->activeUser('stale-ended-mgr@example.com');
        $teacher = $this->activeUser('stale-ended-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_mgr');
        $mgrMembership = $this->memberships->findActiveMembership($manager, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $mgrMembership);
        $this->membershipManager()->endMembership($mgrMembership, $owner, 'end_mgr');

        $staleManager = $this->detachKeepingMemory($manager);
        try {
            $this->membershipManager()->addMember($institution, $staleManager, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
            self::fail('Expected unauthorized');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleTargetRolePromotedToManagerBlocksManagerActor(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-target-role');
        $institution = $this->creator()->create($sa, $owner, 'Target Role School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $manager = $this->activeUser('stale-trg-mgr@example.com');
        $teacher = $this->activeUser('stale-trg-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_mgr');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $teacherMembership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $teacherMembership);
        self::assertSame(InstitutionMembershipRole::Teacher, $teacherMembership->getRole());

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET role = :role WHERE id = :id',
            [
                'role' => InstitutionMembershipRole::Manager->value,
                'id' => $teacherMembership->getId()->toBinary(),
            ],
        );
        $staleMembership = $this->detachKeepingMemory($teacherMembership);
        self::assertSame(InstitutionMembershipRole::Teacher, $staleMembership->getRole());

        try {
            $this->membershipManager()->changeRole($staleMembership, $manager, InstitutionMembershipRole::Staff, 'demote');
            self::fail('Expected unauthorized against fresh manager target');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleTargetUserSuspendedBlocksReactivate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-subj-susp');
        $institution = $this->creator()->create($sa, $owner, 'Subj Susp School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $teacher = $this->activeUser('stale-subj-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->membershipManager()->suspend($membership, $owner, 'suspend_tch');

        $this->setUserStatusInDb($teacher, UserStatus::Suspended);
        $staleMembership = $this->detachKeepingMemory($membership);
        self::assertSame(UserStatus::Active, $staleMembership->getUser()->getStatus());

        try {
            $this->membershipManager()->reactivate($staleMembership, $owner, 'reactivate_tch');
            self::fail('Expected invalid input');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::InvalidInput, $e->getReason());
        }
    }

    public function testStaleTargetUserArchivedBlocksChangeRole(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-subj-arch');
        $institution = $this->creator()->create($sa, $owner, 'Subj Arch School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $teacher = $this->activeUser('stale-subj-arch-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);

        $this->setUserStatusInDb($teacher, UserStatus::Archived);
        $staleMembership = $this->detachKeepingMemory($membership);
        self::assertSame(UserStatus::Active, $staleMembership->getUser()->getStatus());

        try {
            $this->membershipManager()->changeRole($staleMembership, $owner, InstitutionMembershipRole::Staff, 'to_staff');
            self::fail('Expected invalid input');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::InvalidInput, $e->getReason());
        }
    }

    public function testSuspendAndEndWorkWhenSubjectUserNotActive(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('subj-inactive-ops');
        $institution = $this->creator()->create($sa, $owner, 'Inactive Ops School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $teacher = $this->activeUser('inactive-ops-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);

        $this->setUserStatusInDb($teacher, UserStatus::Suspended);
        $membershipId = $membership->getId();
        $ownerId = $owner->getId();
        $this->em->clear();
        $membership = $this->memberships->find($membershipId);
        $owner = $this->users->find($ownerId);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        self::assertInstanceOf(User::class, $owner);
        self::assertSame(UserStatus::Suspended, $membership->getUser()->getStatus());

        $this->membershipManager()->suspend($membership, $owner, 'suspend_inactive_user');
        $this->em->clear();
        $reloaded = $this->memberships->find($membershipId);
        self::assertInstanceOf(InstitutionMembership::class, $reloaded);
        self::assertSame(InstitutionMembershipStatus::Suspended, $reloaded->getStatus());

        $owner = $this->users->find($ownerId);
        self::assertInstanceOf(User::class, $owner);
        $this->membershipManager()->endMembership($reloaded, $owner, 'end_inactive_user');
        $this->em->clear();
        $ended = $this->memberships->find($membershipId);
        self::assertInstanceOf(InstitutionMembership::class, $ended);
        self::assertSame(InstitutionMembershipStatus::Ended, $ended->getStatus());
    }

    public function testDeletedActorUserYieldsNotFoundAndRollback(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('actor-deleted');
        $institution = $this->creator()->create($sa, $owner, 'Actor Deleted School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $teacher = $this->activeUser('actor-del-tch@example.com');
        $actorId = $owner->getId();
        $staleOwner = $this->detachKeepingMemory($owner);
        $this->em->getConnection()->executeStatement('DELETE FROM institution_memberships WHERE user_id = :id', ['id' => $actorId->toBinary()]);
        $this->em->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $actorId->toBinary()]);

        try {
            $this->membershipManager()->addMember($institution, $staleOwner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
            self::fail('Expected not found');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::NotFound, $e->getReason());
            self::assertStringNotContainsString('@', $e->getMessage());
        }
        self::assertFalse($this->memberships->hasActiveMembership($teacher, $institution));
    }

    public function testFreshActorAndSubjectAppearOnAuditRelations(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('audit-fresh');
        $staleSa = $this->detachKeepingMemory($sa);
        $staleOwner = $this->detachKeepingMemory($owner);
        $institution = $this->creator()->create($staleSa, $staleOwner, 'Audit Fresh School', InstitutionType::School, 'platform_setup');
        $created = $this->events->findBy(['action' => SecurityAuditAction::InstitutionCreated], ['occurredAt' => 'DESC'], 1)[0] ?? null;
        self::assertNotNull($created);
        self::assertNotNull($created->getActorUser());
        self::assertNotNull($created->getSubjectUser());
        self::assertTrue($created->getActorUser()->getId()->equals($sa->getId()));
        self::assertTrue($created->getSubjectUser()->getId()->equals($owner->getId()));
        self::assertSame($institution->getId()->toRfc4122(), $created->getMetadata()['institution_id'] ?? null);
    }

    public function testStaleEndedMembershipCannotReactivate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('stale-ended-react');
        $institution = $this->creator()->create($sa, $owner, 'Ended React School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');
        $teacher = $this->activeUser('ended-react-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        self::assertSame(InstitutionMembershipStatus::Active, $membership->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = :status, ended_at = NOW() WHERE id = :id',
            [
                'status' => InstitutionMembershipStatus::Ended->value,
                'id' => $membership->getId()->toBinary(),
            ],
        );
        $stale = $this->detachKeepingMemory($membership);
        self::assertSame(InstitutionMembershipStatus::Active, $stale->getStatus());

        $this->expectException(InstitutionMembershipException::class);
        $this->membershipManager()->reactivate($stale, $owner, 'reactivate_stale_ended');
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

    private function stripSuperAdminInDb(User $user): void
    {
        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => UserRole::SuperAdmin->value !== $role,
        ));
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET global_roles = :roles WHERE id = :id',
            [
                'roles' => json_encode($roles, \JSON_THROW_ON_ERROR),
                'id' => $user->getId()->toBinary(),
            ],
        );
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
