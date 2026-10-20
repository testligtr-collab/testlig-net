<?php

declare(strict_types=1);

namespace App\Enum;

enum NewsFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case NotFound = 'not_found';
    case SeparationOfDuties = 'separation_of_duties';
    case CategoryRequired = 'category_required';
    case Conflict = 'conflict';
}
