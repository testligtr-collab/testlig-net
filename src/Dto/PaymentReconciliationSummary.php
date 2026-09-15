<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Aggregate-only reconciliation command result. No UUIDs/refs/hashes in CLI output.
 */
final class PaymentReconciliationSummary
{
    public function __construct(
        public readonly int $checked,
        public readonly int $matched,
        public readonly int $discrepancy,
        public readonly int $failed,
        public readonly string $runStatus,
        public readonly bool $dryRun = false,
    ) {
    }
}
