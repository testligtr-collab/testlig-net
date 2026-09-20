<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Internal reason codes for phone verification failures (never expose OTP/phone).
 */
enum PhoneVerificationFailureReason: string
{
    case InvalidInput = 'invalid_input';
    case InvalidOtp = 'invalid_otp';
    case ClaimUnavailable = 'claim_unavailable';
    case AttemptsExceeded = 'attempts_exceeded';
    case PhoneConflict = 'phone_conflict';
    case Conflict = 'conflict';
}
