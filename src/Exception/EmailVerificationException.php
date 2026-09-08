<?php

declare(strict_types=1);

namespace App\Exception;

final class EmailVerificationException extends \RuntimeException
{
    public static function invalid(): self
    {
        return new self('Doğrulama bağlantısı geçersiz veya süresi dolmuş.');
    }

    public static function alreadyVerified(): self
    {
        return new self('Bu hesap zaten doğrulanmış.');
    }
}
