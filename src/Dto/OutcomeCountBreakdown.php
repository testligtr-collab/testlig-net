<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Item-score outcome tally for analytics projections.
 */
final class OutcomeCountBreakdown
{
    public function __construct(
        private readonly int $correctCount,
        private readonly int $incorrectCount,
        private readonly int $unansweredCount,
        private readonly int $manualPendingCount = 0,
        private readonly int $manuallyGradedCount = 0,
        private readonly int $invalidCount = 0,
    ) {
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

    public function getManualPendingCount(): int
    {
        return $this->manualPendingCount;
    }

    public function getManuallyGradedCount(): int
    {
        return $this->manuallyGradedCount;
    }

    public function getInvalidCount(): int
    {
        return $this->invalidCount;
    }

    /**
     * @return array{
     *     correctCount: int,
     *     incorrectCount: int,
     *     unansweredCount: int,
     *     manualPendingCount: int,
     *     manuallyGradedCount: int,
     *     invalidCount: int
     * }
     */
    public function toArray(): array
    {
        return [
            'correctCount' => $this->correctCount,
            'incorrectCount' => $this->incorrectCount,
            'unansweredCount' => $this->unansweredCount,
            'manualPendingCount' => $this->manualPendingCount,
            'manuallyGradedCount' => $this->manuallyGradedCount,
            'invalidCount' => $this->invalidCount,
        ];
    }
}
