<?php

declare(strict_types=1);

namespace App\Enum;

enum ResultReleaseStatus: string
{
    case Draft = 'draft';
    case Released = 'released';
    case Superseded = 'superseded';
    case Withdrawn = 'withdrawn';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Released],
            self::Released => [self::Superseded, self::Withdrawn],
            self::Superseded, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function isActiveRelease(): bool
    {
        return self::Released === $this;
    }

    public function isTerminal(): bool
    {
        return self::Superseded === $this || self::Withdrawn === $this;
    }
}
