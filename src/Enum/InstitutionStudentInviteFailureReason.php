<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionStudentInviteFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case NotFound = 'not_found';
    case NotEligible = 'not_eligible';
    case GradeMismatch = 'grade_mismatch';
    case Capacity = 'capacity';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case AccountMismatch = 'account_mismatch';
    case AccountNotReady = 'account_not_ready';
    case ProfileNotReady = 'profile_not_ready';
    case MailFailed = 'mail_failed';
    case Conflict = 'conflict';
}
