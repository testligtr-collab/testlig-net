<?php

declare(strict_types=1);

namespace App\Commerce;

/**
 * Raw HTTP materials required to verify a webhook signature.
 * Callers must not log body, signature, or secrets.
 */
final class PaymentWebhookVerificationRequest
{
    public function __construct(
        public readonly string $providerCode,
        public readonly string $rawBody,
        public readonly string $signatureHeader,
        public readonly string $timestampHeader,
        public readonly \DateTimeImmutable $receivedAt,
    ) {
    }
}
