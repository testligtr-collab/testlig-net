<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\PhoneVerificationFailureReason;

/**
 * Phone verification / binding failures. Messages must never include OTP or raw phone.
 */
final class PhoneVerificationException extends \RuntimeException
{
    private readonly PhoneVerificationFailureReason $reason;

    private function __construct(string $message, PhoneVerificationFailureReason $reason)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function getReason(): PhoneVerificationFailureReason
    {
        return $this->reason;
    }

    public static function invalidInput(string $message = 'Telefon doğrulama verisi geçersiz.'): self
    {
        return new self($message, PhoneVerificationFailureReason::InvalidInput);
    }

    public static function digestMisconfigured(): self
    {
        return new self('Phone OTP pepper is misconfigured.', PhoneVerificationFailureReason::InvalidInput);
    }

    public static function digestMismatch(): self
    {
        return new self(
            'Telefon doğrulama kodu geçersiz veya kullanılamıyor.',
            PhoneVerificationFailureReason::InvalidOtp,
        );
    }

    public static function claimUnavailable(): self
    {
        return new self(
            'Telefon doğrulama kodu geçersiz veya kullanılamıyor.',
            PhoneVerificationFailureReason::ClaimUnavailable,
        );
    }

    public static function attemptsExceeded(): self
    {
        return new self(
            'Telefon doğrulama kodu geçersiz veya kullanılamıyor.',
            PhoneVerificationFailureReason::AttemptsExceeded,
        );
    }

    public static function phoneConflict(): self
    {
        return new self(
            'Telefon doğrulama kodu geçersiz veya kullanılamıyor.',
            PhoneVerificationFailureReason::PhoneConflict,
        );
    }

    public static function conflict(): self
    {
        return new self(
            'Telefon doğrulama kodu geçersiz veya kullanılamıyor.',
            PhoneVerificationFailureReason::Conflict,
        );
    }

    public static function phoneInvariant(): self
    {
        return new self(
            'Verified phone fields must be set or cleared together.',
            PhoneVerificationFailureReason::InvalidInput,
        );
    }
}
