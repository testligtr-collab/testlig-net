<?php

declare(strict_types=1);

namespace App\Enum;

enum QuestionOrderMode: string
{
    case Fixed = 'fixed';
    case Shuffle = 'shuffle';
}
