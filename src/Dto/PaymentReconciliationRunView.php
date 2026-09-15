<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentReconciliationMode;
use App\Enum\PaymentReconciliationRunStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Safe reconciliation run view.
 */
final class PaymentReconciliationRunView
{
    public function __construct(
        public readonly Uuid $runId,
        public readonly string $providerCode,
        public readonly PaymentProviderEnvironment $environment,
        public readonly PaymentReconciliationMode $mode,
        public readonly PaymentReconciliationRunStatus $status,
        public readonly string $reasonCode,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly int $checkedCount,
        public readonly int $matchedCount,
        public readonly int $discrepancyCount,
        public readonly int $failedCount,
    ) {
    }
}
