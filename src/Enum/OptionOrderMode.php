<?php

declare(strict_types=1);

namespace App\Enum;

enum OptionOrderMode: string
{
    case Fixed = 'fixed';
    case Shuffle = 'shuffle';
}
