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

    public function initials(): string
    {
        $first = mb_substr(trim($this->firstName), 0, 1, 'UTF-8');
        $last = mb_substr(trim($this->lastName), 0, 1, 'UTF-8');
        $initials = mb_strtoupper($first.$last, 'UTF-8');

        return '' !== $initials ? $initials : '?';
    }

    public function avatarTone(): string
    {
        $tones = ['blue', 'green', 'violet', 'amber', 'teal', 'rose'];
        $hex = substr(str_replace('-', '', $this->id->toRfc4122()), 0, 2);
        $index = ((int) hexdec($hex)) % \count($tones);

        return $tones[$index];
    }
}
