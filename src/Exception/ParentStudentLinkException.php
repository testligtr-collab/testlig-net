<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Parent–student link domain failures.
 * Messages must not enumerate accounts or leak invitation codes.
 */
final class ParentStudentLinkException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalidInput(string $message = 'Veli–öğrenci ilişki verisi geçersiz.'): self
    {
        return new self($message);
    }

    public static function invalidTransition(): self
    {
        return new self('Veli–öğrenci ilişki durumu bu işlem için uygun değil.');
    }

    public static function unauthorized(): self
    {
        return new self('Bu veli–öğrenci işlemi için yetkiniz yok.');
    }

    public static function unavailable(): self
    {
        return new self('Davet veya bağlantı kullanılamıyor.');
    }

    public static function conflict(): self
    {
        return new self('Veli–öğrenci ilişkisi için çakışma oluştu.');
    }

    public static function rateLimited(): self
    {
        return new self('Çok fazla deneme. Lütfen daha sonra tekrar deneyin.');
    }
}
