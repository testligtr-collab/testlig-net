<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentProviderEnvironment;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable reconciliation query. Never carries secrets or card data.
 */
final class PaymentReconciliationQuery
{
    public function __construct(
        public readonly string $providerCode,
        public readonly PaymentProviderEnvironment $environment,
        public readonly Uuid $paymentAttemptId,
        public readonly ?string $providerPaymentReference = null,
        public readonly ?string $orderPublicReference = null,
    ) {
    }
}
