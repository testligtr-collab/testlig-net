<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Closed vocabulary for order / subscription cancellation. Never free-form buyer text.
 */
enum CommerceCancellationReasonCode: string
{
    case PurchaserRequested = 'purchaser_requested';
    case PaymentFailed = 'payment_failed';
    case PaymentExpired = 'payment_expired';
    case ProviderCancelled = 'provider_cancelled';
    case CatalogRetired = 'catalog_retired';
    case AdministrativeCancellation = 'administrative_cancellation';
}
