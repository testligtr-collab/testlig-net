<?php

declare(strict_types=1);

namespace App\Enum;

enum CurriculumTopicFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case ProgramNotDraft = 'program_not_draft';
    case ProgramImmutable = 'program_immutable';
    case DepthExceeded = 'depth_exceeded';
    case ActiveChildren = 'active_children';
    case CrossUnit = 'cross_unit';
}
