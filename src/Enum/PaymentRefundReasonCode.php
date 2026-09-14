<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Closed vocabulary for refund requests. Never free-form buyer text or PII.
 */
enum PaymentRefundReasonCode: string
{
    case PurchaserRequested = 'purchaser_requested';
    case DuplicateCharge = 'duplicate_charge';
    case ServiceNotDelivered = 'service_not_delivered';
    case SuspectedFraud = 'suspected_fraud';
    case PriceAdjustment = 'price_adjustment';
    case AdministrativeRefund = 'administrative_refund';
}
