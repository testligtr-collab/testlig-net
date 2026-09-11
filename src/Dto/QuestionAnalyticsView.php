<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AnalyticsSuppressionReason;
use Symfony\Component\Uid\Uuid;

/**
 * Per-question aggregate analytics for a delivery's active released results.
 *
 * Ordering uses immutable blueprint section/item positions (not shuffled presentation_position).
 *
 * Under cohort suppression, only identity + suppression metadata are exposed — outcome counts and
 * rates would otherwise reveal a single student's item performance when cohort size is 1–4.
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
        private readonly int $sectionPosition,
        private readonly int $itemPosition,
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

    public function getSectionPosition(): int
    {
        return $this->sectionPosition;
    }

    public function getItemPosition(): int
    {
        return $this->itemPosition;
    }

    public function getScoredResponseCount(): ?int
    {
        return $this->suppression->isSuppressed() ? null : $this->scoredResponseCount;
    }

    public function isSuppressed(): bool
    {
        return $this->suppression->isSuppressed();
    }

    public function getSuppressionReason(): ?AnalyticsSuppressionReason
    {
        return $this->suppression->getReason();
    }

    public function getOutcomes(): ?OutcomeCountBreakdown
    {
        return $this->suppression->isSuppressed() ? null : $this->outcomes;
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
            'sectionPosition' => $this->sectionPosition,
            'itemPosition' => $this->itemPosition,
            'suppressed' => $this->suppression->isSuppressed(),
        ];

        if ($this->suppression->isSuppressed() && null !== $this->suppression->getReason()) {
            $out['suppressionReason'] = $this->suppression->getReason()->value;
        }

        if (!$this->suppression->isSuppressed()) {
            $out['scoredResponseCount'] = $this->scoredResponseCount;
            $out['outcomes'] = $this->outcomes->toArray();
            $out['correctRate'] = $this->correctRate;
            if (null !== $this->optionDistribution) {
                $out['optionDistribution'] = $this->optionDistribution;
            }
        }

        return $out;
    }
}
