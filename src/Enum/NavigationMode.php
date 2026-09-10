<?php

declare(strict_types=1);

namespace App\Enum;

enum NavigationMode: string
{
    case Free = 'free';
    case Sequential = 'sequential';
}
