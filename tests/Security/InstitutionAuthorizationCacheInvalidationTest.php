<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use App\Security\InstitutionPermission;
use App\Security\RequestScopedInstitutionAuthLookup;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use App\Service\UserGlobalRoleManager;
use App\Service\UserStatusManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Same request-scope auth lookup must refresh after successful post-commit invalidation.
 */
final class InstitutionAuthorizationCacheInvalidationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private InstitutionRepository $institutions;
    private InstitutionMembershipRepository $memberships;
    private AccessDecisionManagerInterface $access;
    private RequestScopedInstitutionAuthLookup $lookup;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testLookupAndInvalidatorAreSameRequestScopedInstance(): void
    {
        $asInterface = static::getContainer()->get(InstitutionAuthorizationCacheInvalidator::class);
        self::assertSame($this->lookup, $asInterface);
    }

    public function testInvalidateUserRefreshesCachedSuperAdminAndSuspendedState(): void
    {
        $sa = $this->activeUser('cache-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);

        $first = $this->lookup->getUserSnapshot($sa->getId());
        self::assertNotNull($first);
        self::assertTrue($first->isActiveVerifiedSuperAdmin());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET global_roles = :roles WHERE id = :id',
            [
                'roles' => json_encode(['ROLE_USER', 'ROLE_STUDENT'], \JSON_THROW_ON_ERROR),
                'id' => $sa->getId()->toBinary(),
            ],
        );
        $cached = $this->lookup->getUserSnapshot($sa->getId());
        self::assertNotNull($cached);
        self::assertTrue($cached->isSuperAdmin(), 'Cache still holds first snapshot before invalidation');

        $this->lookup->invalidateUser($sa->getId());
        $after = $this->lookup->getUserSnapshot($sa->getId());
        self::assertNotNull($after);
        self::assertFalse($after->isSuperAdmin());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = :status WHERE id = :id',
            [
                'status' => UserStatus::Suspended->value,
                'id' => $sa->getId()->toBinary(),
            ],
        );
        $this->lookup->invalidateUser($sa->getId());
        [$institution] = $this->activeInstitutionWithOwner('cache-susp-voter');
        self::assertFalse($this->decide($sa, InstitutionPermission::MANAGE, $institution));
    }

    public function testInstitutionStatusManagerInvalidatesCachedInstitutionForVoter(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('cache-inst-status');
        self::assertTrue($this->decide($owner, InstitutionPermission::VIEW, $institution));
        $snap = $this->lookup->getInstitutionSnapshot($institution->getId());
        self::assertNotNull($snap);
        self::assertTrue($snap->isActive());

        $this->statusManager()->suspend($institution, $sa, 'suspend_ok');
        self::assertFalse($this->decide($owner, InstitutionPermission::VIEW, $institution));
    }

    public function testMembershipMutationsInvalidateCachedMembershipSnapshots(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('cache-member');
        $teacher = $this->activeUser('cache-member-tch@example.com');

        self::assertNull($this->lookup->getMembershipSnapshot($teacher->getId(), $institution->getId()));
        // Re-read would hit cached null without invalidation after addMember.
        self::assertNull($this->lookup->getMembershipSnapshot($teacher->getId(), $institution->getId()));

        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $afterAdd = $this->lookup->getMembershipSnapshot($teacher->getId(), $institution->getId());
        self::assertInstanceOf(\App\Security\Authorization\MembershipAuthorizationSnapshot::class, $afterAdd);
        self::assertTrue($afterAdd->isActive());
        self::assertTrue($this->decide($teacher, InstitutionPermission::VIEW, $institution));

        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->membershipManager()->changeRole($membership, $owner, InstitutionMembershipRole::Staff, 'to_staff');
        self::assertFalse($this->decide($teacher, InstitutionPermission::MEMBERS_VIEW, $institution));
        self::assertTrue($this->decide($teacher, InstitutionPermission::VIEW, $institution));

        $membership = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $this->membershipManager()->suspend($membership, $owner, 'suspend_tch');
        self::assertFalse($this->decide($teacher, InstitutionPermission::VIEW, $institution));

        // Reactivate then end
        $this->membershipManager()->reactivate($membership, $owner, 'reactivate_tch');
        self::assertTrue($this->decide($teacher, InstitutionPermission::VIEW, $institution));
        $this->membershipManager()->endMembership($membership, $owner, 'end_tch');
        self::assertFalse($this->decide($teacher, InstitutionPermission::VIEW, $institution));
        unset($sa);
    }

    public function testRollbackDoesNotInvalidateOnFailedMembershipMutation(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('cache-rollback');
        $teacher = $this->activeUser('cache-rollback-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        self::assertTrue($this->decide($teacher, InstitutionPermission::VIEW, $institution));

        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');
        try {
            $membership = $this->memberships->findActiveMembership($teacher, $institution);
            self::assertInstanceOf(InstitutionMembership::class, $membership);
            try {
                $this->membershipManager()->suspend($membership, $owner, 'suspend_fail');
                self::fail('Expected audit failure');
            } catch (\Throwable) {
            }
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
            self::ensureKernelShutdown();
            self::bootKernel();
            $this->rebind();
        }

        $reloadedInstitution = $this->institutions->find($institution->getId());
        self::assertInstanceOf(Institution::class, $reloadedInstitution);
        $reloadedTeacher = $this->users->find($teacher->getId());
        self::assertInstanceOf(User::class, $reloadedTeacher);
        self::assertTrue($this->decide($reloadedTeacher, InstitutionPermission::VIEW, $reloadedInstitution));
        unset($sa, $owner);
    }

    public function testCrossTenantMembershipInvalidationIsScoped(): void
    {
        [$sa, $owner, $institutionA] = $this->activeInstitutionBundle('cache-a');
        $institutionB = $this->creator()->create($sa, $owner, 'cache-b School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institutionB, $sa, 'activate_b');
        $teacher = $this->activeUser('cache-cross-tch@example.com');
        $this->membershipManager()->addMember($institutionA, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_a');
        $this->membershipManager()->addMember($institutionB, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_b');

        self::assertTrue($this->decide($teacher, InstitutionPermission::VIEW, $institutionA));
        self::assertTrue($this->decide($teacher, InstitutionPermission::VIEW, $institutionB));

        $membershipA = $this->memberships->findActiveMembership($teacher, $institutionA);
        self::assertInstanceOf(InstitutionMembership::class, $membershipA);
        $this->membershipManager()->endMembership($membershipA, $owner, 'end_a');

        self::assertFalse($this->decide($teacher, InstitutionPermission::VIEW, $institutionA));
        self::assertTrue($this->decide($teacher, InstitutionPermission::VIEW, $institutionB));
    }

    public function testUserGlobalRoleManagerInvalidatesSuperAdminOverride(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('cache-role-mgr');
        self::assertTrue($this->decide($sa, InstitutionPermission::MANAGE, $institution));

        // replaceRoles replaces the full list and may drop SUPER_ADMIN when not re-listed.
        $this->roleManager()->replaceRoles($sa, [UserRole::Student], $sa, 'strip_super_admin_for_test');
        self::assertFalse($this->decide($sa, InstitutionPermission::MANAGE, $institution));
        unset($owner);
    }

    public function testUserStatusManagerInvalidatesSuspendedSuperAdmin(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('cache-status-mgr');
        self::assertTrue($this->decide($sa, InstitutionPermission::VIEW, $institution));
        $this->userStatusManager()->suspend($sa, $sa, 'suspend_sa');
        self::assertFalse($this->decide($sa, InstitutionPermission::VIEW, $institution));
        unset($owner);
    }

    public function testResetClearsEntireRequestCache(): void
    {
        $user = $this->activeUser('cache-reset@example.com');
        $first = $this->lookup->getUserSnapshot($user->getId());
        self::assertNotNull($first);
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = :status WHERE id = :id',
            ['status' => UserStatus::Suspended->value, 'id' => $user->getId()->toBinary()],
        );
        self::assertSame(UserStatus::Active, $this->lookup->getUserSnapshot($user->getId())?->status);
        $this->lookup->reset();
        self::assertSame(UserStatus::Suspended, $this->lookup->getUserSnapshot($user->getId())?->status);
    }

    /**
     * @return array{0: User, 1: User, 2: Institution}
     */
    private function activeInstitutionBundle(string $prefix): array
    {
        $sa = $this->activeUser($prefix.'-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser($prefix.'-owner@example.com');
        $institution = $this->creator()->create($sa, $owner, $prefix.' School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate_ok');

        return [$sa, $owner, $institution];
    }

    /**
     * @return array{0: Institution, 1: User}
     */
    private function activeInstitutionWithOwner(string $prefix): array
    {
        [, $owner, $institution] = $this->activeInstitutionBundle($prefix);

        return [$institution, $owner];
    }

    private function decide(User $user, string $attribute, Institution $institution): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $institution);
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

    private function roleManager(): UserGlobalRoleManager
    {
        $service = static::getContainer()->get(UserGlobalRoleManager::class);
        self::assertInstanceOf(UserGlobalRoleManager::class, $service);

        return $service;
    }

    private function userStatusManager(): UserStatusManager
    {
        $service = static::getContainer()->get(UserStatusManager::class);
        self::assertInstanceOf(UserStatusManager::class, $service);

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
        $lookup = $c->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);
        $this->lookup = $lookup;
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
