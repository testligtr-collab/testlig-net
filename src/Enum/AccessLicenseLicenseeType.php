<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessLicenseLicenseeType: string
{
    case User = 'user';
    case Institution = 'institution';
}
