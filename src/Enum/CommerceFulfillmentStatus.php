<?php

declare(strict_types=1);

namespace App\Enum;

enum CommerceFulfillmentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function requiresLicense(): bool
    {
        return match ($this) {
            self::Completed, self::Reversed => true,
            self::Pending, self::Failed => false,
        };
    }

    public function allowsReversal(): bool
    {
        return self::Completed === $this;
    }
}
