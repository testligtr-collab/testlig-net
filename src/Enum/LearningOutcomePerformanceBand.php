<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningOutcomePerformanceBand: string
{
    case Strong = 'strong';
    case Developing = 'developing';
    case NeedsSupport = 'needs_support';
}
