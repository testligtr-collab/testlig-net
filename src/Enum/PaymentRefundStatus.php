<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentRefundStatus: string
{
    case Requested = 'requested';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            self::Requested => false,
        };
    }
}
