<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Teacher evaluation labels for a submitted homework. Not a numeric score.
 */
enum HomeworkEvaluationLabel: string
{
    case Reviewed = 'reviewed';
    case NeedsRework = 'needs_rework';

    public function labelTr(): string
    {
        return match ($this) {
            self::Reviewed => 'İncelendi',
            self::NeedsRework => 'Yeniden çalışılmalı',
        };
    }
}
