<?php

declare(strict_types=1);

namespace App\Enum;

enum ClassroomStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function allowsAssignmentAndEnrollment(): bool
    {
        return self::Active === $this;
    }
}
