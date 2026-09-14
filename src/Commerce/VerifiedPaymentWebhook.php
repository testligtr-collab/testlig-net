<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Provider-neutral webhook after signature + timestamp + parse succeeded.
 * Never carries raw body, raw signature, or card data.
 */
final class VerifiedPaymentWebhook
{
    /**
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     */
    public function __construct(
        public readonly string $providerCode,
        public readonly PaymentProviderEnvironment $environment,
        public readonly string $providerEventReference,
        public readonly PaymentEventType $eventType,
        public readonly string $payloadHash,
        public readonly string $signatureFingerprint,
        public readonly \DateTimeImmutable $providerOccurredAt,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly ?Uuid $paymentAttemptId = null,
        public readonly ?string $orderPublicReference = null,
        public readonly ?Money $amount = null,
        public readonly ?string $providerPaymentReference = null,
        public readonly ?string $providerAuthorizationReference = null,
        public readonly ?string $providerRefundReference = null,
        public readonly ?string $failureCode = null,
        public readonly array $sanitizedMetadata = [],
        public readonly int $schemaVersion = 1,
    ) {
    }
}
