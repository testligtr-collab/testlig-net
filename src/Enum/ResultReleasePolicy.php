<?php

declare(strict_types=1);

namespace App\Enum;

enum ResultReleasePolicy: string
{
    case Immediate = 'immediate';
    case Manual = 'manual';
    case AfterClose = 'after_close';
}
