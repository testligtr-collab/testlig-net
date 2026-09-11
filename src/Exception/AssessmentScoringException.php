<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AssessmentScoringFailureReason;

final class AssessmentScoringException extends \RuntimeException
{
    private function __construct(
        private readonly AssessmentScoringFailureReason $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): AssessmentScoringFailureReason
    {
        return $this->reason;
    }

    public static function notFound(): self
    {
        return new self(AssessmentScoringFailureReason::NotFound, 'Assessment scoring resource was not found.');
    }

    public static function unauthorized(): self
    {
        return new self(AssessmentScoringFailureReason::Unauthorized, 'Assessment scoring operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid assessment scoring input.'): self
    {
        return new self(AssessmentScoringFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(
            AssessmentScoringFailureReason::InvalidTransition,
            'Assessment scoring status transition is not allowed.',
        );
    }

    public static function conflict(?\Throwable $previous = null): self
    {
        return new self(
            AssessmentScoringFailureReason::Conflict,
            'Assessment scoring operation conflict.',
            $previous,
        );
    }

    public static function immutable(): self
    {
        return new self(AssessmentScoringFailureReason::Immutable, 'Assessment scoring data is immutable.');
    }

    public static function scopeMismatch(): self
    {
        return new self(AssessmentScoringFailureReason::ScopeMismatch, 'Assessment scoring scope mismatch.');
    }

    public static function attemptNotScorable(): self
    {
        return new self(
            AssessmentScoringFailureReason::AttemptNotScorable,
            'Assessment attempt is not eligible for scoring.',
        );
    }

    public static function scoringInProgress(): self
    {
        return new self(
            AssessmentScoringFailureReason::ScoringInProgress,
            'An assessment scoring run is already in progress for this attempt.',
        );
    }

    public static function answerIntegrityFailed(): self
    {
        return new self(
            AssessmentScoringFailureReason::AnswerIntegrityFailed,
            'Encrypted student answer integrity verification failed.',
        );
    }

    public static function answerDecryptionFailed(): self
    {
        return new self(
            AssessmentScoringFailureReason::AnswerDecryptionFailed,
            'Encrypted student answer could not be decrypted.',
        );
    }

    public static function answerKeyIntegrityFailed(): self
    {
        return new self(
            AssessmentScoringFailureReason::AnswerKeyIntegrityFailed,
            'Question answer key integrity verification failed.',
        );
    }

    public static function publicationIntegrityFailed(): self
    {
        return new self(
            AssessmentScoringFailureReason::PublicationIntegrityFailed,
            'Assessment publication integrity verification failed.',
        );
    }

    public static function scoringFailed(): self
    {
        return new self(AssessmentScoringFailureReason::ScoringFailed, 'Assessment scoring failed.');
    }

    public static function manualGradeNotAllowed(): self
    {
        return new self(
            AssessmentScoringFailureReason::ManualGradeNotAllowed,
            'Manual grading is not allowed for this scoring item.',
        );
    }

    public static function releaseNotAllowed(): self
    {
        return new self(
            AssessmentScoringFailureReason::ReleaseNotAllowed,
            'Assessment result release is not allowed for this scoring run.',
        );
    }

    public static function resultNotReleased(): self
    {
        return new self(
            AssessmentScoringFailureReason::ResultNotReleased,
            'Assessment result is not released.',
        );
    }

    public static function itemNotFound(): self
    {
        return new self(AssessmentScoringFailureReason::ItemNotFound, 'Assessment scoring item was not found.');
    }
}
