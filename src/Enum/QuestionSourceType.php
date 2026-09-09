<?php

declare(strict_types=1);

namespace App\Enum;

enum QuestionSourceType: string
{
    case Original = 'original';
    case Licensed = 'licensed';
    case Imported = 'imported';
}
