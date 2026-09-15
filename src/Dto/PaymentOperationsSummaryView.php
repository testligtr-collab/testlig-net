<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Safe aggregate payment operations dashboard view. No PII/refs/hashes.
 */
final class PaymentOperationsSummaryView
{
    /**
     * @param array<string, int> $attemptCountsByStatus
     */
    public function __construct(
        public readonly array $attemptCountsByStatus,
        public readonly int $dueWebhookCount,
        public readonly int $activeLeaseCount,
        public readonly int $staleLeaseCount,
        public readonly int $deadLetterCount,
        public readonly int $rejectedWebhookCount,
        public readonly ?int $oldestDueAgeSeconds,
        public readonly int $reconciliationMatchedCount,
        public readonly int $reconciliationDiscrepancyCount,
        public readonly int $reconciliationFailedCount,
    ) {
    }
}
