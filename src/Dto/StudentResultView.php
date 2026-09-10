<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Safe released-result projection. Never includes answer keys, ciphertext, or HMAC.
 *
 * @phpstan-type ItemSummary array{
 *     attemptItemId: string,
 *     presentationPosition: int,
 *     outcome: string,
 *     awardedPoints: string,
 *     maximumPoints: string,
 *     scoringMethod: string
 * }
 */
final class StudentResultView
{
    /**
     * @param list<ItemSummary> $items
     */
    public function __construct(
        private readonly Uuid $attemptId,
        private readonly Uuid $assessmentId,
        private readonly int $releaseNumber,
        private readonly \DateTimeImmutable $releasedAt,
        private readonly string $finalPoints,
        private readonly string $maximumPoints,
        private readonly string $percentage,
        private readonly int $correctCount,
        private readonly int $incorrectCount,
        private readonly int $unansweredCount,
        private readonly array $items,
    ) {
    }

    public function getAttemptId(): Uuid
    {
        return $this->attemptId;
    }

    public function getAssessmentId(): Uuid
    {
        return $this->assessmentId;
    }

    public function getReleaseNumber(): int
    {
        return $this->releaseNumber;
    }

    public function getReleasedAt(): \DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function getFinalPoints(): string
    {
        return $this->finalPoints;
    }

    public function getMaximumPoints(): string
    {
        return $this->maximumPoints;
    }

    public function getPercentage(): string
    {
        return $this->percentage;
    }

    public function getCorrectCount(): int
    {
        return $this->correctCount;
    }

    public function getIncorrectCount(): int
    {
        return $this->incorrectCount;
    }

    public function getUnansweredCount(): int
    {
        return $this->unansweredCount;
    }

    /**
     * @return list<ItemSummary>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return array{
     *     attemptId: string,
     *     assessmentId: string,
     *     releaseNumber: int,
     *     releasedAt: string,
     *     finalPoints: string,
     *     maximumPoints: string,
     *     percentage: string,
     *     correctCount: int,
     *     incorrectCount: int,
     *     unansweredCount: int,
     *     items: list<ItemSummary>
     * }
     */
    public function toArray(): array
    {
        return [
            'attemptId' => $this->attemptId->toRfc4122(),
            'assessmentId' => $this->assessmentId->toRfc4122(),
            'releaseNumber' => $this->releaseNumber,
            'releasedAt' => $this->releasedAt->format(\DateTimeInterface::ATOM),
            'finalPoints' => $this->finalPoints,
            'maximumPoints' => $this->maximumPoints,
            'percentage' => $this->percentage,
            'correctCount' => $this->correctCount,
            'incorrectCount' => $this->incorrectCount,
            'unansweredCount' => $this->unansweredCount,
            'items' => $this->items,
        ];
    }
}
