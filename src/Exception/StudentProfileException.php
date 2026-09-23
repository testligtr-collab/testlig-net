<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Student profile domain failures. Messages must not leak other users' data.
 */
final class StudentProfileException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalidInput(string $message = 'Profil bilgisi geçersiz.'): self
    {
        return new self($message);
    }

    public static function notStudent(): self
    {
        return new self('Öğrenci profili yalnız öğrenci hesapları için oluşturulabilir.');
    }

    public static function unauthorized(): self
    {
        return new self('Bu işlem için yetkiniz yok.');
    }

    public static function notFound(): self
    {
        return new self('Öğrenci profili bulunamadı.');
    }

    public static function conflict(): self
    {
        return new self('Profil şu anda güncellenemiyor.');
    }
}
