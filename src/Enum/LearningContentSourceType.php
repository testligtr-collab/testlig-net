<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningContentSourceType: string
{
    case Original = 'original';
    case Imported = 'imported';
    case ExternalReference = 'external_reference';
    case Cloned = 'cloned';
}
