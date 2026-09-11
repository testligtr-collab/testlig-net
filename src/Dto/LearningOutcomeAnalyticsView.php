<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AnalyticsSuppressionReason;
use App\Enum\LearningOutcomePerformanceBand;
use Symfony\Component\Uid\Uuid;

/**
 * Learning-outcome aggregate from item scores joined to question revision alignments.
 */
final class LearningOutcomeAnalyticsView
{
    public function __construct(
        private readonly Uuid $learningOutcomeId,
        private readonly string $code,
        private readonly int $alignedItemScoreCount,
        private readonly AnalyticsSuppression $suppression,
        private readonly ?string $awardedPointsSum,
        private readonly ?string $maximumPointsSum,
        private readonly ?string $percentage,
        private readonly ?LearningOutcomePerformanceBand $performanceBand,
    ) {
    }

    public function getLearningOutcomeId(): Uuid
    {
        return $this->learningOutcomeId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getAlignedItemScoreCount(): int
    {
        return $this->alignedItemScoreCount;
    }

    public function isSuppressed(): bool
    {
        return $this->suppression->isSuppressed();
    }

    public function getSuppressionReason(): ?AnalyticsSuppressionReason
    {
        return $this->suppression->getReason();
    }

    public function getAwardedPointsSum(): ?string
    {
        return $this->suppression->isSuppressed() ? null : $this->awardedPointsSum;
    }

    public function getMaximumPointsSum(): ?string
    {
        return $this->suppression->isSuppressed() ? null : $this->maximumPointsSum;
    }

    public function getPercentage(): ?string
    {
        return $this->suppression->isSuppressed() ? null : $this->percentage;
    }

    public function getPerformanceBand(): ?LearningOutcomePerformanceBand
    {
        return $this->suppression->isSuppressed() ? null : $this->performanceBand;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'learningOutcomeId' => $this->learningOutcomeId->toRfc4122(),
            'code' => $this->code,
            'alignedItemScoreCount' => $this->alignedItemScoreCount,
            'suppressed' => $this->suppression->isSuppressed(),
        ];

        if ($this->suppression->isSuppressed() && null !== $this->suppression->getReason()) {
            $out['suppressionReason'] = $this->suppression->getReason()->value;
        }

        if (!$this->suppression->isSuppressed()) {
            $out['awardedPointsSum'] = $this->awardedPointsSum;
            $out['maximumPointsSum'] = $this->maximumPointsSum;
            $out['percentage'] = $this->percentage;
            $out['performanceBand'] = $this->performanceBand?->value;
        }

        return $out;
    }
}
