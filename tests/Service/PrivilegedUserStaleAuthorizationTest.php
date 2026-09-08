<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Institution;
use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\UserManagementFailureReason;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use App\Security\InstitutionPermission;
use App\Security\RequestScopedInstitutionAuthLookup;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Service\UserGlobalRoleManager;
use App\Service\UserStatusManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Privileged user mutations must authorize from HINT_REFRESH locked DB state.
 *
 * Intentionally does NOT call EntityManager::clear() or detach() around the
 * assertions under test — managed entities stay stale after out-of-band DBAL updates.
 */
final class PrivilegedUserStaleAuthorizationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private AccessDecisionManagerInterface $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testManagedAdminActorWithRolesStrippedInDbCannotChangeRoles(): void
    {
        $actor = $this->activeAdmin('stale-role-actor@example.com');
        $target = $this->activeUser('stale-role-target@example.com', UserRole::Student);
        self::assertTrue($this->em->contains($actor));

        $this->setGlobalRolesInDb($actor, [UserRole::Moderator->value]);
        self::assertContains(UserRole::Admin->value, $actor->getRoles(), 'Precondition: managed still ADMIN');
        self::assertFalse(
            str_contains((string) $this->dbRolesJson($actor), UserRole::Admin->value),
            'Precondition: DB no longer ADMIN',
        );

        try {
            $this->roleManager()->addRole($target, UserRole::Teacher, $actor, 'stale_deny');
            self::fail('Expected actor_not_authorized');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ActorNotAuthorized, $e->getReason());
        }
        self::assertNotContains(UserRole::Teacher->value, $this->dbRolesList($target));
    }

    public function testManagedSuperAdminActorSuspendedInDbCannotChangeRoles(): void
    {
        $actor = $this->activeSuperAdmin('stale-sa-susp@example.com');
        $target = $this->activeUser('stale-sa-susp-tgt@example.com', UserRole::Student);
        $this->setUserStatusInDb($actor, UserStatus::Suspended);
        self::assertSame(UserStatus::Active, $actor->getStatus());

        try {
            $this->roleManager()->addRole($target, UserRole::Teacher, $actor, 'stale_susp');
            self::fail('Expected actor_not_active');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ActorNotActive, $e->getReason());
        }
    }

    public function testManagedVerifiedActorUnverifiedInDbCannotChangeRoles(): void
    {
        $actor = $this->activeAdmin('stale-unv-actor@example.com');
        $target = $this->activeUser('stale-unv-tgt@example.com', UserRole::Student);
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = :id',
            ['id' => $actor->getId()->toBinary()],
        );
        self::assertNotNull($actor->getEmailVerifiedAt());

        try {
            $this->roleManager()->addRole($target, UserRole::Teacher, $actor, 'stale_unv');
            self::fail('Expected actor_not_verified');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ActorNotVerified, $e->getReason());
        }
    }

    public function testManagedNormalTargetPromotedToSuperAdminInDbDeniesRoleMutation(): void
    {
        $actor = $this->activeSuperAdmin('stale-tgt-sa-actor@example.com');
        $target = $this->activeUser('stale-tgt-sa@example.com', UserRole::Student);
        $this->setGlobalRolesInDb($target, [UserRole::SuperAdmin->value]);
        self::assertNotContains(UserRole::SuperAdmin->value, $target->getRoles());

        foreach ([
            fn () => $this->roleManager()->replaceRoles($target, [UserRole::Teacher], $actor, 'deny_replace'),
            fn () => $this->roleManager()->addRole($target, UserRole::Teacher, $actor, 'deny_add'),
            fn () => $this->roleManager()->removeRole($target, UserRole::Student, $actor, 'deny_remove'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected protected_super_admin');
            } catch (InvalidUserTransitionException $e) {
                self::assertSame(UserManagementFailureReason::ProtectedSuperAdmin, $e->getReason());
            }
        }
    }

    public function testExistingSuperAdminCannotBeStrippedViaReplaceRoles(): void
    {
        $actor = $this->activeSuperAdmin('strip-sa-actor@example.com');
        $target = $this->activeSuperAdmin('strip-sa-target@example.com');

        try {
            $this->roleManager()->replaceRoles($target, [UserRole::Student], $actor, 'strip_sa');
            self::fail('Expected protected_super_admin');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ProtectedSuperAdmin, $e->getReason());
        }
        self::assertContains(UserRole::SuperAdmin->value, $this->dbRolesList($target));
    }

    public function testAdminCannotChangeAnotherAdminRoles(): void
    {
        $actor = $this->activeAdmin('admin-vs-admin-a@example.com');
        $target = $this->activeAdmin('admin-vs-admin-b@example.com');

        try {
            $this->roleManager()->addRole($target, UserRole::Teacher, $actor, 'peer_admin');
            self::fail('Expected target_privilege_too_high');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::TargetPrivilegeTooHigh, $e->getReason());
        }
    }

    public function testAdminCannotChangeSuperAdminRoles(): void
    {
        $actor = $this->activeAdmin('admin-vs-sa@example.com');
        $target = $this->activeSuperAdmin('admin-vs-sa-tgt@example.com');

        try {
            $this->roleManager()->addRole($target, UserRole::Teacher, $actor, 'admin_on_sa');
            self::fail('Expected protected_super_admin');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ProtectedSuperAdmin, $e->getReason());
        }
    }

    public function testAdminCannotAssignAdminRole(): void
    {
        $actor = $this->activeAdmin('admin-assign-admin@example.com');
        $target = $this->activeUser('admin-assign-tgt@example.com', UserRole::Student);

        try {
            $this->roleManager()->addRole($target, UserRole::Admin, $actor, 'elevate');
            self::fail('Expected actor_not_authorized');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ActorNotAuthorized, $e->getReason());
        }
    }

    public function testActorCannotChangeOwnRoles(): void
    {
        $actor = $this->activeAdmin('self-role@example.com');

        try {
            $this->roleManager()->addRole($actor, UserRole::Teacher, $actor, 'self');
            self::fail('Expected self_management_forbidden');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::SelfManagementForbidden, $e->getReason());
        }
    }

    public function testFreshSuperAdminCanChangeNormalTargetAllowedRole(): void
    {
        $actor = $this->activeSuperAdmin('fresh-sa-ok@example.com');
        $target = $this->activeUser('fresh-sa-ok-tgt@example.com', UserRole::Student);

        $this->roleManager()->addRole($target, UserRole::Teacher, $actor, 'ok_promote');
        self::assertContains(UserRole::Teacher->value, $this->dbRolesList($target));
        self::assertContains(UserRole::Teacher->value, $target->getRoles());
    }

    public function testRoleAuditFailureRollsBackRoles(): void
    {
        $actor = $this->activeSuperAdmin('role-audit-fail-a@example.com');
        $target = $this->activeUser('role-audit-fail-t@example.com', UserRole::Student);
        $before = $this->dbRolesJson($target);

        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');
        try {
            try {
                $this->roleManager()->addRole($target, UserRole::Teacher, $actor, 'fail_audit');
                self::fail('Expected audit failure');
            } catch (\Throwable) {
            }
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
            self::ensureKernelShutdown();
            self::bootKernel();
            $this->rebind();
        }

        $reloaded = $this->users->find($target->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($before, $this->dbRolesJson($reloaded));
        self::assertNotContains(UserRole::Teacher->value, $reloaded->getRoles());
    }

    public function testRoleCommitInvalidatesVoterUserSnapshot(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('role-voter');
        self::assertTrue($this->decide($owner, InstitutionPermission::VIEW, $institution));
        $lookup = $this->lookup();
        $cached = $lookup->getUserSnapshot($owner->getId());
        self::assertNotNull($cached);
        self::assertNotContains(UserRole::Moderator->value, $cached->roles);

        $this->roleManager()->addRole($owner, UserRole::Moderator, $sa, 'voter_sync');
        $fresh = $lookup->getUserSnapshot($owner->getId());
        self::assertNotNull($fresh);
        self::assertContains(UserRole::Moderator->value, $fresh->roles);
    }

    public function testManagedSuspendedAdminActorCannotChangeStatus(): void
    {
        $actor = $this->activeAdmin('stale-status-actor@example.com');
        $target = $this->activeUser('stale-status-tgt@example.com', UserRole::Student);
        $this->setUserStatusInDb($actor, UserStatus::Suspended);
        self::assertSame(UserStatus::Active, $actor->getStatus());

        try {
            $this->statusManager()->suspend($target, $actor, 'stale_status');
            self::fail('Expected actor_not_active');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ActorNotActive, $e->getReason());
        }
        self::assertSame(UserStatus::Active->value, $this->dbStatus($target));
    }

    public function testManagedSuperAdminActorWithRoleStrippedCannotChangeStatus(): void
    {
        $actor = $this->activeSuperAdmin('stale-status-sa@example.com');
        $target = $this->activeUser('stale-status-sa-tgt@example.com', UserRole::Student);
        $this->setGlobalRolesInDb($actor, [UserRole::Moderator->value]);
        self::assertContains(UserRole::SuperAdmin->value, $actor->getRoles());

        try {
            $this->statusManager()->suspend($target, $actor, 'stale_sa_role');
            self::fail('Expected actor_not_authorized');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ActorNotAuthorized, $e->getReason());
        }
    }

    public function testManagedActiveTargetArchivedInDbCannotBeOverwrittenBySuspendOrReactivate(): void
    {
        $actor = $this->activeSuperAdmin('stale-arch-actor@example.com');
        $target = $this->activeUser('stale-arch-tgt@example.com', UserRole::Student);
        $this->setUserStatusInDb($target, UserStatus::Archived);
        self::assertSame(UserStatus::Active, $target->getStatus());

        try {
            $this->statusManager()->suspend($target, $actor, 'overwrite_suspend');
            self::fail('Expected invalid transition from archived');
        } catch (InvalidUserTransitionException) {
        }
        self::assertSame(UserStatus::Archived->value, $this->dbStatus($target));

        try {
            $this->statusManager()->reactivate($target, $actor, 'overwrite_reactivate');
            self::fail('Expected invalid reactivation');
        } catch (InvalidUserTransitionException) {
        }
        self::assertSame(UserStatus::Archived->value, $this->dbStatus($target));
    }

    public function testAdminCannotSuspendAnotherAdmin(): void
    {
        $actor = $this->activeAdmin('status-admin-a@example.com');
        $target = $this->activeAdmin('status-admin-b@example.com');

        try {
            $this->statusManager()->suspend($target, $actor, 'peer');
            self::fail('Expected target_privilege_too_high');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::TargetPrivilegeTooHigh, $e->getReason());
        }
    }

    public function testAdminCannotManageSuperAdminStatus(): void
    {
        $actor = $this->activeAdmin('status-admin-sa@example.com');
        $target = $this->activeSuperAdmin('status-admin-sa-tgt@example.com');

        try {
            $this->statusManager()->suspend($target, $actor, 'sa');
            self::fail('Expected protected_super_admin');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ProtectedSuperAdmin, $e->getReason());
        }
    }

    public function testSuperAdminTargetCannotBeManagedViaStatusManager(): void
    {
        $actor = $this->activeSuperAdmin('status-sa-peer-a@example.com');
        $target = $this->activeSuperAdmin('status-sa-peer-b@example.com');

        try {
            $this->statusManager()->suspend($target, $actor, 'peer_sa');
            self::fail('Expected protected_super_admin');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::ProtectedSuperAdmin, $e->getReason());
        }
    }

    public function testActorCannotChangeOwnStatus(): void
    {
        $actor = $this->activeAdmin('self-status@example.com');

        try {
            $this->statusManager()->suspend($actor, $actor, 'self');
            self::fail('Expected self_management_forbidden');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::SelfManagementForbidden, $e->getReason());
        }
    }

    public function testFreshSuperAdminCanSuspendAdminAndReactivateSuspendedUser(): void
    {
        $actor = $this->activeSuperAdmin('status-sa-ok@example.com');
        $admin = $this->activeAdmin('status-sa-ok-admin@example.com');
        $user = $this->activeUser('status-sa-ok-user@example.com', UserRole::Student);

        $this->statusManager()->suspend($admin, $actor, 'suspend_admin');
        self::assertSame(UserStatus::Suspended->value, $this->dbStatus($admin));

        $this->statusManager()->suspend($user, $actor, 'suspend_user');
        $this->statusManager()->reactivate($user, $actor, 'reactivate_user');
        self::assertSame(UserStatus::Active->value, $this->dbStatus($user));
    }

    public function testPendingAndArchivedTargetsCannotReactivate(): void
    {
        $actor = $this->activeSuperAdmin('reactivate-deny@example.com');
        $pending = $this->factory->createAndPersist('reactivate-pending@example.com', 'Guclu-Parola-123!', 'P', 'E', UserRole::Student);
        $archived = $this->activeUser('reactivate-arch@example.com', UserRole::Student);
        $archived->transitionTo(UserStatus::Archived);
        $this->users->save($archived);

        foreach ([$pending, $archived] as $target) {
            try {
                $this->statusManager()->reactivate($target, $actor, 'nope');
                self::fail('Expected invalid reactivation');
            } catch (InvalidUserTransitionException) {
            }
        }
    }

    public function testStatusAuditFailureRollsBackStatus(): void
    {
        $actor = $this->activeSuperAdmin('status-audit-a@example.com');
        $target = $this->activeUser('status-audit-t@example.com', UserRole::Student);

        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');
        try {
            try {
                $this->statusManager()->suspend($target, $actor, 'fail_audit');
                self::fail('Expected audit failure');
            } catch (\Throwable) {
            }
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
            self::ensureKernelShutdown();
            self::bootKernel();
            $this->rebind();
        }

        $reloaded = $this->users->find($target->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatus::Active, $reloaded->getStatus());
    }

    public function testStatusCommitInvalidatesVoterSnapshot(): void
    {
        [$sa, $owner, $institution] = $this->activeInstitutionBundle('status-voter');
        self::assertTrue($this->decide($owner, InstitutionPermission::VIEW, $institution));
        $this->statusManager()->suspend($owner, $sa, 'voter_sync');
        self::assertFalse($this->decide($owner, InstitutionPermission::VIEW, $institution));
    }

    public function testLifecycleDeniesActivationWhenDbSuspendedDespiteManagedPending(): void
    {
        $user = $this->factory->createAndPersist('life-susp@example.com', 'Guclu-Parola-123!', 'L', 'S', UserRole::Student);
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        $this->setUserStatusInDb($user, UserStatus::Suspended);

        try {
            $this->lifecycle()->markEmailVerifiedAndActivate($user);
            self::fail('Expected invalid transition');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::InvalidTransition, $e->getReason());
        }
        self::assertSame(UserStatus::Suspended->value, $this->dbStatus($user));
        self::assertNull($this->dbVerifiedAt($user));
    }

    public function testLifecycleDeniesActivationWhenDbArchivedDespiteManagedPending(): void
    {
        $user = $this->factory->createAndPersist('life-arch@example.com', 'Guclu-Parola-123!', 'L', 'A', UserRole::Student);
        $this->setUserStatusInDb($user, UserStatus::Archived);

        try {
            $this->lifecycle()->markEmailVerifiedAndActivate($user);
            self::fail('Expected invalid transition');
        } catch (InvalidUserTransitionException $e) {
            self::assertSame(UserManagementFailureReason::InvalidTransition, $e->getReason());
        }
        self::assertSame(UserStatus::Archived->value, $this->dbStatus($user));
    }

    public function testLifecycleDoesNotEarlyReturnOnStaleManagedActiveVerified(): void
    {
        $user = $this->activeUser('life-stale-early@example.com', UserRole::Student);
        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertNotNull($user->getEmailVerifiedAt());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = :status, email_verified_at = NULL WHERE id = :id',
            [
                'status' => UserStatus::PendingVerification->value,
                'id' => $user->getId()->toBinary(),
            ],
        );

        $this->lifecycle()->markEmailVerifiedAndActivate($user);
        self::assertSame(UserStatus::Active->value, $this->dbStatus($user));
        self::assertNotNull($this->dbVerifiedAt($user));
    }

    public function testLifecycleIdempotentWhenDbAlreadyActiveVerified(): void
    {
        $user = $this->activeUser('life-idem@example.com', UserRole::Student);
        $verifiedAt = $this->dbVerifiedAt($user);
        $this->lifecycle()->markEmailVerifiedAndActivate($user);
        self::assertSame($verifiedAt, $this->dbVerifiedAt($user));
        self::assertSame(UserStatus::Active->value, $this->dbStatus($user));
    }

    public function testLifecycleAuditFailureRollsBackActivation(): void
    {
        $user = $this->factory->createAndPersist('life-audit@example.com', 'Guclu-Parola-123!', 'L', 'F', UserRole::Student);
        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');
        try {
            try {
                $this->lifecycle()->markEmailVerifiedAndActivate($user);
                self::fail('Expected audit failure');
            } catch (\Throwable) {
            }
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
            self::ensureKernelShutdown();
            self::bootKernel();
            $this->rebind();
        }

        $reloaded = $this->users->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatus::PendingVerification, $reloaded->getStatus());
        self::assertNull($reloaded->getEmailVerifiedAt());
    }

    public function testLifecycleSuccessInvalidatesUserCache(): void
    {
        $user = $this->factory->createAndPersist('life-cache@example.com', 'Guclu-Parola-123!', 'L', 'C', UserRole::Student);
        $lookup = $this->lookup();
        $before = $lookup->getUserSnapshot($user->getId());
        self::assertNotNull($before);
        self::assertSame(UserStatus::PendingVerification, $before->status);

        $this->lifecycle()->markEmailVerifiedAndActivate($user);
        $after = $lookup->getUserSnapshot($user->getId());
        self::assertNotNull($after);
        self::assertSame(UserStatus::Active, $after->status);
        self::assertTrue($after->isActiveAndVerified());
    }

    private function roleManager(): UserGlobalRoleManager
    {
        $service = static::getContainer()->get(UserGlobalRoleManager::class);
        self::assertInstanceOf(UserGlobalRoleManager::class, $service);

        return $service;
    }

    private function statusManager(): UserStatusManager
    {
        $service = static::getContainer()->get(UserStatusManager::class);
        self::assertInstanceOf(UserStatusManager::class, $service);

        return $service;
    }

    private function lifecycle(): UserAccountLifecycle
    {
        $service = static::getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserAccountLifecycle::class, $service);

        return $service;
    }

    private function lookup(): RequestScopedInstitutionAuthLookup
    {
        $lookup = static::getContainer()->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);

        return $lookup;
    }

    private function decide(User $user, string $attribute, Institution $institution): bool
    {
        $this->lookup()->reset();
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $institution);
    }

    /**
     * @return array{0: User, 1: User, 2: Institution}
     */
    private function activeInstitutionBundle(string $prefix): array
    {
        $sa = $this->activeSuperAdmin($prefix.'-sa@example.com');
        $owner = $this->activeUser($prefix.'-owner@example.com', UserRole::Teacher);
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        $institution = $creator->create($sa, $owner, $prefix.' School', InstitutionType::School, 'platform_setup');
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $status->activate($institution, $sa, 'activate_ok');

        return [$sa, $owner, $institution];
    }

    private function activeSuperAdmin(string $email): User
    {
        $user = $this->activeUser($email, UserRole::Moderator);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function activeAdmin(string $email): User
    {
        $user = $this->activeUser($email, UserRole::Moderator);
        $user->addGlobalRole(UserRole::Admin);
        $this->users->save($user);

        return $user;
    }

    private function activeUser(string $email, UserRole $role = UserRole::Student): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'T', 'U', $role);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    /**
     * @param list<string> $roles
     */
    private function setGlobalRolesInDb(User $user, array $roles): void
    {
        $roles = array_values(array_filter(
            $roles,
            static fn (string $role): bool => UserRole::User->value !== $role,
        ));
        sort($roles);
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

    private function dbRolesJson(User $user): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT global_roles FROM users WHERE id = :id',
            ['id' => $user->getId()->toBinary()],
        );
    }

    /**
     * @return list<string>
     */
    private function dbRolesList(User $user): array
    {
        /** @var list<string> $decoded */
        $decoded = json_decode($this->dbRolesJson($user), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function dbStatus(User $user): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $user->getId()->toBinary()],
        );
    }

    private function dbVerifiedAt(User $user): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT email_verified_at FROM users WHERE id = :id',
            ['id' => $user->getId()->toBinary()],
        );

        return null === $value ? null : (string) $value;
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
        $access = $c->get(AccessDecisionManagerInterface::class);
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $access);
        $this->access = $access;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        foreach ([
            'security_audit_events',
            'institution_memberships',
            'institutions',
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
        if (isset($this->em)) {
            $this->cleanup();
        }
        parent::tearDown();
    }
}
