<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Safe dead-letter requeue result. No payload/hash/reference leakage.
 */
final class PaymentWebhookDeadLetterRequeueResult
{
    public function __construct(
        public readonly Uuid $eventId,
        public readonly PaymentWebhookInboxStatus $status,
        public readonly string $providerCode,
        public readonly PaymentProviderEnvironment $environment,
        public readonly int $attemptCount,
        public readonly string $reasonCode,
    ) {
    }
}
