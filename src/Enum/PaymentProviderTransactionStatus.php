<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Provider-neutral transaction status returned by reconciliation adapters.
 * Safe snapshot field only — never carries card/PII/raw payload.
 */
enum PaymentProviderTransactionStatus: string
{
    case Initiated = 'initiated';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
