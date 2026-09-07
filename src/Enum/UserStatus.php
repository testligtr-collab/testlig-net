<?php

declare(strict_types=1);

namespace App\Enum;

enum UserStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function canAuthenticate(): bool
    {
        return self::Active === $this;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingVerification => [self::Active, self::Archived],
            self::Active => [self::Suspended, self::Archived],
            self::Suspended => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}
