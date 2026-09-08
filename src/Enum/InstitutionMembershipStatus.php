<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionMembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';

    public function grantsAccess(): bool
    {
        return self::Active === $this;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Ended],
            self::Active => [self::Suspended, self::Ended],
            self::Suspended => [self::Active, self::Ended],
            self::Ended => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}
