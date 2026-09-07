<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\UserRole;
use App\Enum\UserStatus;

final class InvalidUserTransitionException extends \InvalidArgumentException
{
    public static function forStatus(UserStatus $from, UserStatus $to): self
    {
        return new self(\sprintf('Cannot transition user status from "%s" to "%s".', $from->value, $to->value));
    }

    public static function forRole(string $message): self
    {
        return new self($message);
    }

    public static function privilegedBootstrapRole(UserRole $role): self
    {
        return new self(\sprintf('Role "%s" cannot be assigned at user creation.', $role->value));
    }
}
