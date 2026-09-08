<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;

/**
 * Shared account gate for institution-domain actors and eligible subjects.
 *
 * Holding ROLE_SUPER_ADMIN on a stale in-memory User never bypasses this check.
 */
final class ActiveVerifiedUserPolicy
{
    public function isActiveAndVerified(User $user): bool
    {
        return UserStatus::Active === $user->getStatus()
            && null !== $user->getEmailVerifiedAt();
    }

    public function isSuperAdmin(User $user): bool
    {
        return \in_array(UserRole::SuperAdmin->value, $user->getRoles(), true);
    }

    public function isActiveVerifiedSuperAdmin(User $user): bool
    {
        return $this->isActiveAndVerified($user) && $this->isSuperAdmin($user);
    }
}
