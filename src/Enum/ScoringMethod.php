<?php

declare(strict_types=1);

namespace App\Enum;

enum ScoringMethod: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
