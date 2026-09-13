<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentRefundStatus;
use App\Money\Money;

final class PaymentProviderRefundResult
{
    /**
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     */
    public function __construct(
        public readonly PaymentRefundStatus $status,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly Money $amount,
        public readonly ?string $providerRefundReference = null,
        public readonly ?string $failureCode = null,
        public readonly array $sanitizedMetadata = [],
    ) {
    }
}
