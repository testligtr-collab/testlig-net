<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Safe student result-review projection governed by an active review policy.
 *
 * Never includes ciphertext, nonce, HMAC, or raw answer-key field names.
 */
final class StudentResultReviewView
{
    /**
     * @param list<StudentResultReviewItemView> $items
     */
    public function __construct(
        private readonly Uuid $attemptId,
        private readonly Uuid $assessmentId,
        private readonly Uuid $deliveryId,
        private readonly int $policyVersion,
        private readonly string $availabilityMode,
        private readonly int $releaseNumber,
        private readonly \DateTimeImmutable $releasedAt,
        private readonly bool $scoreSummaryIncluded,
        private readonly ?string $finalPoints,
        private readonly ?string $maximumPoints,
        private readonly ?string $percentage,
        private readonly ?int $correctCount,
        private readonly ?int $incorrectCount,
        private readonly ?int $unansweredCount,
        private readonly ?\DateTimeImmutable $sensitiveRevealAt,
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

    public function getDeliveryId(): Uuid
    {
        return $this->deliveryId;
    }

    public function getPolicyVersion(): int
    {
        return $this->policyVersion;
    }

    public function getAvailabilityMode(): string
    {
        return $this->availabilityMode;
    }

    public function getReleaseNumber(): int
    {
        return $this->releaseNumber;
    }

    public function getReleasedAt(): \DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function isScoreSummaryIncluded(): bool
    {
        return $this->scoreSummaryIncluded;
    }

    public function getFinalPoints(): ?string
    {
        return $this->finalPoints;
    }

    public function getMaximumPoints(): ?string
    {
        return $this->maximumPoints;
    }

    public function getPercentage(): ?string
    {
        return $this->percentage;
    }

    public function getCorrectCount(): ?int
    {
        return $this->correctCount;
    }

    public function getIncorrectCount(): ?int
    {
        return $this->incorrectCount;
    }

    public function getUnansweredCount(): ?int
    {
        return $this->unansweredCount;
    }

    public function getSensitiveRevealAt(): ?\DateTimeImmutable
    {
        return $this->sensitiveRevealAt;
    }

    /**
     * @return list<StudentResultReviewItemView>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attemptId' => $this->attemptId->toRfc4122(),
            'assessmentId' => $this->assessmentId->toRfc4122(),
            'deliveryId' => $this->deliveryId->toRfc4122(),
            'policyVersion' => $this->policyVersion,
            'availabilityMode' => $this->availabilityMode,
            'releaseNumber' => $this->releaseNumber,
            'releasedAt' => $this->releasedAt->format(\DateTimeInterface::ATOM),
            'scoreSummaryIncluded' => $this->scoreSummaryIncluded,
            'finalPoints' => $this->finalPoints,
            'maximumPoints' => $this->maximumPoints,
            'percentage' => $this->percentage,
            'correctCount' => $this->correctCount,
            'incorrectCount' => $this->incorrectCount,
            'unansweredCount' => $this->unansweredCount,
            'sensitiveRevealAt' => $this->sensitiveRevealAt?->format(\DateTimeInterface::ATOM),
            'items' => array_map(
                static fn (StudentResultReviewItemView $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
