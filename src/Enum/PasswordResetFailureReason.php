<?php

declare(strict_types=1);

namespace App\Enum;

enum PasswordResetFailureReason: string
{
    case InvalidToken = 'invalid_token';
    case SameAsCurrent = 'same_as_current';
    case AccountUnavailable = 'account_unavailable';
    case Conflict = 'conflict';
}
