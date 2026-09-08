<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserManagementFailureReason;
use App\Enum\UserRole;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Controlled mutations for global system roles on the central User account.
 *
 * ROLE_SUPER_ADMIN cannot be assigned or removed here — use audited bootstrap /
 * a future recovery/rotation path. Mutations always authorize against fresh locked
 * User rows (HINT_REFRESH), never against pre-transaction managed state.
 */
final class UserGlobalRoleManager
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly FreshUserLoader $freshUsers,
        private readonly PrivilegedUserActorGuard $actorGuard,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<mixed> $roles
     */
    public function replaceRoles(User $user, array $roles, User $actor, string $reason): void
    {
        $reason = $this->normalizeReason($reason);
        $normalizedRoles = $this->normalizeAssignableRoles($roles);
        $this->mutate($user, $actor, function (User $freshTarget, User $freshActor) use ($normalizedRoles, $reason): void {
            $this->assertMayMutateTargetRoles($freshActor, $freshTarget);
            $this->assertActorMayAssignRoles($freshActor, $normalizedRoles);

            $previous = $freshTarget->getRoles();
            $freshTarget->setGlobalRoles($normalizedRoles);
            $this->users->save($freshTarget, false);
            $this->recordRoleAudit($freshActor, $freshTarget, $previous, $reason);
        });
    }

    public function addRole(User $user, UserRole $role, User $actor, string $reason): void
    {
        $reason = $this->normalizeReason($reason);
        $this->assertAssignableRoleValue($role);
        $this->mutate($user, $actor, function (User $freshTarget, User $freshActor) use ($role, $reason): void {
            $this->assertMayMutateTargetRoles($freshActor, $freshTarget);
            $this->assertActorMayAssignRoles($freshActor, [$role]);

            $previous = $freshTarget->getRoles();
            $freshTarget->addGlobalRole($role);
            $this->users->save($freshTarget, false);
            $this->recordRoleAudit($freshActor, $freshTarget, $previous, $reason);
        });
    }

    public function removeRole(User $user, UserRole $role, User $actor, string $reason): void
    {
        if (UserRole::SuperAdmin === $role) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::ProtectedSuperAdmin,
                'ROLE_SUPER_ADMIN cannot be removed through application role services.',
            );
        }
        if (UserRole::User === $role) {
            throw InvalidUserTransitionException::forRole('ROLE_USER is implicit and cannot be removed.');
        }
        $reason = $this->normalizeReason($reason);

        $this->mutate($user, $actor, function (User $freshTarget, User $freshActor) use ($role, $reason): void {
            $this->assertMayMutateTargetRoles($freshActor, $freshTarget);

            $previous = $freshTarget->getRoles();
            $remaining = array_values(array_filter(
                $freshTarget->getGlobalRoleEnums(),
                static fn (UserRole $existing): bool => $existing !== $role,
            ));
            $freshTarget->setGlobalRoles($remaining);
            $this->users->save($freshTarget, false);
            $this->recordRoleAudit($freshActor, $freshTarget, $previous, $reason);
        });
    }

    /**
     * @param callable(User, User): void $mutation
     */
    private function mutate(User $user, User $actor, callable $mutation): void
    {
        $actorId = $actor->getId();
        $targetId = $user->getId();
        if ($actorId->equals($targetId)) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::SelfManagementForbidden,
                'Actors cannot change their own global roles.',
            );
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($actorId, $targetId, $mutation): void {
                [$freshActor, $freshTarget] = $this->lockActorAndTarget($actorId, $targetId);
                $this->actorGuard->assertActiveVerifiedPrivilegedActor($freshActor);
                $mutation($freshTarget, $freshActor);
                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::Conflict,
                'User role mutation could not complete due to a lock conflict. Retry later.',
            );
        }

        $this->authCache->invalidateUser($targetId);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function lockActorAndTarget(Uuid $actorId, Uuid $targetId): array
    {
        $locked = $this->freshUsers->findFreshLockedUsers(
            [$actorId, $targetId],
            LockMode::PESSIMISTIC_WRITE,
        );

        $freshActor = $locked[$actorId->toRfc4122()] ?? null;
        $freshTarget = $locked[$targetId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User || !$freshTarget instanceof User) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::UserNotFound,
                'User was not found.',
            );
        }

        return [$freshActor, $freshTarget];
    }

    private function assertMayMutateTargetRoles(User $freshActor, User $freshTarget): void
    {
        if ($this->actorGuard->isSuperAdmin($freshTarget)) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::ProtectedSuperAdmin,
                'ROLE_SUPER_ADMIN accounts cannot be modified through application role services.',
            );
        }

        if ($this->actorGuard->isPlainAdmin($freshActor) && $this->actorGuard->isAdmin($freshTarget)) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::TargetPrivilegeTooHigh,
                'ROLE_ADMIN actors cannot change roles on another ROLE_ADMIN account.',
            );
        }
    }

    /**
     * @param list<UserRole> $roles
     */
    private function assertActorMayAssignRoles(User $freshActor, array $roles): void
    {
        foreach ($roles as $role) {
            if (UserRole::SuperAdmin === $role) {
                throw InvalidUserTransitionException::management(
                    UserManagementFailureReason::ProtectedSuperAdmin,
                    'ROLE_SUPER_ADMIN cannot be assigned through application services.',
                );
            }

            if ($this->actorGuard->isPlainAdmin($freshActor) && UserRole::Admin === $role) {
                throw InvalidUserTransitionException::management(
                    UserManagementFailureReason::ActorNotAuthorized,
                    'ROLE_ADMIN actors cannot assign ROLE_ADMIN.',
                );
            }
        }
    }

    /**
     * @param list<mixed> $roles
     *
     * @return list<UserRole>
     */
    private function normalizeAssignableRoles(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            if (!$role instanceof UserRole) {
                throw InvalidUserTransitionException::forRole('Only UserRole enum values are allowed.');
            }
            $this->assertAssignableRoleValue($role);
            if (UserRole::User === $role) {
                continue;
            }
            $normalized[$role->value] = $role;
        }

        return array_values($normalized);
    }

    private function assertAssignableRoleValue(UserRole $role): void
    {
        if (UserRole::SuperAdmin === $role) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::ProtectedSuperAdmin,
                'ROLE_SUPER_ADMIN cannot be assigned through application services.',
            );
        }
    }

    /**
     * @param list<string> $previous
     */
    private function recordRoleAudit(User $freshActor, User $freshTarget, array $previous, string $reason): void
    {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::RoleChanged,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $freshActor,
            subjectUser: $freshTarget,
            metadata: [
                'reason' => $reason,
                'previous_roles' => $previous,
                'new_roles' => $freshTarget->getRoles(),
                'source' => 'user_global_role_manager',
            ],
        ), false);
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw InvalidUserTransitionException::forRole('A non-empty reason is required for role changes.');
        }

        return mb_substr($reason, 0, 255);
    }
}
