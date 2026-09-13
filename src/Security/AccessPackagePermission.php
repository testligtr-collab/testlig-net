<?php

declare(strict_types=1);

namespace App\Security;

enum AccessPackagePermission: string
{
    case ManagePackage = 'ACCESS_PACKAGE_MANAGE';
    case ActivatePackage = 'ACCESS_PACKAGE_ACTIVATE';
    case ManageVersion = 'ACCESS_PACKAGE_VERSION_MANAGE';
    case ActivateVersion = 'ACCESS_PACKAGE_VERSION_ACTIVATE';
    case ManageLicense = 'ACCESS_LICENSE_MANAGE';
    case ManageInstitutionLicense = 'ACCESS_INSTITUTION_LICENSE_MANAGE';
    case ManageSeats = 'ACCESS_LICENSE_SEAT_MANAGE';
    case SetResourceAccessPolicy = 'ACCESS_RESOURCE_POLICY_SET';
}
