<?php

declare(strict_types=1);

namespace App\Enum;

enum ItemScoreOutcome: string
{
    case Correct = 'correct';
    case Incorrect = 'incorrect';
    case Unanswered = 'unanswered';
    case ManualPending = 'manual_pending';
    case ManuallyGraded = 'manually_graded';
    case Invalid = 'invalid';
}
