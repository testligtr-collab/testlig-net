<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Phone verification / binding failures. Messages must never include OTP or raw phone.
 */
final class PhoneVerificationException extends \RuntimeException
{
    public static function invalidInput(string $message = 'Telefon doğrulama verisi geçersiz.'): self
    {
        return new self($message);
    }

    public static function digestMisconfigured(): self
    {
        return new self('Phone OTP pepper is misconfigured.');
    }

    public static function digestMismatch(): self
    {
        return new self('Telefon doğrulama kodu geçersiz veya kullanılamıyor.');
    }

    public static function phoneInvariant(): self
    {
        return new self('Verified phone fields must be set or cleared together.');
    }
}
