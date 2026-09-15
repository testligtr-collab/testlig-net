<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentReconciliationMode: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
}
