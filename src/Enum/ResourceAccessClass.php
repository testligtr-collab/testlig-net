<?php

declare(strict_types=1);

namespace App\Enum;

enum ResourceAccessClass: string
{
    case Free = 'free';
    case EntitlementRequired = 'entitlement_required';
}
