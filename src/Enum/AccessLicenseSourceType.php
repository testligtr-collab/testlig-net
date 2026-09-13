<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessLicenseSourceType: string
{
    case Manual = 'manual';
    case Purchase = 'purchase';
    case Promotion = 'promotion';
    case InstitutionContract = 'institution_contract';
    case Migration = 'migration';
}
