<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\AssessmentResultReviewPolicy;
use App\Enum\AssessmentResultReviewFailureReason;

/**
 * Gate decision for student result review (authorization + effective policy flags).
 */
final class StudentResultReviewDecision
{
    public function __construct(
        private readonly bool $allowed,
        private readonly ?AssessmentResultReviewFailureReason $denyReason,
        private readonly ?AssessmentResultReviewPolicy $policy,
        private readonly ?\DateTimeImmutable $sensitiveRevealAt,
        private readonly bool $allowScoreSummary,
        private readonly bool $allowItemOutcomes,
        private readonly bool $allowStudentAnswer,
        private readonly bool $allowCorrectAnswer,
        private readonly bool $allowExplanation,
        private readonly bool $deliveryCancelled,
    ) {
    }

    public static function denied(AssessmentResultReviewFailureReason $reason): self
    {
        return new self(
            false,
            $reason,
            null,
            null,
            false,
            false,
            false,
            false,
            false,
            false,
        );
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getDenyReason(): ?AssessmentResultReviewFailureReason
    {
        return $this->denyReason;
    }

    public function getPolicy(): ?AssessmentResultReviewPolicy
    {
        return $this->policy;
    }

    public function getSensitiveRevealAt(): ?\DateTimeImmutable
    {
        return $this->sensitiveRevealAt;
    }

    public function allowScoreSummary(): bool
    {
        return $this->allowScoreSummary;
    }

    public function allowItemOutcomes(): bool
    {
        return $this->allowItemOutcomes;
    }

    public function allowStudentAnswer(): bool
    {
        return $this->allowStudentAnswer;
    }

    public function allowCorrectAnswer(): bool
    {
        return $this->allowCorrectAnswer;
    }

    public function allowExplanation(): bool
    {
        return $this->allowExplanation;
    }

    public function isDeliveryCancelled(): bool
    {
        return $this->deliveryCancelled;
    }
}
