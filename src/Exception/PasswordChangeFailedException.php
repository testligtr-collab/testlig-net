<?php

declare(strict_types=1);

namespace App\Exception;

final class PasswordChangeFailedException extends \RuntimeException
{
    public static function invalidCurrentPassword(): self
    {
        return new self('password.invalid_current');
    }

    public static function sameAsCurrent(): self
    {
        return new self('password.same_as_current');
    }

    public static function accountUnavailable(): self
    {
        return new self('password.account_unavailable');
    }
}
