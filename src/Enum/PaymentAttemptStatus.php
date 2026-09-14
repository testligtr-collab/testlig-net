<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentAttemptStatus: string
{
    case Initiated = 'initiated';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Captured, self::Failed, self::Cancelled => true,
            self::Initiated, self::Authorized => false,
        };
    }

    public function allowsCapture(): bool
    {
        return match ($this) {
            self::Initiated, self::Authorized => true,
            self::Captured, self::Failed, self::Cancelled => false,
        };
    }
}
