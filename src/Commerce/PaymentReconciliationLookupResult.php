<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentReconciliationLookupStatus;

/**
 * Typed result of a provider reconciliation lookup. No raw payload.
 */
final class PaymentReconciliationLookupResult
{
    private function __construct(
        public readonly PaymentReconciliationLookupStatus $status,
        public readonly ?PaymentProviderTransactionSnapshot $snapshot = null,
        public readonly ?string $reasonCode = null,
    ) {
    }

    public static function found(PaymentProviderTransactionSnapshot $snapshot): self
    {
        return new self(PaymentReconciliationLookupStatus::Found, $snapshot);
    }

    public static function missing(string $reasonCode = 'missing_at_provider'): self
    {
        return new self(PaymentReconciliationLookupStatus::Missing, null, $reasonCode);
    }

    public static function unsupported(string $reasonCode = 'reconciliation_unsupported'): self
    {
        return new self(PaymentReconciliationLookupStatus::Unsupported, null, $reasonCode);
    }

    public static function ambiguous(string $reasonCode = 'provider_ambiguous'): self
    {
        return new self(PaymentReconciliationLookupStatus::Ambiguous, null, $reasonCode);
    }

    public static function failed(string $reasonCode = 'provider_query_failed'): self
    {
        return new self(PaymentReconciliationLookupStatus::Failed, null, $reasonCode);
    }
}
