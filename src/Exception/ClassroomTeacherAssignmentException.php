<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\ClassroomTeacherFailureReason;

final class ClassroomTeacherAssignmentException extends \RuntimeException
{
    private function __construct(
        private readonly ClassroomTeacherFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): ClassroomTeacherFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(ClassroomTeacherFailureReason::Unauthorized, 'Classroom teacher assignment operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid classroom teacher assignment input.'): self
    {
        return new self(ClassroomTeacherFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(ClassroomTeacherFailureReason::InvalidTransition, 'Teacher assignment status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(ClassroomTeacherFailureReason::Conflict, 'Classroom teacher assignment conflict.');
    }

    public static function notFound(): self
    {
        return new self(ClassroomTeacherFailureReason::NotFound, 'Teacher assignment was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(ClassroomTeacherFailureReason::NotFound, 'User was not found.');
    }

    public static function crossInstitution(): self
    {
        return new self(ClassroomTeacherFailureReason::CrossInstitution, 'Cross-institution teacher assignment is forbidden.');
    }

    public static function institutionNotOperable(): self
    {
        return new self(ClassroomTeacherFailureReason::InstitutionNotOperable, 'Institution does not allow teacher assignment in its current status.');
    }

    public static function yearNotOperable(): self
    {
        return new self(ClassroomTeacherFailureReason::YearNotOperable, 'Academic year does not allow teacher assignment in its current status.');
    }

    public static function classroomNotOperable(): self
    {
        return new self(ClassroomTeacherFailureReason::ClassroomNotOperable, 'Classroom does not allow teacher assignment in its current status.');
    }

    public static function membershipNotEligible(): self
    {
        return new self(ClassroomTeacherFailureReason::MembershipNotEligible, 'Membership is not eligible for classroom teacher assignment.');
    }
}
