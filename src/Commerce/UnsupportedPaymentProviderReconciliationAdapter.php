<?php

declare(strict_types=1);

namespace App\Commerce;

/**
 * Fail-closed reconciliation adapter when a provider has no reconciliation registration.
 */
final class UnsupportedPaymentProviderReconciliationAdapter implements PaymentProviderReconciliationAdapterInterface
{
    public function __construct(
        private readonly string $providerCode,
    ) {
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }

    public function queryTransaction(PaymentReconciliationQuery $query): PaymentReconciliationLookupResult
    {
        return PaymentReconciliationLookupResult::unsupported('reconciliation_adapter_not_registered');
    }
}
