<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Onboarding application failures. Messages must not enumerate accounts or leak PII.
 */
final class OnboardingApplicationException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalidInput(string $message = 'Başvuru verisi geçersiz.'): self
    {
        return new self($message);
    }

    public static function unauthorized(): self
    {
        return new self('Bu işlem için yetkiniz yok.');
    }

    public static function conflict(): self
    {
        return new self('Başvuru şu anda işlenemiyor.');
    }

    public static function notFound(): self
    {
        return new self('Başvuru bulunamadı.');
    }

    public static function invalidTransition(): self
    {
        return new self('Başvuru durumu bu işlem için uygun değil.');
    }

    public static function applicantNotEligible(): self
    {
        return new self('Başvuru için hesap durumu uygun değil.');
    }
}
