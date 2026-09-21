<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Digest context binding for invitation / join-code HMAC messages (Stage 2.22.4a).
 */
enum InvitationCodeKind: string
{
    case PersonalInvitation = 'personal_invitation';
    case ParticipationCode = 'participation_code';
}
