<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Per-item projection for student result review.
 *
 * Sensitive keys are omitted from toArray() when policy/time denies them —
 * never emitted as null placeholders.
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
        private readonly bool $includeStudentAnswer,
        private readonly bool $includeCorrectAnswer,
        private readonly bool $includeExplanation,
        private readonly ?array $studentAnswer = null,
        private readonly ?CorrectAnswerPresentation $correctAnswer = null,
        private readonly ?array $explanation = null,
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

    public function includesStudentAnswer(): bool
    {
        return $this->includeStudentAnswer;
    }

    public function includesCorrectAnswer(): bool
    {
        return $this->includeCorrectAnswer;
    }

    public function includesExplanation(): bool
    {
        return $this->includeExplanation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStudentAnswer(): ?array
    {
        return $this->includeStudentAnswer ? $this->studentAnswer : null;
    }

    public function getCorrectAnswer(): ?CorrectAnswerPresentation
    {
        return $this->includeCorrectAnswer ? $this->correctAnswer : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExplanation(): ?array
    {
        return $this->includeExplanation ? $this->explanation : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'attemptItemId' => $this->attemptItemId,
            'presentationPosition' => $this->presentationPosition,
            'outcome' => $this->outcome,
            'awardedPoints' => $this->awardedPoints,
            'maximumPoints' => $this->maximumPoints,
            'scoringMethod' => $this->scoringMethod,
        ];

        if ($this->includeStudentAnswer) {
            $out['studentAnswer'] = $this->studentAnswer;
        }
        if ($this->includeCorrectAnswer) {
            $out['correctAnswer'] = $this->correctAnswer?->toArray();
        }
        if ($this->includeExplanation) {
            $out['explanation'] = $this->explanation;
        }

        return $out;
    }
}
