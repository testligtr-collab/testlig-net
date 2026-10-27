<?php

declare(strict_types=1);

namespace App\Enum;

enum CurriculumAccessState: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
