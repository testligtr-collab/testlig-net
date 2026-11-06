<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionBranchKind: string
{
    case Branch = 'branch';
    case Campus = 'campus';
}
