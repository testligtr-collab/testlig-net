<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Component\Uid\Uuid;

final class AdminUserListItemView
{
    /**
     * @param list<string> $globalRoles
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
    ) {
    }

    public function displayName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * @return list<string>
     */
    public function roleLabels(): array
    {
        $labels = $this->globalRoles;
        if ([] === $labels) {
            return [UserRole::User->value];
        }

        return $labels;
    }
}
