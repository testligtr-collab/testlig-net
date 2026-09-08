<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\PasswordChangeFailureReason;

final class PasswordChangeFailedException extends \RuntimeException
{
    private function __construct(
        private readonly PasswordChangeFailureReason $reason,
        string $message = '',
    ) {
        parent::__construct('' !== $message ? $message : $reason->value);
    }

    public function getReason(): PasswordChangeFailureReason
    {
        return $this->reason;
    }

    public static function invalidCurrentPassword(): self
    {
        return new self(PasswordChangeFailureReason::InvalidCurrentPassword);
    }

    public static function sameAsCurrent(): self
    {
        return new self(PasswordChangeFailureReason::SameAsCurrent);
    }

    public static function accountUnavailable(): self
    {
        return new self(PasswordChangeFailureReason::AccountUnavailable);
    }

    public static function conflict(): self
    {
        return new self(PasswordChangeFailureReason::Conflict);
    }
}
