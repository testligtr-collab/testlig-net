<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\ClassroomStudentFailureReason;

final class ClassroomStudentEnrollmentException extends \RuntimeException
{
    private function __construct(
        private readonly ClassroomStudentFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): ClassroomStudentFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(ClassroomStudentFailureReason::Unauthorized, 'Classroom student enrollment operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid classroom student enrollment input.'): self
    {
        return new self(ClassroomStudentFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(ClassroomStudentFailureReason::InvalidTransition, 'Student enrollment status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(ClassroomStudentFailureReason::Conflict, 'Classroom student enrollment conflict.');
    }

    public static function notFound(): self
    {
        return new self(ClassroomStudentFailureReason::NotFound, 'Student enrollment was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(ClassroomStudentFailureReason::NotFound, 'User was not found.');
    }

    public static function crossInstitution(): self
    {
        return new self(ClassroomStudentFailureReason::CrossInstitution, 'Cross-institution student enrollment is forbidden.');
    }

    public static function institutionNotOperable(): self
    {
        return new self(ClassroomStudentFailureReason::InstitutionNotOperable, 'Institution does not allow student enrollment in its current status.');
    }

    public static function yearNotOperable(): self
    {
        return new self(ClassroomStudentFailureReason::YearNotOperable, 'Academic year does not allow student enrollment in its current status.');
    }

    public static function classroomNotOperable(): self
    {
        return new self(ClassroomStudentFailureReason::ClassroomNotOperable, 'Classroom does not allow student enrollment in its current status.');
    }

    public static function membershipNotEligible(): self
    {
        return new self(ClassroomStudentFailureReason::MembershipNotEligible, 'Membership is not eligible for classroom student enrollment.');
    }

    public static function capacityExceeded(): self
    {
        return new self(ClassroomStudentFailureReason::CapacityExceeded, 'Classroom capacity would be exceeded.');
    }
}
