<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PaymentAttempt;
use App\Enum\PaymentCheckoutOutcome;
use App\Enum\PaymentEventType;

/**
 * Result of {@see \App\Service\PaymentCheckoutOrchestrator::checkout()}.
 */
final class PaymentCheckoutResult
{
    public function __construct(
        public readonly PaymentCheckoutOutcome $outcome,
        public readonly PaymentAttempt $attempt,
        public readonly ?PaymentEventType $providerEventType = null,
        public readonly ?string $reasonCode = null,
    ) {
    }
}
