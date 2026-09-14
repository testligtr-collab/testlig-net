<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Append-only webhook inbox processing lifecycle with recoverable retry states.
 *
 * Delivery: provider at-least-once; processing is idempotent convergence.
 * Terminal: processed | rejected | dead_letter (never roll backwards).
 */
enum PaymentWebhookInboxStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case RetryPending = 'retry_pending';
    case Processed = 'processed';
    case Rejected = 'rejected';
    case DeadLetter = 'dead_letter';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Processed, self::Rejected, self::DeadLetter => true,
            self::Received, self::Processing, self::RetryPending => false,
        };
    }

    public function isClaimable(): bool
    {
        return match ($this) {
            self::Received, self::RetryPending, self::Processing => true,
            self::Processed, self::Rejected, self::DeadLetter => false,
        };
    }
}
