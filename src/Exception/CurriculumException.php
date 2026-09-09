<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\CurriculumFailureReason;

final class CurriculumException extends \RuntimeException
{
    private function __construct(
        private readonly CurriculumFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): CurriculumFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(CurriculumFailureReason::Unauthorized, 'Curriculum operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid curriculum input.'): self
    {
        return new self(CurriculumFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(CurriculumFailureReason::InvalidTransition, 'Curriculum status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(CurriculumFailureReason::Conflict, 'Curriculum operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(CurriculumFailureReason::NotFound, 'Curriculum program was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(CurriculumFailureReason::NotFound, 'User was not found.');
    }

    public static function subjectArchived(): self
    {
        return new self(CurriculumFailureReason::SubjectArchived, 'Subject is archived and cannot receive new curriculum programs.');
    }

    public static function programNotDraft(): self
    {
        return new self(CurriculumFailureReason::ProgramNotDraft, 'Curriculum program must be draft for this operation.');
    }

    public static function programImmutable(): self
    {
        return new self(CurriculumFailureReason::ProgramImmutable, 'Published or retired curriculum identity fields are immutable.');
    }

    public static function dateOverlap(): self
    {
        return new self(CurriculumFailureReason::DateOverlap, 'Published curriculum validity range overlaps another published program for the same subject and grade.');
    }
}
