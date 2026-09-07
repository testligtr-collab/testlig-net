<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\InvalidUserTransitionException;

/**
 * Controlled mutations for global system roles on the central User account.
 *
 * Domain-scoped memberships (institution, classroom, teacher/student links)
 * must not be modelled here — those belong to later membership entities.
 *
 * ROLE_SUPER_ADMIN cannot be assigned through this service. The first super-admin
 * account will be created later via an explicitly approved, audited, one-shot CLI
 * bootstrap command (not implemented in this phase).
 */
final class UserGlobalRoleManager
{
    /**
     * @param list<mixed> $roles
     */
    public function replaceRoles(User $user, array $roles): void
    {
        foreach ($roles as $role) {
            if (!$role instanceof UserRole) {
                throw InvalidUserTransitionException::forRole('Only UserRole enum values are allowed.');
            }

            if (UserRole::SuperAdmin === $role) {
                throw InvalidUserTransitionException::forRole(
                    'ROLE_SUPER_ADMIN cannot be assigned through application services in this phase.'
                );
            }
        }

        $user->setGlobalRoles($roles);
    }

    public function addRole(User $user, UserRole $role): void
    {
        if (UserRole::SuperAdmin === $role) {
            throw InvalidUserTransitionException::forRole(
                'ROLE_SUPER_ADMIN cannot be assigned through application services in this phase.'
            );
        }

        $user->addGlobalRole($role);
    }
}
