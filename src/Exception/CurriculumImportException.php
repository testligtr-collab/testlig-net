<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Curriculum pilot import failures. Messages must not leak PII.
 */
final class CurriculumImportException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalidInput(string $message = 'Müfredat içe aktarma verisi geçersiz.'): self
    {
        return new self($message);
    }

    public static function conflict(string $message = 'Müfredat içe aktarma çakışması.'): self
    {
        return new self($message);
    }

    public static function notFound(string $message = 'Gerekli kayıt bulunamadı.'): self
    {
        return new self($message);
    }
}
