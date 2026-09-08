<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\PasswordResetFailureReason;

final class PasswordResetFailedException extends \RuntimeException
{
    private function __construct(
        private readonly PasswordResetFailureReason $reason,
        string $message = '',
    ) {
        parent::__construct('' !== $message ? $message : $reason->value);
    }

    public function getReason(): PasswordResetFailureReason
    {
        return $this->reason;
    }

    public static function invalidToken(): self
    {
        return new self(PasswordResetFailureReason::InvalidToken);
    }

    public static function sameAsCurrent(): self
    {
        return new self(PasswordResetFailureReason::SameAsCurrent);
    }

    public static function accountUnavailable(): self
    {
        return new self(PasswordResetFailureReason::AccountUnavailable);
    }

    public static function conflict(): self
    {
        return new self(PasswordResetFailureReason::Conflict);
    }
}
