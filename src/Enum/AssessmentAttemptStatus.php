<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::InProgress => [self::Submitted, self::Expired, self::Cancelled],
            self::Submitted, self::Expired, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return self::InProgress !== $this;
    }
}
