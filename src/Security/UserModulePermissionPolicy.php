<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\ModeratorModuleGrant;
use App\Enum\UserRole;
use App\Security\Authorization\UserAuthorizationSnapshot;

/**
 * Single check for a direct moderator module grant.
 *
 * Role access stays in the existing voters and managers. A grant is extra,
 * platform-only, and inert unless the account is an active verified moderator
 * who is not an admin or superadmin.
 */
final class UserModulePermissionPolicy
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
    ) {
    }

    public function allows(User $user, ModeratorModuleGrant $grant): bool
    {
        $snapshot = $this->authLookup->getUserSnapshot($user->getId());

        return $snapshot instanceof UserAuthorizationSnapshot
            && $this->snapshotAllows($snapshot, $grant);
    }

    public function snapshotAllows(UserAuthorizationSnapshot $user, ModeratorModuleGrant $grant): bool
    {
        if (!$user->isActiveAndVerified()) {
            return false;
        }
        if (\in_array(UserRole::SuperAdmin->value, $user->roles, true)
            || \in_array(UserRole::Admin->value, $user->roles, true)
        ) {
            return false;
        }
        if (!\in_array(UserRole::Moderator->value, $user->roles, true)) {
            return false;
        }

        return \in_array($grant->value, $user->moduleGrants, true);
    }
}
