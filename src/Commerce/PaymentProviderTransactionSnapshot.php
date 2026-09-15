<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Entity\PaymentAttempt;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentProviderTransactionStatus;
use App\Exception\CommerceException;
use App\Time\UtcInstant;

/**
 * Safe provider transaction snapshot for reconciliation. No card/PII/raw payload/secrets.
 */
final class PaymentProviderTransactionSnapshot
{
    public function __construct(
        public readonly string $providerCode,
        public readonly PaymentProviderEnvironment $environment,
        public readonly string $providerPaymentReference,
        public readonly PaymentProviderTransactionStatus $providerStatus,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly \DateTimeImmutable $providerUpdatedAt,
        public readonly ?\DateTimeImmutable $authorizedAt = null,
        public readonly ?\DateTimeImmutable $capturedAt = null,
        public readonly ?\DateTimeImmutable $cancelledAt = null,
        public readonly int $refundCount = 0,
        public readonly int $refundedAmountMinor = 0,
        public readonly ?string $providerAuthorizationReference = null,
    ) {
        if (1 !== preg_match(PaymentAttempt::PROVIDER_CODE_PATTERN, $providerCode)) {
            throw CommerceException::invalidInput('providerCode invalid.');
        }
        PaymentAttempt::assertProviderReference($providerPaymentReference);
        if (null !== $providerAuthorizationReference) {
            PaymentAttempt::assertProviderReference($providerAuthorizationReference);
        }
        if ($amountMinor < 0 || $refundedAmountMinor < 0 || $refundCount < 0) {
            throw CommerceException::invalidInput('Snapshot amounts must be non-negative.');
        }
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw CommerceException::invalidInput('currency invalid.');
        }
        UtcInstant::ensure($providerUpdatedAt);
        if (null !== $authorizedAt) {
            UtcInstant::ensure($authorizedAt);
        }
        if (null !== $capturedAt) {
            UtcInstant::ensure($capturedAt);
        }
        if (null !== $cancelledAt) {
            UtcInstant::ensure($cancelledAt);
        }
    }
}
