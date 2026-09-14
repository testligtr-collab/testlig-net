<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Stable, lowercase snake_case failure reasons for {@see \App\Money\Money}.
 */
enum MoneyFailureReason: string
{
    case InvalidCurrency = 'invalid_currency';
    case NegativeAmount = 'negative_amount';
    case Overflow = 'overflow';
    case CurrencyMismatch = 'currency_mismatch';
    case InvalidScale = 'invalid_scale';
}
