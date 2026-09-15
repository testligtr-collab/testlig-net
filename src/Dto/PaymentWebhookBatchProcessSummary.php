<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Numeric-only summary for webhook due-batch processing. No UUIDs/hashes/refs/PII.
 */
final class PaymentWebhookBatchProcessSummary
{
    public function __construct(
        public readonly int $selected,
        public readonly int $processed,
        public readonly int $rejected,
        public readonly int $retryPending,
        public readonly int $deadLetter,
        public readonly int $failed,
        public readonly int $skipped,
        public readonly bool $dryRun = false,
    ) {
    }
}
