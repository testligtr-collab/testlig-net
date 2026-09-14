<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Provider-neutral settlement event vocabulary. No provider SDK names leak in here.
 */
enum PaymentEventType: string
{
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case RefundRequested = 'refund_requested';
    case RefundSucceeded = 'refund_succeeded';
    case RefundFailed = 'refund_failed';

    public function requiresAmount(): bool
    {
        return match ($this) {
            self::Authorized, self::Captured, self::RefundRequested, self::RefundSucceeded => true,
            self::Failed, self::Cancelled, self::RefundFailed => false,
        };
    }

    public function toAttemptStatus(): ?PaymentAttemptStatus
    {
        return match ($this) {
            self::Authorized => PaymentAttemptStatus::Authorized,
            self::Captured => PaymentAttemptStatus::Captured,
            self::Failed => PaymentAttemptStatus::Failed,
            self::Cancelled => PaymentAttemptStatus::Cancelled,
            self::RefundRequested, self::RefundSucceeded, self::RefundFailed => null,
        };
    }
}
