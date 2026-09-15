<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentReconciliationItemOutcome: string
{
    case Matched = 'matched';
    case LocalBehind = 'local_behind';
    case ProviderBehind = 'provider_behind';
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case ReferenceMismatch = 'reference_mismatch';
    case MissingAtProvider = 'missing_at_provider';
    case Unsupported = 'unsupported';
    case Failed = 'failed';

    public function isDiscrepancy(): bool
    {
        return match ($this) {
            self::Matched => false,
            self::LocalBehind,
            self::ProviderBehind,
            self::AmountMismatch,
            self::CurrencyMismatch,
            self::ReferenceMismatch,
            self::MissingAtProvider,
            self::Unsupported,
            self::Failed => true,
        };
    }

    public function isHardFailure(): bool
    {
        return self::Failed === $this;
    }
}
