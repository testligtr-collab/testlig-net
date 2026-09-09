<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AssessmentFailureReason;

final class AssessmentException extends \RuntimeException
{
    private function __construct(
        private readonly AssessmentFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): AssessmentFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(AssessmentFailureReason::Unauthorized, 'Assessment operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid assessment input.'): self
    {
        return new self(AssessmentFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(AssessmentFailureReason::InvalidTransition, 'Assessment status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(AssessmentFailureReason::Conflict, 'Assessment operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(AssessmentFailureReason::NotFound, 'Assessment was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(AssessmentFailureReason::NotFound, 'User was not found.');
    }

    public static function reviewSeparation(): self
    {
        return new self(AssessmentFailureReason::ReviewSeparation, 'Publisher must differ from revision author.');
    }

    public static function scopeMismatch(): self
    {
        return new self(AssessmentFailureReason::ScopeMismatch, 'Assessment scope and institution are inconsistent.');
    }

    public static function emptyAssessment(): self
    {
        return new self(AssessmentFailureReason::EmptyAssessment, 'Assessment must contain at least one section.');
    }

    public static function emptySection(): self
    {
        return new self(AssessmentFailureReason::EmptySection, 'Assessment section must contain at least one item.');
    }

    public static function duplicateQuestion(): self
    {
        return new self(AssessmentFailureReason::DuplicateQuestion, 'Question revision is already used in this assessment revision.');
    }

    public static function questionNotPublished(): self
    {
        return new self(AssessmentFailureReason::QuestionNotPublished, 'Only published questions may be used.');
    }

    public static function questionScopeMismatch(): self
    {
        return new self(AssessmentFailureReason::QuestionScopeMismatch, 'Question scope is not allowed for this assessment.');
    }

    public static function questionRevisionMismatch(): self
    {
        return new self(AssessmentFailureReason::QuestionRevisionMismatch, 'Question revision does not match the published question revision.');
    }

    public static function gradeMismatch(): self
    {
        return new self(AssessmentFailureReason::GradeMismatch, 'Question grade level does not match the assessment.');
    }

    public static function invalidPoints(string $detail = 'Assessment points are invalid.'): self
    {
        return new self(AssessmentFailureReason::InvalidPoints, $detail);
    }

    public static function revisionNotSealed(): self
    {
        return new self(AssessmentFailureReason::RevisionNotSealed, 'Assessment revision must be sealed before publish.');
    }

    public static function publicationInvalid(string $detail = 'Assessment publication is invalid.'): self
    {
        return new self(AssessmentFailureReason::PublicationInvalid, $detail);
    }

    public static function answerIntegrityFailed(): self
    {
        return new self(
            AssessmentFailureReason::AnswerIntegrityFailed,
            'Answer integrity verification failed.',
        );
    }

    public static function immutable(): self
    {
        return new self(AssessmentFailureReason::Immutable, 'Assessment revision content is immutable.');
    }
}
