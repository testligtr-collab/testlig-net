<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderTransactionStatus;
use App\Enum\PaymentReconciliationItemAction;
use App\Enum\PaymentReconciliationItemOutcome;
use Symfony\Component\Uid\Uuid;

/**
 * Safe discrepancy item view. Omits snapshot hash and provider references.
 */
final class PaymentDiscrepancyView
{
    public function __construct(
        public readonly Uuid $itemId,
        public readonly Uuid $runId,
        public readonly Uuid $paymentAttemptId,
        public readonly PaymentAttemptStatus $expectedState,
        public readonly ?PaymentProviderTransactionStatus $providerState,
        public readonly PaymentReconciliationItemOutcome $outcome,
        public readonly PaymentReconciliationItemAction $action,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly ?string $reasonCode,
    ) {
    }
}
