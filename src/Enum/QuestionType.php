<?php

declare(strict_types=1);

namespace App\Enum;

enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case TrueFalse = 'true_false';
    case Numeric = 'numeric';
    case ShortAnswer = 'short_answer';
}
