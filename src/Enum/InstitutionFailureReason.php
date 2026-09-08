<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidTransition = 'invalid_transition';
    case InvalidInput = 'invalid_input';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case InstitutionNotActive = 'institution_not_active';
}
