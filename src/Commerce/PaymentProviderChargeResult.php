<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentEventType;
use App\Money\Money;

/**
 * Provider-neutral charge outcome. Metadata must already be sanitized scalars.
 */
final class PaymentProviderChargeResult
{
    /**
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     */
    public function __construct(
        public readonly PaymentEventType $eventType,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?Money $amount = null,
        public readonly ?string $providerPaymentReference = null,
        public readonly ?string $providerAuthorizationReference = null,
        public readonly ?string $providerEventReference = null,
        public readonly ?string $failureCode = null,
        public readonly array $sanitizedMetadata = [],
    ) {
    }
}
