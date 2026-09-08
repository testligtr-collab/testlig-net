<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionMembershipFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidTransition = 'invalid_transition';
    case InvalidInput = 'invalid_input';
    case Conflict = 'conflict';
    case DuplicateMembership = 'duplicate_membership';
    case LastOwnerProtected = 'last_owner_protected';
    case CrossInstitution = 'cross_institution';
    case InstitutionNotOperable = 'institution_not_operable';
    case OwnerRoleRestricted = 'owner_role_restricted';
    case NotFound = 'not_found';
}
