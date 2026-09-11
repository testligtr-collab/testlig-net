<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AnalyticsSuppressionReason;
use Symfony\Component\Uid\Uuid;

/**
 * Delivery-level aggregate analytics projection (released results only).
 *
 * Sensitive percentages/distributions are omitted from toArray() when suppressed.
 */
final class AssessmentAnalyticsSummaryView
{
    /**
     * @param list<PercentageDistributionBucket>|null $distribution
     */
    public function __construct(
        private readonly Uuid $deliveryId,
        private readonly Uuid $assessmentId,
        private readonly Uuid $institutionId,
        private readonly int $eligibleRecipientCount,
        private readonly int $startedAttemptCount,
        private readonly int $completedAttemptCount,
        private readonly int $releasedResultCount,
        private readonly AnalyticsSuppression $suppression,
        private readonly ?string $participationRate,
        private readonly ?string $completionRate,
        private readonly ?string $averagePercentage,
        private readonly ?string $medianPercentage,
        private readonly ?string $minPercentage,
        private readonly ?string $maxPercentage,
        private readonly ?array $distribution,
    ) {
    }

    public function getDeliveryId(): Uuid
    {
        return $this->deliveryId;
    }

    public function getAssessmentId(): Uuid
    {
        return $this->assessmentId;
    }

    public function getInstitutionId(): Uuid
    {
        return $this->institutionId;
    }

    public function getEligibleRecipientCount(): int
    {
        return $this->eligibleRecipientCount;
    }

    public function getStartedAttemptCount(): int
    {
        return $this->startedAttemptCount;
    }

    public function getCompletedAttemptCount(): int
    {
        return $this->completedAttemptCount;
    }

    public function getReleasedResultCount(): int
    {
        return $this->releasedResultCount;
    }

    public function isSuppressed(): bool
    {
        return $this->suppression->isSuppressed();
    }

    public function getSuppressionReason(): ?AnalyticsSuppressionReason
    {
        return $this->suppression->getReason();
    }

    public function getParticipationRate(): ?string
    {
        return $this->participationRate;
    }

    public function getCompletionRate(): ?string
    {
        return $this->completionRate;
    }

    public function getAveragePercentage(): ?string
    {
        return $this->suppression->isSuppressed() ? null : $this->averagePercentage;
    }

    public function getMedianPercentage(): ?string
    {
        return $this->suppression->isSuppressed() ? null : $this->medianPercentage;
    }

    public function getMinPercentage(): ?string
    {
        return $this->suppression->isSuppressed() ? null : $this->minPercentage;
    }

    public function getMaxPercentage(): ?string
    {
        return $this->suppression->isSuppressed() ? null : $this->maxPercentage;
    }

    /**
     * @return list<PercentageDistributionBucket>|null
     */
    public function getDistribution(): ?array
    {
        return $this->suppression->isSuppressed() ? null : $this->distribution;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'deliveryId' => $this->deliveryId->toRfc4122(),
            'assessmentId' => $this->assessmentId->toRfc4122(),
            'institutionId' => $this->institutionId->toRfc4122(),
            'eligibleRecipientCount' => $this->eligibleRecipientCount,
            'startedAttemptCount' => $this->startedAttemptCount,
            'completedAttemptCount' => $this->completedAttemptCount,
            'releasedResultCount' => $this->releasedResultCount,
            'suppressed' => $this->suppression->isSuppressed(),
        ];

        if ($this->suppression->isSuppressed() && null !== $this->suppression->getReason()) {
            $out['suppressionReason'] = $this->suppression->getReason()->value;
        }

        if (null !== $this->participationRate) {
            $out['participationRate'] = $this->participationRate;
        }
        if (null !== $this->completionRate) {
            $out['completionRate'] = $this->completionRate;
        }

        if (!$this->suppression->isSuppressed()) {
            $out['averagePercentage'] = $this->averagePercentage;
            $out['medianPercentage'] = $this->medianPercentage;
            $out['minPercentage'] = $this->minPercentage;
            $out['maxPercentage'] = $this->maxPercentage;
            $out['distribution'] = array_map(
                static fn (PercentageDistributionBucket $b): array => $b->toArray(),
                $this->distribution ?? [],
            );
        }

        return $out;
    }
}
