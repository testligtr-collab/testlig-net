<?php

declare(strict_types=1);

namespace App\Commerce;

/**
 * Parses a verified raw webhook body into a provider-neutral DTO.
 * Must never return or retain raw body / signature / secrets / card data.
 */
interface PaymentWebhookParserInterface
{
    public function getProviderCode(): string;

    /**
     * @throws \App\Exception\CommerceException on malformed or unsafe payloads
     */
    public function parse(PaymentWebhookVerificationRequest $request): VerifiedPaymentWebhook;
}
