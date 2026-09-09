<?php

declare(strict_types=1);

namespace App\Enum;

enum QuestionScope: string
{
    case Platform = 'platform';
    case Institution = 'institution';
}
