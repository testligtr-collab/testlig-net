<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AnalyticsFailureReason;

final class AssessmentAnalyticsException extends \RuntimeException
{
    private function __construct(
        private readonly AnalyticsFailureReason $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): AnalyticsFailureReason
    {
        return $this->reason;
    }

    public static function notFound(): self
    {
        return new self(
            AnalyticsFailureReason::NotFound,
            'Assessment analytics resource was not found.',
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            AnalyticsFailureReason::Unauthorized,
            'Assessment analytics operation is not authorized.',
        );
    }

    public static function scopeMismatch(): self
    {
        return new self(
            AnalyticsFailureReason::ScopeMismatch,
            'Assessment analytics scope mismatch.',
        );
    }

    public static function resultNotReleased(): self
    {
        return new self(
            AnalyticsFailureReason::ResultNotReleased,
            'Assessment result is not released.',
        );
    }

    public static function resultWithdrawn(): self
    {
        return new self(
            AnalyticsFailureReason::ResultWithdrawn,
            'Assessment result release was withdrawn.',
        );
    }

    public static function analyticsUnavailable(): self
    {
        return new self(
            AnalyticsFailureReason::AnalyticsUnavailable,
            'Assessment analytics is not available.',
        );
    }

    public static function cohortSuppressed(): self
    {
        return new self(
            AnalyticsFailureReason::CohortSuppressed,
            'Assessment analytics cohort is below the privacy threshold.',
        );
    }

    public static function invalidInput(string $detail = 'Invalid assessment analytics input.'): self
    {
        return new self(AnalyticsFailureReason::InvalidInput, $detail);
    }

    public static function conflict(?\Throwable $previous = null): self
    {
        return new self(
            AnalyticsFailureReason::Conflict,
            'Assessment analytics operation conflict.',
            $previous,
        );
    }
}
