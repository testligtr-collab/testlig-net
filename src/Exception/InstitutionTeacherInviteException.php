<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\InstitutionTeacherInviteFailureReason;

final class InstitutionTeacherInviteException extends \RuntimeException
{
    private function __construct(
        private readonly InstitutionTeacherInviteFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): InstitutionTeacherInviteFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::Unauthorized, 'Teacher invite operation is not authorized.');
    }

    public static function invalidInput(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::InvalidInput, 'Teacher invite input is invalid.');
    }

    public static function notFound(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::NotFound, 'Teacher invite was not found.');
    }

    public static function alreadyTeacher(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::AlreadyTeacher, 'An active teacher membership already exists.');
    }

    public static function notEligible(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::NotEligible, 'This person cannot be invited as a teacher.');
    }

    public static function unavailable(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::Unavailable, 'Teacher invite is not usable.');
    }

    public static function rateLimited(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::RateLimited, 'Teacher invite rate limit exceeded.');
    }

    public static function accountMismatch(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::AccountMismatch, 'Teacher invite cannot be accepted by this account.');
    }

    public static function accountNotReady(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::AccountNotReady, 'Account is not active and verified.');
    }

    public static function mailFailed(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::MailFailed, 'Teacher invite email could not be sent.');
    }

    public static function conflict(): self
    {
        return new self(InstitutionTeacherInviteFailureReason::Conflict, 'Teacher invite conflict.');
    }
}
