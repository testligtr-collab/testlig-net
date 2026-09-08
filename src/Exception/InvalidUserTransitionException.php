<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\UserManagementFailureReason;
use App\Enum\UserRole;
use App\Enum\UserStatus;

final class InvalidUserTransitionException extends \InvalidArgumentException
{
    private ?UserManagementFailureReason $reason = null;

    public static function forStatus(UserStatus $from, UserStatus $to): self
    {
        $exception = new self(\sprintf('Cannot transition user status from "%s" to "%s".', $from->value, $to->value));
        $exception->reason = UserManagementFailureReason::InvalidTransition;

        return $exception;
    }

    public static function forRole(string $message): self
    {
        $exception = new self($message);
        $exception->reason = UserManagementFailureReason::InvalidInput;

        return $exception;
    }

    public static function management(UserManagementFailureReason $reason, string $message): self
    {
        $exception = new self($message);
        $exception->reason = $reason;

        return $exception;
    }

    public static function privilegedBootstrapRole(UserRole $role): self
    {
        $exception = new self(\sprintf('Role "%s" cannot be assigned at user creation.', $role->value));
        $exception->reason = UserManagementFailureReason::InvalidInput;

        return $exception;
    }

    public function getReason(): ?UserManagementFailureReason
    {
        return $this->reason;
    }
}
