<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Parent–student link lifecycle (Stage 2.22.5a foundation).
 *
 * {@see Verified} is a domain state only — it does not grant child-data access,
 * open an accept API, or change InvitationPurposeContract allow-lists.
 */
enum ParentStudentLinkStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Ended = 'ended';
}
