<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for a membership. Independent of Doctrine identity map.
 */
final readonly class MembershipAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public Uuid $institutionId,
        public Uuid $userId,
        public InstitutionMembershipRole $role,
        public InstitutionMembershipStatus $status,
    ) {
    }

    public function isActive(): bool
    {
        return InstitutionMembershipStatus::Active === $this->status;
    }
}
