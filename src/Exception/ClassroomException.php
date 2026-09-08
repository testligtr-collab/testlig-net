<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\ClassroomFailureReason;

final class ClassroomException extends \RuntimeException
{
    private function __construct(
        private readonly ClassroomFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): ClassroomFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(ClassroomFailureReason::Unauthorized, 'Classroom operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid classroom input.'): self
    {
        return new self(ClassroomFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(ClassroomFailureReason::InvalidTransition, 'Classroom status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(ClassroomFailureReason::Conflict, 'Classroom operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(ClassroomFailureReason::NotFound, 'Classroom was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(ClassroomFailureReason::NotFound, 'User was not found.');
    }

    public static function crossInstitution(): self
    {
        return new self(ClassroomFailureReason::CrossInstitution, 'Cross-institution classroom operation is forbidden.');
    }

    public static function institutionNotOperable(): self
    {
        return new self(ClassroomFailureReason::InstitutionNotOperable, 'Institution does not allow classroom management in its current status.');
    }

    public static function yearNotOperable(): self
    {
        return new self(ClassroomFailureReason::YearNotOperable, 'Academic year does not allow classroom operations in its current status.');
    }

    public static function classroomNotOperable(): self
    {
        return new self(ClassroomFailureReason::ClassroomNotOperable, 'Classroom does not allow this operation in its current status.');
    }
}
