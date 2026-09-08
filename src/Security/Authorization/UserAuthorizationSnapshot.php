<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for a user. Independent of Doctrine identity map.
 *
 * @phpstan-type RoleList list<string>
 */
final readonly class UserAuthorizationSnapshot
{
    /**
     * @param RoleList $roles
     */
    public function __construct(
        public Uuid $id,
        public UserStatus $status,
        public bool $emailVerified,
        public array $roles,
    ) {
    }

    public function isActiveAndVerified(): bool
    {
        return UserStatus::Active === $this->status && $this->emailVerified;
    }

    public function isSuperAdmin(): bool
    {
        return \in_array(UserRole::SuperAdmin->value, $this->roles, true);
    }

    public function isActiveVerifiedSuperAdmin(): bool
    {
        return $this->isActiveAndVerified() && $this->isSuperAdmin();
    }
}
