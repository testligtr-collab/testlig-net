<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Append-only webhook inbox processing lifecycle.
 *
 * Transitions: received → processing → processed|rejected|failed.
 * Terminal states never move backwards.
 */
enum PaymentWebhookInboxStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Processed, self::Rejected, self::Failed => true,
            self::Received, self::Processing => false,
        };
    }

    public function canStartProcessing(): bool
    {
        return self::Received === $this;
    }
}
