<?php

declare(strict_types=1);

namespace App\Enum;

enum StoredMediaScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Clean, self::Infected, self::Failed],
            self::Clean => [self::Infected, self::Failed],
            self::Infected => [],
            self::Failed => [self::Clean, self::Infected, self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}
