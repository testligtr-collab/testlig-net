<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentScope: string
{
    case Platform = 'platform';
    case Institution = 'institution';
}
