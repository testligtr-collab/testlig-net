<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionFailureReason;
use App\Enum\InstitutionMembershipFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InstitutionMembershipException;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Security\InstitutionPermission;
use App\Security\RequestScopedInstitutionAuthLookup;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Identity-map regression: managed entities stay stale in memory after out-of-band DBAL updates.
 * Services/voter must still authorize from the database (HINT_REFRESH / DBAL snapshots).
 *
 * Intentionally does NOT call EntityManager::clear() or detach() around the assertions under test.
 */
final class InstitutionIdentityMapAuthorizationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private InstitutionRepository $institutions;
    private InstitutionMembershipRepository $memberships;
    private AccessDecisionManagerInterface $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testManagedStaleSuperAdminRoleRemovedDeniesInstitutionCreate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('imap-create-role');
        self::assertContains(UserRole::SuperAdmin->value, $sa->getRoles());
        self::assertTrue($this->em->contains($sa));

        $this->stripSuperAdminInDb($sa);

        self::assertContains(
            UserRole::SuperAdmin->value,
            $sa->getRoles(),
            'Precondition: managed User still shows SUPER_ADMIN after DBAL role strip',
        );
        self::assertFalse(
            str_contains((string) $this->em->getConnection()->fetchOne(
                'SELECT global_roles FROM users WHERE id = :id',
                ['id' => $sa->getId()->toBinary()],
            ), UserRole::SuperAdmin->value),
            'Precondition: DB no longer contains SUPER_ADMIN',
        );

        try {
            $this->creator()->create($sa, $owner, 'IMap Role School', InstitutionType::School, 'platform_setup');
            self::fail('Expected unauthorized against DB-fresh roles');
        } catch (InstitutionOperationException $e) {
            self::assertSame(InstitutionFailureReason::Unauthorized, $e->getReason());
        }
        self::assertNull($this->institutions->findOneBySlug('imap-role-school'));
    }

    public function testManagedStaleSuspendedActorDeniesInstitutionCreate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('imap-create-susp');
        $this->setUserStatusInDb($sa, UserStatus::Suspended);

        self::assertSame(UserStatus::Active, $sa->getStatus(), 'Precondition: managed User still Active');
        self::assertSame(
            UserStatus::Suspended->value,
            (string) $this->em->getConnection()->fetchOne(
                'SELECT status FROM users WHERE id = :id',
                ['id' => $sa->getId()->toBinary()],
            ),
            'Precondition: DB status is suspended',
        );

        $this->expectException(InstitutionOperationException::class);
        $this->creator()->create($sa, $owner, 'IMap Susp School', InstitutionType::School, 'platform_setup');
    }

    public function testManagedStaleUnverifiedActorDeniesInstitutionCreate(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('imap-create-unv');
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = :id',
            ['id' => $sa->getId()->toBinary()],
        );
        self::assertNotNull($sa->getEmailVerifiedAt(), 'Precondition: managed User still verified');

        $this->expectException(InstitutionOperationException::class);
        $this->creator()->create($sa, $owner, 'IMap Unv School', InstitutionType::School, 'platform_setup');
    }

    public function testManagedStaleArchivedActorDeniesMembershipMutation(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-arch-actor');
        $teacher = $this->activeUser('imap-arch-tch@example.com');
        $this->setUserStatusInDb($owner, UserStatus::Archived);
        self::assertSame(UserStatus::Active, $owner->getStatus(), 'Precondition: managed owner still Active');

        try {
            $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
            self::fail('Expected unauthorized');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testManagedStaleArchivedInstitutionBlocksMembershipOps(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-arch-inst');
        $teacher = $this->activeUser('imap-arch-inst-tch@example.com');
        $this->setInstitutionStatusInDb($institution, InstitutionStatus::Archived);

        self::assertSame(InstitutionStatus::Active, $institution->getStatus(), 'Precondition: managed Institution still Active');
        self::assertSame(
            InstitutionStatus::Archived->value,
            (string) $this->em->getConnection()->fetchOne(
                'SELECT status FROM institutions WHERE id = :id',
                ['id' => $institution->getId()->toBinary()],
            ),
        );

        try {
            $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
            self::fail('Expected institution not operable');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::InstitutionNotOperable, $e->getReason());
        }
    }

    public function testManagedStaleSuspendedInstitutionBlocksMembershipOps(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-susp-inst');
        $this->setInstitutionStatusInDb($institution, InstitutionStatus::Suspended);
        self::assertSame(InstitutionStatus::Active, $institution->getStatus());

        $this->expectException(InstitutionMembershipException::class);
        $this->membershipManager()->addMember(
            $institution,
            $owner,
            $this->activeUser('imap-susp-inst-tch@example.com'),
            InstitutionMembershipRole::Teacher,
            'add_tch',
        );
    }

    public function testStatusManagerUsesDbFreshInstitutionTransitionState(): void
    {
        [$sa, $owner] = $this->superAdminAndOwner('imap-status-term');
        $institution = $this->creator()->create($sa, $owner, 'IMap Status School', InstitutionType::School, 'platform_setup');
        self::assertSame(InstitutionStatus::Pending, $institution->getStatus());

        $this->setInstitutionStatusInDb($institution, InstitutionStatus::Archived);
        self::assertSame(InstitutionStatus::Pending, $institution->getStatus(), 'Precondition: managed still Pending');

        try {
            $this->statusManager()->activate($institution, $sa, 'activate_stale');
            self::fail('Archived→Active must be rejected from DB-fresh status');
        } catch (InstitutionOperationException $e) {
            self::assertSame(InstitutionFailureReason::InvalidTransition, $e->getReason());
        }
    }

    public function testManagedStaleEndedActorMembershipDeniesManagement(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-actor-ended');
        $manager = $this->activeUser('imap-actor-ended-mgr@example.com');
        $teacher = $this->activeUser('imap-actor-ended-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_mgr');
        $mgrMembership = $this->memberships->findActiveMembership($manager, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $mgrMembership);

        $this->setMembershipStatusInDb($mgrMembership, InstitutionMembershipStatus::Ended);
        self::assertSame(InstitutionMembershipStatus::Active, $mgrMembership->getStatus(), 'Precondition: managed still Active');

        try {
            $this->membershipManager()->addMember($institution, $manager, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
            self::fail('Expected unauthorized');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testManagedStaleSuspendedActorMembershipDeniesManagement(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-actor-susp');
        $manager = $this->activeUser('imap-actor-susp-mgr@example.com');
        $this->membershipManager()->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_mgr');
        $mgrMembership = $this->memberships->findActiveMembership($manager, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $mgrMembership);
        $this->setMembershipStatusInDb($mgrMembership, InstitutionMembershipStatus::Suspended);
        self::assertSame(InstitutionMembershipStatus::Active, $mgrMembership->getStatus());

        $this->expectException(InstitutionMembershipException::class);
        $this->membershipManager()->addMember(
            $institution,
            $manager,
            $this->activeUser('imap-actor-susp-tch@example.com'),
            InstitutionMembershipRole::Teacher,
            'add_tch',
        );
    }

    public function testManagedStaleActorRoleDemotedFromOwnerDeniesManagement(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-actor-demote');
        $secondOwner = $this->activeUser('imap-second-owner@example.com');
        $this->membershipManager()->addMember($institution, $sa, $secondOwner, InstitutionMembershipRole::Manager, 'tmp');
        // Promote second via DB then demote original owner role in DB while managed stays Owner.
        $ownerMembership = $this->memberships->findActiveMembership($owner, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $ownerMembership);
        $this->setMembershipRoleInDb($ownerMembership, InstitutionMembershipRole::Teacher);
        self::assertSame(InstitutionMembershipRole::Owner, $ownerMembership->getRole(), 'Precondition: managed still Owner');

        try {
            $this->membershipManager()->addMember(
                $institution,
                $owner,
                $this->activeUser('imap-demote-tch@example.com'),
                InstitutionMembershipRole::Teacher,
                'add_tch',
            );
            self::fail('Expected unauthorized for DB-fresh teacher actor');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testManagedStaleTargetPromotedToManagerBlocksManagerActor(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-tgt-mgr');
        $manager = $this->activeUser('imap-tgt-mgr-a@example.com');
        $teacher = $this->activeUser('imap-tgt-mgr-b@example.com');
        $this->membershipManager()->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_mgr');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $target = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $target);

        $this->setMembershipRoleInDb($target, InstitutionMembershipRole::Manager);
        self::assertSame(InstitutionMembershipRole::Teacher, $target->getRole(), 'Precondition: managed still Teacher');

        try {
            $this->membershipManager()->changeRole($target, $manager, InstitutionMembershipRole::Staff, 'demote');
            self::fail('Expected unauthorized');
        } catch (InstitutionMembershipException $e) {
            self::assertSame(InstitutionMembershipFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testManagedStaleEndedTargetCannotReactivate(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-tgt-ended');
        $teacher = $this->activeUser('imap-tgt-ended-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $target = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $target);

        $this->setMembershipStatusInDb($target, InstitutionMembershipStatus::Ended);
        self::assertSame(InstitutionMembershipStatus::Active, $target->getStatus(), 'Precondition: managed still Active');

        $this->expectException(InstitutionMembershipException::class);
        $this->membershipManager()->reactivate($target, $owner, 'reactivate_stale_ended');
    }

    public function testVoterDeniesManagedStaleSuspendedTokenUser(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-voter-susp');
        $this->setUserStatusInDb($owner, UserStatus::Suspended);
        self::assertSame(UserStatus::Active, $owner->getStatus(), 'Precondition: managed token User still Active');

        self::assertFalse($this->decide($owner, InstitutionPermission::VIEW, $institution));
    }

    public function testVoterDeniesManagedStaleSuperAdminWithoutDbRole(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-voter-sa');
        $this->stripSuperAdminInDb($sa);
        self::assertContains(UserRole::SuperAdmin->value, $sa->getRoles(), 'Precondition: managed still SUPER_ADMIN');

        self::assertFalse($this->decide($sa, InstitutionPermission::MANAGE, $institution));
    }

    public function testVoterDeniesManagedStaleEndedMembership(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-voter-ended');
        $teacher = $this->activeUser('imap-voter-ended-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->setMembershipStatusInDb($membership, InstitutionMembershipStatus::Ended);
        self::assertSame(InstitutionMembershipStatus::Active, $membership->getStatus());

        self::assertFalse($this->decide($teacher, InstitutionPermission::VIEW, $institution));
    }

    public function testVoterDeniesManagedStaleOwnerDemotedToStaff(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-voter-staff');
        unset($sa);
        $membership = $this->memberships->findActiveMembership($owner, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->setMembershipRoleInDb($membership, InstitutionMembershipRole::Staff);
        self::assertSame(InstitutionMembershipRole::Owner, $membership->getRole());

        self::assertFalse($this->decide($owner, InstitutionPermission::MANAGE, $institution));
        self::assertTrue($this->decide($owner, InstitutionPermission::VIEW, $institution));
    }

    public function testVoterDeniesManagedStaleArchivedInstitutionForMembers(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-voter-arch');
        $this->setInstitutionStatusInDb($institution, InstitutionStatus::Archived);
        self::assertSame(InstitutionStatus::Active, $institution->getStatus(), 'Precondition: managed Institution still Active');

        self::assertFalse($this->decide($owner, InstitutionPermission::VIEW, $institution));
        self::assertTrue($this->decide($sa, InstitutionPermission::VIEW, $institution));
    }

    public function testAuthLookupRequestCacheAndReset(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('imap-cache');
        $lookup = static::getContainer()->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);

        $first = $lookup->getUserSnapshot($owner->getId());
        self::assertNotNull($first);
        self::assertTrue($first->isActiveAndVerified());

        $this->setUserStatusInDb($owner, UserStatus::Suspended);
        $cached = $lookup->getUserSnapshot($owner->getId());
        self::assertNotNull($cached);
        self::assertSame(UserStatus::Active, $cached->status, 'Same request cache keeps first DB snapshot');

        $lookup->reset();
        $afterReset = $lookup->getUserSnapshot($owner->getId());
        self::assertNotNull($afterReset);
        self::assertSame(UserStatus::Suspended, $afterReset->status);
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

    /**
     * @return array{0: User, 1: User, 2: Institution}
     */
    private function activeInstitutionBundle(string $prefix): array
    {
        [$sa, $owner] = $this->superAdminAndOwner($prefix);
        $institution = $this->creator()->create($sa, $owner, $prefix.' School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');

        return [$sa, $owner, $institution];
    }

    private function activeUser(string $email): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function decide(User $user, string $attribute, Institution $institution): bool
    {
        $this->resetAuthLookup();
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $institution);
    }

    private function resetAuthLookup(): void
    {
        $lookup = static::getContainer()->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);
        $lookup->reset();
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

    private function setInstitutionStatusInDb(Institution $institution, InstitutionStatus $status): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE institutions SET status = :status WHERE id = :id',
            [
                'status' => $status->value,
                'id' => $institution->getId()->toBinary(),
            ],
        );
    }

    private function setMembershipStatusInDb(InstitutionMembership $membership, InstitutionMembershipStatus $status): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = :status WHERE id = :id',
            [
                'status' => $status->value,
                'id' => $membership->getId()->toBinary(),
            ],
        );
    }

    private function setMembershipRoleInDb(InstitutionMembership $membership, InstitutionMembershipRole $role): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET role = :role WHERE id = :id',
            [
                'role' => $role->value,
                'id' => $membership->getId()->toBinary(),
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
        $access = $c->get(AccessDecisionManagerInterface::class);
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $access);
        $this->access = $access;
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
