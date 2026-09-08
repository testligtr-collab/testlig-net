<?php

declare(strict_types=1);

namespace App\Exception;

final class RegistrationFailedException extends \RuntimeException
{
    public static function duplicateEmail(): self
    {
        return new self('registration.duplicate_or_invalid');
    }
}
