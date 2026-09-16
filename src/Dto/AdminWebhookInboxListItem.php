<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Safe webhook inbox list/detail projection (no payload/signature hashes).
 */
final class AdminWebhookInboxListItem
{
    public function __construct(
        public readonly Uuid $eventId,
        public readonly PaymentWebhookInboxStatus $status,
        public readonly string $providerCode,
        public readonly PaymentProviderEnvironment $environment,
        public readonly PaymentEventType $eventType,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly ?\DateTimeImmutable $nextRetryAt,
        public readonly ?\DateTimeImmutable $leaseExpiresAt,
        public readonly int $attemptCount,
        public readonly ?string $lastFailureReasonCode,
        public readonly ?Uuid $paymentAttemptId,
        public readonly bool $isStaleLease,
        public readonly bool $canRequeue,
    ) {
    }
}
