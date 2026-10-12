<?php

declare(strict_types=1);

namespace App\Enum;

enum LocationLevel: string
{
    case Province = 'province';
    case District = 'district';
    case School = 'school';
}
