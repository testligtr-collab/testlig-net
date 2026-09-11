<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Safe student result-review projection governed by an active review policy.
 *
 * Never includes ciphertext, nonce, HMAC, or raw answer-key field names.
 * Score summary keys are omitted entirely when scoreSummaryIncluded is false.
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
        return $this->scoreSummaryIncluded ? $this->finalPoints : null;
    }

    public function getMaximumPoints(): ?string
    {
        return $this->scoreSummaryIncluded ? $this->maximumPoints : null;
    }

    public function getPercentage(): ?string
    {
        return $this->scoreSummaryIncluded ? $this->percentage : null;
    }

    public function getCorrectCount(): ?int
    {
        return $this->scoreSummaryIncluded ? $this->correctCount : null;
    }

    public function getIncorrectCount(): ?int
    {
        return $this->scoreSummaryIncluded ? $this->incorrectCount : null;
    }

    public function getUnansweredCount(): ?int
    {
        return $this->scoreSummaryIncluded ? $this->unansweredCount : null;
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
        $out = [
            'attemptId' => $this->attemptId->toRfc4122(),
            'assessmentId' => $this->assessmentId->toRfc4122(),
            'deliveryId' => $this->deliveryId->toRfc4122(),
            'policyVersion' => $this->policyVersion,
            'availabilityMode' => $this->availabilityMode,
            'releaseNumber' => $this->releaseNumber,
            'releasedAt' => $this->releasedAt->format(\DateTimeInterface::ATOM),
            'scoreSummaryIncluded' => $this->scoreSummaryIncluded,
        ];

        if ($this->scoreSummaryIncluded) {
            $out['finalPoints'] = $this->finalPoints;
            $out['maximumPoints'] = $this->maximumPoints;
            $out['percentage'] = $this->percentage;
            $out['correctCount'] = $this->correctCount;
            $out['incorrectCount'] = $this->incorrectCount;
            $out['unansweredCount'] = $this->unansweredCount;
        }

        if (null !== $this->sensitiveRevealAt) {
            $out['sensitiveRevealAt'] = $this->sensitiveRevealAt->format(\DateTimeInterface::ATOM);
        }

        $out['items'] = array_map(
            static fn (StudentResultReviewItemView $item): array => $item->toArray(),
            $this->items,
        );

        return $out;
    }
}
