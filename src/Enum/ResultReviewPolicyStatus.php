<?php

declare(strict_types=1);

namespace App\Enum;

enum ResultReviewPolicyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active],
            self::Active => [self::Superseded],
            self::Superseded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function isActivePolicy(): bool
    {
        return self::Active === $this;
    }

    public function isTerminal(): bool
    {
        return self::Superseded === $this;
    }

    public function allowsDraftMutation(): bool
    {
        return self::Draft === $this;
    }
}
