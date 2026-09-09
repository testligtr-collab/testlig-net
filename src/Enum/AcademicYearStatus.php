<?php

declare(strict_types=1);

namespace App\Enum;

enum AcademicYearStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::Active, self::Closed],
            self::Active => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function allowsClassroomMutation(): bool
    {
        return self::Closed !== $this;
    }
}
