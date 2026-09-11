<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AssessmentResultReviewFailureReason;

final class AssessmentResultReviewException extends \RuntimeException
{
    private function __construct(
        private readonly AssessmentResultReviewFailureReason $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): AssessmentResultReviewFailureReason
    {
        return $this->reason;
    }

    public static function notFound(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::NotFound,
            'Assessment result review resource was not found.',
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::Unauthorized,
            'Assessment result review operation is not authorized.',
        );
    }

    public static function scopeMismatch(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::ScopeMismatch,
            'Assessment result review scope mismatch.',
        );
    }

    public static function resultNotReleased(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::ResultNotReleased,
            'Assessment result is not released.',
        );
    }

    public static function resultWithdrawn(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::ResultWithdrawn,
            'Assessment result release was withdrawn.',
        );
    }

    public static function reviewPolicyNotActive(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::ReviewPolicyNotActive,
            'No active assessment result review policy for this delivery.',
        );
    }

    public static function reviewNotAvailable(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::ReviewNotAvailable,
            'Assessment result review is not available.',
        );
    }

    public static function deliveryNotSafelyClosed(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::DeliveryNotSafelyClosed,
            'Delivery is not safely closed for sensitive result review.',
        );
    }

    public static function itemReviewNotAllowed(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::ItemReviewNotAllowed,
            'Item-level result review is not allowed by policy.',
        );
    }

    public static function studentAnswerNotAllowed(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::StudentAnswerNotAllowed,
            'Student answer review is not allowed by policy.',
        );
    }

    public static function correctAnswerNotAllowed(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::CorrectAnswerNotAllowed,
            'Correct answer review is not allowed by policy or timing.',
        );
    }

    public static function explanationNotAllowed(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::ExplanationNotAllowed,
            'Explanation review is not allowed by policy or timing.',
        );
    }

    public static function policyIntegrityFailed(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::PolicyIntegrityFailed,
            'Assessment result review policy integrity verification failed.',
        );
    }

    public static function answerIntegrityFailed(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::AnswerIntegrityFailed,
            'Answer key integrity verification failed during result review.',
        );
    }

    public static function answerDecryptionFailed(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::AnswerDecryptionFailed,
            'Encrypted student answer could not be decrypted during result review.',
        );
    }

    public static function invalidTransition(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::InvalidTransition,
            'Assessment result review policy status transition is not allowed.',
        );
    }

    public static function invalidInput(string $detail = 'Invalid assessment result review input.'): self
    {
        return new self(AssessmentResultReviewFailureReason::InvalidInput, $detail);
    }

    public static function conflict(?\Throwable $previous = null): self
    {
        return new self(
            AssessmentResultReviewFailureReason::Conflict,
            'Assessment result review operation conflict.',
            $previous,
        );
    }

    public static function immutable(): self
    {
        return new self(
            AssessmentResultReviewFailureReason::InvalidTransition,
            'Assessment result review policy data is immutable.',
        );
    }
}
