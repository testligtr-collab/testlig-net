<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessPackageTargetType: string
{
    case Individual = 'individual';
    case Institution = 'institution';
}
