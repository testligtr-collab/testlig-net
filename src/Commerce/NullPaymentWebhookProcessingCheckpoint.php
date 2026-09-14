<?php

declare(strict_types=1);

namespace App\Commerce;

/**
 * No-op checkpoint used in production/dev.
 */
final class NullPaymentWebhookProcessingCheckpoint implements PaymentWebhookProcessingCheckpointInterface
{
    public function before(string $checkpoint): void
    {
        unset($checkpoint);
    }
}
