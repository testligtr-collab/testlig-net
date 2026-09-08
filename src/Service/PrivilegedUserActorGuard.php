<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserManagementFailureReason;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InvalidUserTransitionException;

/**
 * Shared active+verified privileged-actor gate for global role/status mutations.
 *
 * Reuses {@see ActiveVerifiedUserPolicy}; does not duplicate status/verification rules.
 */
final class PrivilegedUserActorGuard
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
    ) {
    }

    /**
     * @throws InvalidUserTransitionException
     */
    public function assertActiveVerifiedPrivilegedActor(User $actor): void
    {
        if (UserStatus::Active !== $actor->getStatus()) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::ActorNotActive,
                'Actor must be an active user.',
            );
        }

        if (null === $actor->getEmailVerifiedAt()) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::ActorNotVerified,
                'Actor must have a verified e-mail address.',
            );
        }

        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::ActorNotAuthorized,
                'Actor is not eligible for privileged user mutations.',
            );
        }

        if (!$this->isAdmin($actor) && !$this->isSuperAdmin($actor)) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::ActorNotAuthorized,
                'Only ROLE_ADMIN or ROLE_SUPER_ADMIN actors may perform privileged user mutations.',
            );
        }
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->activeVerifiedUserPolicy->isSuperAdmin($user);
    }

    public function isAdmin(User $user): bool
    {
        return \in_array(UserRole::Admin->value, $user->getRoles(), true);
    }

    /**
     * True when the user holds ROLE_ADMIN but not ROLE_SUPER_ADMIN.
     */
    public function isPlainAdmin(User $user): bool
    {
        return $this->isAdmin($user) && !$this->isSuperAdmin($user);
    }
}
