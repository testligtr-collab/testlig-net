<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Per-item projection for student result review.
 */
final class StudentResultReviewItemView
{
    /**
     * @param array<string, mixed>|null $studentAnswer
     * @param array<string, mixed>|null $explanation
     */
    public function __construct(
        private readonly string $attemptItemId,
        private readonly int $presentationPosition,
        private readonly string $outcome,
        private readonly string $awardedPoints,
        private readonly string $maximumPoints,
        private readonly string $scoringMethod,
        private readonly ?array $studentAnswer,
        private readonly ?CorrectAnswerPresentation $correctAnswer,
        private readonly ?array $explanation,
    ) {
    }

    public function getAttemptItemId(): string
    {
        return $this->attemptItemId;
    }

    public function getPresentationPosition(): int
    {
        return $this->presentationPosition;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getAwardedPoints(): string
    {
        return $this->awardedPoints;
    }

    public function getMaximumPoints(): string
    {
        return $this->maximumPoints;
    }

    public function getScoringMethod(): string
    {
        return $this->scoringMethod;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStudentAnswer(): ?array
    {
        return $this->studentAnswer;
    }

    public function getCorrectAnswer(): ?CorrectAnswerPresentation
    {
        return $this->correctAnswer;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExplanation(): ?array
    {
        return $this->explanation;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attemptItemId' => $this->attemptItemId,
            'presentationPosition' => $this->presentationPosition,
            'outcome' => $this->outcome,
            'awardedPoints' => $this->awardedPoints,
            'maximumPoints' => $this->maximumPoints,
            'scoringMethod' => $this->scoringMethod,
            'studentAnswer' => $this->studentAnswer,
            'correctAnswer' => $this->correctAnswer?->toArray(),
            'explanation' => $this->explanation,
        ];
    }
}
