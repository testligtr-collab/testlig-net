<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlled mutations for global system roles on the central User account.
 *
 * ROLE_SUPER_ADMIN cannot be assigned here — use the audited one-shot CLI bootstrap.
 */
final class UserGlobalRoleManager
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<mixed> $roles
     */
    public function replaceRoles(User $user, array $roles, User $actor, string $reason): void
    {
        $this->assertActorMayManageRoles($actor);
        $this->assertAssignableRoles($roles);
        $reason = $this->normalizeReason($reason);

        $previous = $user->getRoles();
        $userId = $user->getId();
        $this->entityManager->wrapInTransaction(function () use ($user, $roles, $actor, $reason, $previous): void {
            $user->setGlobalRoles($roles);
            $this->users->save($user, false);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::RoleChanged,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                subjectUser: $user,
                metadata: [
                    'reason' => $reason,
                    'previous_roles' => $previous,
                    'new_roles' => $user->getRoles(),
                    'source' => 'user_global_role_manager',
                ],
            ), false);
            $this->entityManager->flush();
        });
        $this->authCache->invalidateUser($userId);
    }

    public function addRole(User $user, UserRole $role, User $actor, string $reason): void
    {
        $this->assertActorMayManageRoles($actor);
        $this->assertAssignableRoles([$role]);
        $reason = $this->normalizeReason($reason);

        $previous = $user->getRoles();
        $userId = $user->getId();
        $this->entityManager->wrapInTransaction(function () use ($user, $role, $actor, $reason, $previous): void {
            $user->addGlobalRole($role);
            $this->users->save($user, false);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::RoleChanged,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                subjectUser: $user,
                metadata: [
                    'reason' => $reason,
                    'previous_roles' => $previous,
                    'new_roles' => $user->getRoles(),
                    'source' => 'user_global_role_manager',
                ],
            ), false);
            $this->entityManager->flush();
        });
        $this->authCache->invalidateUser($userId);
    }

    public function removeRole(User $user, UserRole $role, User $actor, string $reason): void
    {
        $this->assertActorMayManageRoles($actor);
        if (UserRole::SuperAdmin === $role) {
            throw InvalidUserTransitionException::forRole(
                'ROLE_SUPER_ADMIN cannot be removed through application role services in this phase.'
            );
        }
        if (UserRole::User === $role) {
            throw InvalidUserTransitionException::forRole('ROLE_USER is implicit and cannot be removed.');
        }
        $reason = $this->normalizeReason($reason);

        $previous = $user->getRoles();
        $userId = $user->getId();
        $this->entityManager->wrapInTransaction(function () use ($user, $role, $actor, $reason, $previous): void {
            $remaining = array_values(array_filter(
                $user->getGlobalRoleEnums(),
                static fn (UserRole $existing): bool => $existing !== $role,
            ));
            $user->setGlobalRoles($remaining);
            $this->users->save($user, false);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::RoleChanged,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                subjectUser: $user,
                metadata: [
                    'reason' => $reason,
                    'previous_roles' => $previous,
                    'new_roles' => $user->getRoles(),
                    'source' => 'user_global_role_manager',
                ],
            ), false);
            $this->entityManager->flush();
        });
        $this->authCache->invalidateUser($userId);
    }

    /**
     * @param list<mixed> $roles
     */
    private function assertAssignableRoles(array $roles): void
    {
        foreach ($roles as $role) {
            if (!$role instanceof UserRole) {
                throw InvalidUserTransitionException::forRole('Only UserRole enum values are allowed.');
            }

            if (UserRole::SuperAdmin === $role) {
                throw InvalidUserTransitionException::forRole(
                    'ROLE_SUPER_ADMIN cannot be assigned through application services.'
                );
            }
        }
    }

    private function assertActorMayManageRoles(User $actor): void
    {
        $roles = $actor->getRoles();
        if (!\in_array(UserRole::Admin->value, $roles, true)
            && !\in_array(UserRole::SuperAdmin->value, $roles, true)) {
            throw InvalidUserTransitionException::forRole(
                'Only ROLE_ADMIN or ROLE_SUPER_ADMIN actors may change global roles.'
            );
        }
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
