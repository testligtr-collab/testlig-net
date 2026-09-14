<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentProviderEnvironment;

/**
 * Provider-neutral seam for payment integration.
 *
 * Stage 2.18 ships a sandbox adapter for dev/test only — not a commercial SDK.
 * Adapters receive an order/attempt reference and an amount in integer minor units,
 * and return opaque provider references plus sanitized metadata.
 *
 * Implementations MUST:
 * - never accept, return, log, or persist card numbers, CVV, or expiry data;
 * - never receive the raw idempotency key (only the caller-supplied opaque token);
 * - return already-sanitized metadata (allowlisted scalar keys only).
 */
interface PaymentProviderAdapterInterface
{
    /**
     * Stable snake_case provider identifier persisted on payment attempts.
     */
    public function getProviderCode(): string;

    public function getEnvironment(): PaymentProviderEnvironment;

    public function supportsRecurring(): bool;

    /**
     * Requests authorization for an already-persisted payment attempt.
     */
    public function authorize(PaymentProviderChargeRequest $request): PaymentProviderChargeResult;

    /**
     * Captures a previously authorized payment.
     */
    public function capture(PaymentProviderChargeRequest $request): PaymentProviderChargeResult;

    /**
     * Requests a full or partial refund against a captured payment.
     */
    public function refund(PaymentProviderRefundRequest $request): PaymentProviderRefundResult;
}
