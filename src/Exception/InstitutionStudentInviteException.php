<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\InstitutionStudentInviteFailureReason;

final class InstitutionStudentInviteException extends \RuntimeException
{
    private function __construct(
        private readonly InstitutionStudentInviteFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): InstitutionStudentInviteFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(InstitutionStudentInviteFailureReason::Unauthorized, 'Student invite operation is not authorized.');
    }

    public static function invalidInput(): self
    {
        return new self(InstitutionStudentInviteFailureReason::InvalidInput, 'Student invite input is invalid.');
    }

    public static function notFound(): self
    {
        return new self(InstitutionStudentInviteFailureReason::NotFound, 'Student invite was not found.');
    }

    public static function notEligible(): self
    {
        return new self(InstitutionStudentInviteFailureReason::NotEligible, 'This person cannot be invited as a student.');
    }

    public static function gradeMismatch(): self
    {
        return new self(InstitutionStudentInviteFailureReason::GradeMismatch, 'Classroom grade does not match the student profile.');
    }

    public static function capacity(): self
    {
        return new self(InstitutionStudentInviteFailureReason::Capacity, 'Classroom capacity is full.');
    }

    public static function unavailable(): self
    {
        return new self(InstitutionStudentInviteFailureReason::Unavailable, 'Student invite is not usable.');
    }

    public static function rateLimited(): self
    {
        return new self(InstitutionStudentInviteFailureReason::RateLimited, 'Student invite rate limit exceeded.');
    }

    public static function accountMismatch(): self
    {
        return new self(InstitutionStudentInviteFailureReason::AccountMismatch, 'Student invite cannot be accepted by this account.');
    }

    public static function accountNotReady(): self
    {
        return new self(InstitutionStudentInviteFailureReason::AccountNotReady, 'Account is not active and verified.');
    }

    public static function profileNotReady(): self
    {
        return new self(InstitutionStudentInviteFailureReason::ProfileNotReady, 'Student profile onboarding is incomplete.');
    }

    public static function mailFailed(): self
    {
        return new self(InstitutionStudentInviteFailureReason::MailFailed, 'Student invite email could not be sent.');
    }

    public static function conflict(): self
    {
        return new self(InstitutionStudentInviteFailureReason::Conflict, 'Student invite conflict.');
    }
}
