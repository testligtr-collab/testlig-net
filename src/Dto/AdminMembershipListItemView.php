<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use Symfony\Component\Uid\Uuid;

final class AdminMembershipListItemView
{
    public function __construct(
        public readonly Uuid $membershipId,
        public readonly Uuid $userId,
        public readonly string $shortRef,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly InstitutionMembershipRole $role,
        public readonly InstitutionMembershipStatus $status,
        public readonly ?\DateTimeImmutable $joinedAt,
        public readonly ?\DateTimeImmutable $suspendedAt,
        public readonly ?\DateTimeImmutable $endedAt,
        public readonly bool $canChangeRole,
        public readonly bool $canSuspend,
        public readonly bool $canReactivate,
        public readonly bool $canEnd,
    ) {
    }

    public function displayName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
