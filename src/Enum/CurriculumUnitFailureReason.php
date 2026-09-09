<?php

declare(strict_types=1);

namespace App\Enum;

enum CurriculumUnitFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case ProgramNotDraft = 'program_not_draft';
    case ProgramImmutable = 'program_immutable';
}
