<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Purpose of a phone verification claim (Stage 2.22.2a: bind only).
 */
enum PhoneVerificationPurpose: string
{
    case BindPhone = 'bind_phone';
}
