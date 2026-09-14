<?php

declare(strict_types=1);

namespace App\Commerce;

/**
 * Verifies webhook authenticity over the raw request body (constant-time).
 * Algorithm is provider-specific; production vendors plug in here without leaking SDKs.
 */
interface PaymentWebhookSignatureVerifierInterface
{
    public function getProviderCode(): string;

    /**
     * @throws \App\Exception\CommerceException on invalid signature or timestamp window
     */
    public function verify(PaymentWebhookVerificationRequest $request): void;
}
