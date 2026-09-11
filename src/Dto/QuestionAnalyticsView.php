<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AnalyticsSuppressionReason;
use Symfony\Component\Uid\Uuid;

/**
 * Per-question aggregate analytics for a delivery's active released results.
 *
 * Option distribution is intentionally omitted in Stage 2.14: selectedStableKey is only
 * available inside encrypted attempt answers; aggregate option counts would require decrypt.
 *
 * @phpstan-type OptionCount array{stableKey: string, count: int}
 */
final class QuestionAnalyticsView
{
    /**
     * @param list<OptionCount>|null $optionDistribution
     */
    public function __construct(
        private readonly Uuid $questionId,
        private readonly Uuid $questionRevisionId,
        private readonly int $presentationPosition,
        private readonly int $scoredResponseCount,
        private readonly AnalyticsSuppression $suppression,
        private readonly OutcomeCountBreakdown $outcomes,
        private readonly ?string $correctRate,
        private readonly ?array $optionDistribution = null,
    ) {
    }

    public function getQuestionId(): Uuid
    {
        return $this->questionId;
    }

    public function getQuestionRevisionId(): Uuid
    {
        return $this->questionRevisionId;
    }

    public function getPresentationPosition(): int
    {
        return $this->presentationPosition;
    }

    public function getScoredResponseCount(): int
    {
        return $this->scoredResponseCount;
    }

    public function isSuppressed(): bool
    {
        return $this->suppression->isSuppressed();
    }

    public function getSuppressionReason(): ?AnalyticsSuppressionReason
    {
        return $this->suppression->getReason();
    }

    public function getOutcomes(): OutcomeCountBreakdown
    {
        return $this->outcomes;
    }

    public function getCorrectRate(): ?string
    {
        return $this->suppression->isSuppressed() ? null : $this->correctRate;
    }

    /**
     * @return list<OptionCount>|null
     */
    public function getOptionDistribution(): ?array
    {
        if ($this->suppression->isSuppressed()) {
            return null;
        }

        return $this->optionDistribution;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'questionId' => $this->questionId->toRfc4122(),
            'questionRevisionId' => $this->questionRevisionId->toRfc4122(),
            'presentationPosition' => $this->presentationPosition,
            'scoredResponseCount' => $this->scoredResponseCount,
            'suppressed' => $this->suppression->isSuppressed(),
            'outcomes' => $this->outcomes->toArray(),
        ];

        if ($this->suppression->isSuppressed() && null !== $this->suppression->getReason()) {
            $out['suppressionReason'] = $this->suppression->getReason()->value;
        }

        if (!$this->suppression->isSuppressed()) {
            $out['correctRate'] = $this->correctRate;
            if (null !== $this->optionDistribution) {
                $out['optionDistribution'] = $this->optionDistribution;
            }
        }

        return $out;
    }
}
