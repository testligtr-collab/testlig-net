<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\QuestionFailureReason;

final class QuestionException extends \RuntimeException
{
    private function __construct(
        private readonly QuestionFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): QuestionFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(QuestionFailureReason::Unauthorized, 'Question operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid question input.'): self
    {
        return new self(QuestionFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(QuestionFailureReason::InvalidTransition, 'Question status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(QuestionFailureReason::Conflict, 'Question operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(QuestionFailureReason::NotFound, 'Question was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(QuestionFailureReason::NotFound, 'User was not found.');
    }

    public static function reviewSeparation(): self
    {
        return new self(QuestionFailureReason::ReviewSeparation, 'Publisher must differ from revision author.');
    }

    public static function scopeMismatch(): self
    {
        return new self(QuestionFailureReason::ScopeMismatch, 'Question scope and institution are inconsistent.');
    }

    public static function alignmentInvalid(string $detail = 'Question alignment is invalid.'): self
    {
        return new self(QuestionFailureReason::AlignmentInvalid, $detail);
    }

    public static function curriculumNotPublished(): self
    {
        return new self(QuestionFailureReason::CurriculumNotPublished, 'Publishing requires a published curriculum program.');
    }

    public static function answerInvalid(string $detail = 'Answer key is invalid for question type.'): self
    {
        return new self(QuestionFailureReason::AnswerInvalid, $detail);
    }

    public static function contentInvalid(string $detail = 'Question content is invalid.'): self
    {
        return new self(QuestionFailureReason::ContentInvalid, $detail);
    }

    public static function immutable(): self
    {
        return new self(QuestionFailureReason::Immutable, 'Question revision content is immutable.');
    }
}
