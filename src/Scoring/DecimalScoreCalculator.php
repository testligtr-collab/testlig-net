<?php

declare(strict_types=1);

namespace App\Scoring;

use App\Enum\ItemScoreOutcome;
use App\Exception\AssessmentScoringException;

/**
 * Aggregate score policy testlig_default_v1 (bcmath only; no PHP float).
 *
 * - Points scale 2, percentage scale 4
 * - rawPoints = sum(awarded)
 * - finalPoints = max(0, rawPoints)
 * - percentage = finalPoints / maximumPoints * 100
 */
final class DecimalScoreCalculator
{
    public const POLICY_ID = 'testlig_default_v1';

    public const POINTS_SCALE = 2;

    public const PERCENTAGE_SCALE = 4;

    /**
     * @param list<array{
     *     awardedPoints: string,
     *     maximumPoints: string,
     *     outcome: ItemScoreOutcome
     * }> $items
     *
     * @return array{
     *     rawPoints: numeric-string,
     *     finalPoints: numeric-string,
     *     maximumPoints: numeric-string,
     *     percentage: numeric-string,
     *     correctCount: int,
     *     incorrectCount: int,
     *     unansweredCount: int,
     *     manualPendingCount: int
     * }
     */
    public function calculate(array $items): array
    {
        if (!\extension_loaded('bcmath')) {
            throw AssessmentScoringException::scoringFailed();
        }
        if ([] === $items) {
            throw AssessmentScoringException::invalidInput('Scoring requires at least one item.');
        }

        $rawPoints = '0.00';
        $maximumPoints = '0.00';
        $correctCount = 0;
        $incorrectCount = 0;
        $unansweredCount = 0;
        $manualPendingCount = 0;

        foreach ($items as $item) {
            $awarded = $this->assertDecimal($item['awardedPoints'], self::POINTS_SCALE);
            $maximum = $this->assertDecimal($item['maximumPoints'], self::POINTS_SCALE);
            if (-1 === bccomp($maximum, '0', self::POINTS_SCALE)) {
                throw AssessmentScoringException::invalidInput('Item maximumPoints must be >= 0.');
            }

            $rawPoints = bcadd($rawPoints, $awarded, self::POINTS_SCALE);
            $maximumPoints = bcadd($maximumPoints, $maximum, self::POINTS_SCALE);

            match ($item['outcome']) {
                ItemScoreOutcome::Correct => ++$correctCount,
                ItemScoreOutcome::Incorrect, ItemScoreOutcome::Invalid => ++$incorrectCount,
                ItemScoreOutcome::Unanswered => ++$unansweredCount,
                ItemScoreOutcome::ManualPending => ++$manualPendingCount,
                ItemScoreOutcome::ManuallyGraded => null,
            };
        }

        if (1 !== bccomp($maximumPoints, '0', self::POINTS_SCALE)) {
            throw AssessmentScoringException::invalidInput('maximumPoints must be greater than 0.');
        }

        $finalPoints = -1 === bccomp($rawPoints, '0', self::POINTS_SCALE)
            ? bcadd('0', '0', self::POINTS_SCALE)
            : $rawPoints;

        $percentage = bcmul(
            bcdiv($finalPoints, $maximumPoints, self::PERCENTAGE_SCALE + 4),
            '100',
            self::PERCENTAGE_SCALE,
        );

        return [
            'rawPoints' => $rawPoints,
            'finalPoints' => $finalPoints,
            'maximumPoints' => $maximumPoints,
            'percentage' => $percentage,
            'correctCount' => $correctCount,
            'incorrectCount' => $incorrectCount,
            'unansweredCount' => $unansweredCount,
            'manualPendingCount' => $manualPendingCount,
        ];
    }

    /**
     * @return numeric-string
     */
    public function normalizePoints(string $raw): string
    {
        return $this->assertDecimal($raw, self::POINTS_SCALE);
    }

    /**
     * Awarded points for a correct automatic answer.
     *
     * @param numeric-string $maximumPoints
     *
     * @return numeric-string
     */
    public function awardedForCorrect(string $maximumPoints): string
    {
        return $this->assertDecimal($maximumPoints, self::POINTS_SCALE);
    }

    /**
     * Awarded points for an incorrect automatic answer (-penalty).
     *
     * @param numeric-string $penaltyPoints
     *
     * @return numeric-string
     */
    public function awardedForIncorrect(string $penaltyPoints): string
    {
        $penalty = $this->assertDecimal($penaltyPoints, self::POINTS_SCALE);
        if (-1 === bccomp($penalty, '0', self::POINTS_SCALE)) {
            throw AssessmentScoringException::invalidInput('penaltyPoints must be >= 0.');
        }

        return bcsub('0', $penalty, self::POINTS_SCALE);
    }

    /**
     * @return numeric-string
     */
    private function assertDecimal(string $raw, int $scale): string
    {
        $trimmed = trim($raw);
        if ('' === $trimmed || !is_numeric($trimmed) || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw AssessmentScoringException::invalidInput('Score decimal fields must be canonical numeric strings.');
        }

        return bcadd($trimmed, '0', $scale);
    }
}
