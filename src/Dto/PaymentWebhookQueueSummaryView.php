<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Safe webhook queue ops summary. No event ids/hashes/refs.
 */
final class PaymentWebhookQueueSummaryView
{
    public function __construct(
        public readonly int $dueCount,
        public readonly int $receivedCount,
        public readonly int $retryPendingCount,
        public readonly int $processingCount,
        public readonly int $activeLeaseCount,
        public readonly int $staleLeaseCount,
        public readonly int $deadLetterCount,
        public readonly int $rejectedCount,
        public readonly int $processedCount,
        public readonly ?int $oldestDueAgeSeconds,
    ) {
    }
}
