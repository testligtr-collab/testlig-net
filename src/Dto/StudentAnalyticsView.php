<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Per-student analytics from the active released scoring run.
 *
 * No email/PII. userId + membershipId UUIDs are allowed identifiers.
 *
 * @phpstan-type OutcomeRow array{
 *     learningOutcomeId: string,
 *     code: string,
 *     awardedPoints: string,
 *     maximumPoints: string,
 *     percentage: string,
 *     performanceBand: string
 * }
 */
final class StudentAnalyticsView
{
    /**
     * @param list<OutcomeRow> $learningOutcomes
     */
    public function __construct(
        private readonly Uuid $attemptId,
        private readonly Uuid $deliveryId,
        private readonly Uuid $assessmentId,
        private readonly Uuid $userId,
        private readonly Uuid $membershipId,
        private readonly int $releaseNumber,
        private readonly \DateTimeImmutable $releasedAt,
        private readonly string $finalPoints,
        private readonly string $maximumPoints,
        private readonly string $percentage,
        private readonly OutcomeCountBreakdown $outcomes,
        private readonly array $learningOutcomes,
    ) {
    }

    public function getAttemptId(): Uuid
    {
        return $this->attemptId;
    }

    public function getDeliveryId(): Uuid
    {
        return $this->deliveryId;
    }

    public function getAssessmentId(): Uuid
    {
        return $this->assessmentId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getMembershipId(): Uuid
    {
        return $this->membershipId;
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

    public function getOutcomes(): OutcomeCountBreakdown
    {
        return $this->outcomes;
    }

    /**
     * @return list<OutcomeRow>
     */
    public function getLearningOutcomes(): array
    {
        return $this->learningOutcomes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attemptId' => $this->attemptId->toRfc4122(),
            'deliveryId' => $this->deliveryId->toRfc4122(),
            'assessmentId' => $this->assessmentId->toRfc4122(),
            'userId' => $this->userId->toRfc4122(),
            'membershipId' => $this->membershipId->toRfc4122(),
            'releaseNumber' => $this->releaseNumber,
            'releasedAt' => $this->releasedAt->format(\DateTimeInterface::ATOM),
            'finalPoints' => $this->finalPoints,
            'maximumPoints' => $this->maximumPoints,
            'percentage' => $this->percentage,
            'outcomes' => $this->outcomes->toArray(),
            'learningOutcomes' => $this->learningOutcomes,
        ];
    }
}
