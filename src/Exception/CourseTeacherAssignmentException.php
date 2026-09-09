<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\CourseTeacherAssignmentFailureReason;

final class CourseTeacherAssignmentException extends \RuntimeException
{
    private function __construct(
        private readonly CourseTeacherAssignmentFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): CourseTeacherAssignmentFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::Unauthorized, 'Course teacher assignment operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid course teacher assignment input.'): self
    {
        return new self(CourseTeacherAssignmentFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::InvalidTransition, 'Course teacher assignment status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::Conflict, 'Course teacher assignment operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::NotFound, 'Course teacher assignment was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::NotFound, 'User was not found.');
    }

    public static function crossInstitution(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::CrossInstitution, 'Cross-institution course teacher assignment is forbidden.');
    }

    public static function institutionNotOperable(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::InstitutionNotOperable, 'Institution does not allow course teacher assignments in its current status.');
    }

    public static function yearNotOperable(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::YearNotOperable, 'Academic year does not allow course teacher assignments in its current status.');
    }

    public static function classroomNotOperable(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::ClassroomNotOperable, 'Classroom does not allow course teacher assignments in its current status.');
    }

    public static function courseNotOperable(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::CourseNotOperable, 'Classroom course does not allow teacher assignments in its current status.');
    }

    public static function curriculumNotPublished(): self
    {
        return new self(CourseTeacherAssignmentFailureReason::CurriculumNotPublished, 'Course teacher assignment requires a published (non-retired) curriculum program.');
    }
}
