<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\ClassroomCourseFailureReason;

final class ClassroomCourseException extends \RuntimeException
{
    private function __construct(
        private readonly ClassroomCourseFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): ClassroomCourseFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(ClassroomCourseFailureReason::Unauthorized, 'Classroom course operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid classroom course input.'): self
    {
        return new self(ClassroomCourseFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(ClassroomCourseFailureReason::InvalidTransition, 'Classroom course status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(ClassroomCourseFailureReason::Conflict, 'Classroom course operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(ClassroomCourseFailureReason::NotFound, 'Classroom course was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(ClassroomCourseFailureReason::NotFound, 'User was not found.');
    }

    public static function crossInstitution(): self
    {
        return new self(ClassroomCourseFailureReason::CrossInstitution, 'Cross-institution classroom course operation is forbidden.');
    }

    public static function institutionNotOperable(): self
    {
        return new self(ClassroomCourseFailureReason::InstitutionNotOperable, 'Institution does not allow classroom course management in its current status.');
    }

    public static function yearNotOperable(): self
    {
        return new self(ClassroomCourseFailureReason::YearNotOperable, 'Academic year does not allow classroom course operations in its current status.');
    }

    public static function classroomNotOperable(): self
    {
        return new self(ClassroomCourseFailureReason::ClassroomNotOperable, 'Classroom does not allow course operations in its current status.');
    }

    public static function courseNotOperable(): self
    {
        return new self(ClassroomCourseFailureReason::CourseNotOperable, 'Classroom course does not allow this operation in its current status.');
    }

    public static function curriculumNotPublished(): self
    {
        return new self(ClassroomCourseFailureReason::CurriculumNotPublished, 'Classroom course requires a published curriculum program.');
    }

    public static function gradeMismatch(): self
    {
        return new self(ClassroomCourseFailureReason::GradeMismatch, 'Curriculum grade level does not match the classroom.');
    }

    public static function subjectMismatch(): self
    {
        return new self(ClassroomCourseFailureReason::SubjectMismatch, 'Curriculum subject does not match the classroom course subject.');
    }

    public static function activeTeachers(): self
    {
        return new self(ClassroomCourseFailureReason::ActiveTeachers, 'Cannot archive a classroom course with active teacher assignments.');
    }

    public static function subjectArchived(): self
    {
        return new self(ClassroomCourseFailureReason::SubjectArchived, 'Subject is archived and cannot be used for classroom courses.');
    }
}
