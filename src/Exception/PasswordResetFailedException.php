<?php

declare(strict_types=1);

namespace App\Exception;

final class PasswordResetFailedException extends \RuntimeException
{
    public static function invalidToken(): self
    {
        return new self('Doğrulama bağlantısı geçersiz veya süresi dolmuş.');
    }

    public static function sameAsCurrent(): self
    {
        return new self('Yeni parola mevcut parolanızdan farklı olmalıdır.');
    }

    public static function accountUnavailable(): self
    {
        return new self('Doğrulama bağlantısı geçersiz veya süresi dolmuş.');
    }
}
