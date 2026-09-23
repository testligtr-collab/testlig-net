<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Student course catalog domain failures. Messages must not leak other users' data.
 */
final class CatalogException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalidInput(string $message = 'Katalog verisi geçersiz.'): self
    {
        return new self($message);
    }

    public static function unauthorized(): self
    {
        return new self('Bu işlem için yetkiniz yok.');
    }

    public static function notFound(): self
    {
        return new self('Kayıt bulunamadı.');
    }

    public static function conflict(string $message = 'Katalog kaydı şu anda güncellenemiyor.'): self
    {
        return new self($message);
    }

    public static function invalidTransition(string $message = 'Bu durum geçişi izinli değil.'): self
    {
        return new self($message);
    }
}
