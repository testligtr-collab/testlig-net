<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PaymentAttempt;
use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentWebhookInboxStatus;

/**
 * HTTP/application result for webhook ingress (generic, no secrets).
 */
final class PaymentWebhookIngressResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly int $httpStatus,
        public readonly string $reasonCode,
        public readonly ?PaymentWebhookInboxEvent $inboxEvent = null,
        public readonly ?PaymentAttempt $attempt = null,
    ) {
    }

    public static function accepted(PaymentWebhookInboxEvent $event, ?PaymentAttempt $attempt = null): self
    {
        return new self(
            true,
            200,
            PaymentWebhookInboxStatus::Processed === $event->getProcessingStatus()
                ? 'processed'
                : $event->getProcessingStatus()->value,
            $event,
            $attempt ?? $event->getPaymentAttempt(),
        );
    }

    public static function rejected(int $httpStatus, string $reasonCode): self
    {
        return new self(false, $httpStatus, $reasonCode);
    }
}
