<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\UserStatus;
use Symfony\Component\Uid\Uuid;

final class AdminUserDetailView
{
    /**
     * @param list<string>     $globalRoles
     * @param list<UserStatus> $allowedStatusTargets
     * @param list<string>     $assignableRoleValues
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly string $shortRef,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly UserStatus $status,
        public readonly array $globalRoles,
        public readonly bool $emailVerified,
        public readonly \DateTimeImmutable $createdAt,
        public readonly bool $canManageRoles,
        public readonly bool $canManageStatus,
        public readonly array $allowedStatusTargets,
        public readonly array $assignableRoleValues,
        public readonly bool $isProtectedSuperAdmin,
        public readonly bool $isSelf,
    ) {
    }

    public function displayName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
