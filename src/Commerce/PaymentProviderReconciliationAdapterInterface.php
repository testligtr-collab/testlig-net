<?php

declare(strict_types=1);

namespace App\Commerce;

/**
 * Provider-neutral reconciliation read/query seam.
 * Implementations must never return card data, secrets, or raw provider payloads.
 */
interface PaymentProviderReconciliationAdapterInterface
{
    public function getProviderCode(): string;

    public function queryTransaction(PaymentReconciliationQuery $query): PaymentReconciliationLookupResult;
}
