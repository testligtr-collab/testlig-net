<?php

declare(strict_types=1);

namespace App\Enum;

enum EntitlementGrantSource: string
{
    case FreePublication = 'free_publication';
    case IndividualLicense = 'individual_license';
    case InstitutionLicenseSeat = 'institution_license_seat';
}
