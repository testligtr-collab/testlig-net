<?php

declare(strict_types=1);

namespace App\Enum;

enum CommerceOrderStatus: string
{
    case Draft = 'draft';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Cancelled, self::Expired => true,
            self::Draft, self::AwaitingPayment, self::Failed => false,
        };
    }

    public function allowsPaymentAttempt(): bool
    {
        return match ($this) {
            self::Draft, self::AwaitingPayment, self::Failed => true,
            self::Paid, self::Cancelled, self::Expired => false,
        };
    }

    public function allowsCancellation(): bool
    {
        return match ($this) {
            self::Draft, self::AwaitingPayment, self::Failed => true,
            self::Paid, self::Cancelled, self::Expired => false,
        };
    }
}
