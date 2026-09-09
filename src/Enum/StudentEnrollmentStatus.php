<?php

declare(strict_types=1);

namespace App\Enum;

enum StudentEnrollmentStatus: string
{
    case Active = 'active';
    case Ended = 'ended';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Ended],
            self::Ended => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}
