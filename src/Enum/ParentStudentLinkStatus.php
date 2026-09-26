<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Parent–student link lifecycle (Stage 2.22.5a foundation).
 *
 * {@see Verified} grants only the limited parent summary on `/veli`.
 * It does not grant student actions, answer keys, admin access, or a new role.
 */
enum ParentStudentLinkStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Ended = 'ended';
}
