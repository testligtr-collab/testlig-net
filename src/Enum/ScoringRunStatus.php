<?php

declare(strict_types=1);

namespace App\Enum;

enum ScoringRunStatus: string
{
    case Processing = 'processing';
    case PendingManual = 'pending_manual';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Processing => [self::PendingManual, self::Completed, self::Failed],
            self::PendingManual => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return self::Completed === $this || self::Failed === $this;
    }

    public function allowsItemMutation(): bool
    {
        return self::Processing === $this || self::PendingManual === $this;
    }
}
