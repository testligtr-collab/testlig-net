<?php

declare(strict_types=1);

namespace App\Enum;

enum PasswordChangeFailureReason: string
{
    case InvalidCurrentPassword = 'invalid_current';
    case SameAsCurrent = 'same_as_current';
    case AccountUnavailable = 'account_unavailable';
    case Conflict = 'conflict';
}
