<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\SubjectFailureReason;

final class SubjectException extends \RuntimeException
{
    private function __construct(
        private readonly SubjectFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): SubjectFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(SubjectFailureReason::Unauthorized, 'Subject operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid subject input.'): self
    {
        return new self(SubjectFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(SubjectFailureReason::InvalidTransition, 'Subject status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(SubjectFailureReason::Conflict, 'Subject operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(SubjectFailureReason::NotFound, 'Subject was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(SubjectFailureReason::NotFound, 'User was not found.');
    }

    public static function subjectArchived(): self
    {
        return new self(SubjectFailureReason::SubjectArchived, 'Subject is archived and cannot be used.');
    }
}
