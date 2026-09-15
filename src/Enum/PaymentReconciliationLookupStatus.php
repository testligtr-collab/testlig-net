<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentReconciliationLookupStatus: string
{
    case Found = 'found';
    case Missing = 'missing';
    case Unsupported = 'unsupported';
    case Ambiguous = 'ambiguous';
    case Failed = 'failed';
}
