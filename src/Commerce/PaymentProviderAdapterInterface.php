<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentProviderEnvironment;

/**
 * Provider-neutral seam for a future payment integration.
 *
 * Stage 2.17 intentionally ships **no implementation** — not iyzico, not PayTR, not
 * Stripe. Only test doubles implement this interface. The contract is deliberately
 * card-data free: adapters receive an order/attempt reference and an amount in integer
 * minor units, and return opaque provider references plus sanitized metadata.
 *
 * Implementations added later MUST:
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
