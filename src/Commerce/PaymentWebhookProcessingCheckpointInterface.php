<?php

declare(strict_types=1);

namespace App\Commerce;

/**
 * Test/dev hook for deterministic failure injection during webhook processing.
 * Production wiring uses {@see NullPaymentWebhookProcessingCheckpoint}.
 */
interface PaymentWebhookProcessingCheckpointInterface
{
    /**
     * Named checkpoints: after_claim, before_settlement, after_settlement_before_fulfillment,
     * after_fulfillment_before_processed, before_processed_audit.
     */
    public function before(string $checkpoint): void;
}
