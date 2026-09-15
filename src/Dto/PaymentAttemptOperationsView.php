<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderEnvironment;
use Symfony\Component\Uid\Uuid;

/**
 * Authorized detail view for a single payment attempt. Safe fields only.
 */
final class PaymentAttemptOperationsView
{
    public function __construct(
        public readonly Uuid $attemptId,
        public readonly PaymentAttemptStatus $status,
        public readonly string $providerCode,
        public readonly PaymentProviderEnvironment $environment,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $orderPublicReference,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $authorizedAt,
        public readonly ?\DateTimeImmutable $capturedAt,
        public readonly ?string $failureCode,
    ) {
    }
}
