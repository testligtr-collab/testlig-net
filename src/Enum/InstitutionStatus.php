<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Archived],
            self::Active => [self::Suspended, self::Archived],
            self::Suspended => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function allowsMembershipAccess(): bool
    {
        return self::Active === $this;
    }

    public function allowsMembershipManagement(): bool
    {
        return self::Active === $this;
    }
}
