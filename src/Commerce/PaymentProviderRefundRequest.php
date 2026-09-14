<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentRefundReasonCode;
use App\Money\Money;
use Symfony\Component\Uid\Uuid;

final class PaymentProviderRefundRequest
{
    public function __construct(
        public readonly Uuid $paymentAttemptId,
        public readonly string $providerPaymentReference,
        public readonly Money $amount,
        public readonly PaymentRefundReasonCode $reasonCode,
        public readonly string $idempotencyKey,
    ) {
    }
}
