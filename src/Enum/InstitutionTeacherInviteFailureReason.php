<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionTeacherInviteFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case NotFound = 'not_found';
    case AlreadyTeacher = 'already_teacher';
    case NotEligible = 'not_eligible';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case AccountMismatch = 'account_mismatch';
    case AccountNotReady = 'account_not_ready';
    case MailFailed = 'mail_failed';
    case Conflict = 'conflict';
}
