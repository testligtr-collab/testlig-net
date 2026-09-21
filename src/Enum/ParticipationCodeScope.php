<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Structural scope for limited multi-use participation codes.
 *
 * Product-specific invite purposes stay as opaque purpose_code on PersonalInvitation;
 * this enum only distinguishes institution-wide vs classroom-scoped join codes.
 */
enum ParticipationCodeScope: string
{
    case Institution = 'institution';
    case Classroom = 'classroom';
}
