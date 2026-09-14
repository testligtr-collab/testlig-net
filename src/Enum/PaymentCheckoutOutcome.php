<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Typed checkout/provider call outcomes. Ambiguous never collapses into failed.
 */
enum PaymentCheckoutOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Ambiguous = 'ambiguous';
    case Conflict = 'conflict';
}
