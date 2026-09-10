<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentDeliveryStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return self::Closed === $this || self::Cancelled === $this;
    }

    public function allowsDraftMutation(): bool
    {
        return self::Draft === $this;
    }
}
