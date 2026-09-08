<?php

declare(strict_types=1);

namespace App\Enum;

enum SecurityAuditOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
}
