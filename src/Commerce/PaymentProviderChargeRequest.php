<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Card-data-free charge request passed to a {@see PaymentProviderAdapterInterface}.
 */
final class PaymentProviderChargeRequest
{
    public function __construct(
        public readonly Uuid $orderId,
        public readonly Uuid $paymentAttemptId,
        public readonly string $orderPublicReference,
        public readonly Money $amount,
        public readonly string $idempotencyKey,
        public readonly ?string $providerPaymentReference = null,
    ) {
    }
}
