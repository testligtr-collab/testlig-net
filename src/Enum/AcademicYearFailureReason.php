<?php

declare(strict_types=1);

namespace App\Enum;

enum AcademicYearFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case CrossInstitution = 'cross_institution';
    case InstitutionNotOperable = 'institution_not_operable';
    case DateOverlap = 'date_overlap';
    case YearNotOperable = 'year_not_operable';
}
