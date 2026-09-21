<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Invitation / participation-code digest and domain failures.
 * Messages must not leak plaintext codes or enumerate accounts.
 */
final class InvitationCodeException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalidInput(string $message = 'Davet veya katılım kodu verisi geçersiz.'): self
    {
        return new self($message);
    }

    public static function digestMisconfigured(): self
    {
        return new self('Davet/katılım kodu özet anahtarı yapılandırması geçersiz.');
    }

    public static function digestMismatch(): self
    {
        return new self('Davet veya katılım kodu doğrulanamadı.');
    }

    public static function pepperKeyMismatch(): self
    {
        return new self('Davet veya katılım kodu doğrulanamadı.');
    }

    public static function unknownPurpose(): self
    {
        return new self('Davet amacı bu işlem için tanınmıyor.');
    }

    public static function unavailable(): self
    {
        return new self('Davet veya katılım kodu kullanılamıyor.');
    }

    public static function invalidTransition(): self
    {
        return new self('Davet veya katılım kodu durumu bu işlem için uygun değil.');
    }
}
