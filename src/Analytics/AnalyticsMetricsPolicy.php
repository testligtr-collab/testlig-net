<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Enum\LearningOutcomePerformanceBand;
use App\Exception\AssessmentAnalyticsException;
use App\Scoring\DecimalScoreCalculator;

/**
 * Deterministic bcmath metrics for analytics projections (no PHP float).
 */
final class AnalyticsMetricsPolicy
{
    public const STRONG_MIN_PERCENTAGE = '80.0000';

    public const DEVELOPING_MIN_PERCENTAGE = '50.0000';

    /**
     * Inclusive lower / exclusive upper except the final bucket which is inclusive on both ends.
     *
     * @var list<array{label: string, lower: numeric-string, upper: numeric-string, upperInclusive: bool}>
     */
    private const DISTRIBUTION_BUCKETS = [
        ['label' => '0-20', 'lower' => '0.0000', 'upper' => '20.0000', 'upperInclusive' => false],
        ['label' => '20-40', 'lower' => '20.0000', 'upper' => '40.0000', 'upperInclusive' => false],
        ['label' => '40-60', 'lower' => '40.0000', 'upper' => '60.0000', 'upperInclusive' => false],
        ['label' => '60-80', 'lower' => '60.0000', 'upper' => '80.0000', 'upperInclusive' => false],
        ['label' => '80-100', 'lower' => '80.0000', 'upper' => '100.0000', 'upperInclusive' => true],
    ];

    /**
     * @param list<numeric-string> $percentages
     *
     * @return numeric-string|null
     */
    public function median(array $percentages): ?string
    {
        if ([] === $percentages) {
            return null;
        }

        $sorted = $percentages;
        usort(
            $sorted,
            static fn (string $a, string $b): int => bccomp($a, $b, DecimalScoreCalculator::PERCENTAGE_SCALE),
        );

        $count = \count($sorted);
        $mid = intdiv($count, 2);
        if (0 === $count % 2) {
            $left = $sorted[$mid - 1];
            $right = $sorted[$mid];
            $sum = bcadd($left, $right, DecimalScoreCalculator::PERCENTAGE_SCALE + 1);

            return bcdiv($sum, '2', DecimalScoreCalculator::PERCENTAGE_SCALE);
        }

        return bcadd($sorted[$mid], '0', DecimalScoreCalculator::PERCENTAGE_SCALE);
    }

    /**
     * @param list<numeric-string> $percentages
     *
     * @return numeric-string|null
     */
    public function mean(array $percentages): ?string
    {
        if ([] === $percentages) {
            return null;
        }

        $sum = '0';
        foreach ($percentages as $pct) {
            $sum = bcadd($sum, $pct, DecimalScoreCalculator::PERCENTAGE_SCALE + 4);
        }

        return bcdiv($sum, (string) \count($percentages), DecimalScoreCalculator::PERCENTAGE_SCALE);
    }

    /**
     * @param list<numeric-string> $percentages
     *
     * @return numeric-string|null
     */
    public function min(array $percentages): ?string
    {
        if ([] === $percentages) {
            return null;
        }

        $min = $percentages[0];
        foreach ($percentages as $pct) {
            if (-1 === bccomp($pct, $min, DecimalScoreCalculator::PERCENTAGE_SCALE)) {
                $min = $pct;
            }
        }

        return bcadd($min, '0', DecimalScoreCalculator::PERCENTAGE_SCALE);
    }

    /**
     * @param list<numeric-string> $percentages
     *
     * @return numeric-string|null
     */
    public function max(array $percentages): ?string
    {
        if ([] === $percentages) {
            return null;
        }

        $max = $percentages[0];
        foreach ($percentages as $pct) {
            if (1 === bccomp($pct, $max, DecimalScoreCalculator::PERCENTAGE_SCALE)) {
                $max = $pct;
            }
        }

        return bcadd($max, '0', DecimalScoreCalculator::PERCENTAGE_SCALE);
    }

    public function bandForPercentage(string $pct): LearningOutcomePerformanceBand
    {
        $normalized = $this->assertPercentage($pct);
        if (-1 !== bccomp($normalized, self::STRONG_MIN_PERCENTAGE, DecimalScoreCalculator::PERCENTAGE_SCALE)) {
            return LearningOutcomePerformanceBand::Strong;
        }
        if (-1 !== bccomp($normalized, self::DEVELOPING_MIN_PERCENTAGE, DecimalScoreCalculator::PERCENTAGE_SCALE)) {
            return LearningOutcomePerformanceBand::Developing;
        }

        return LearningOutcomePerformanceBand::NeedsSupport;
    }

    /**
     * @param list<numeric-string> $percentages
     *
     * @return list<array{label: string, count: int}>
     */
    public function distributionBuckets(array $percentages): array
    {
        $counts = [];
        foreach (self::DISTRIBUTION_BUCKETS as $bucket) {
            $counts[$bucket['label']] = 0;
        }

        foreach ($percentages as $pct) {
            $normalized = $this->assertPercentage($pct);
            $matched = false;
            foreach (self::DISTRIBUTION_BUCKETS as $bucket) {
                $geLower = -1 !== bccomp($normalized, $bucket['lower'], DecimalScoreCalculator::PERCENTAGE_SCALE);
                $vsUpper = bccomp($normalized, $bucket['upper'], DecimalScoreCalculator::PERCENTAGE_SCALE);
                $withinUpper = $bucket['upperInclusive'] ? (1 !== $vsUpper) : (-1 === $vsUpper);
                if ($geLower && $withinUpper) {
                    ++$counts[$bucket['label']];
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw AssessmentAnalyticsException::invalidInput('Percentage fell outside distribution buckets.');
            }
        }

        $out = [];
        foreach (self::DISTRIBUTION_BUCKETS as $bucket) {
            $out[] = [
                'label' => $bucket['label'],
                'count' => $counts[$bucket['label']],
            ];
        }

        return $out;
    }

    /**
     * @param numeric-string $numerator
     *
     * @return numeric-string|null null when denominator is zero
     */
    public function rate(string $numerator, int $denominator): ?string
    {
        if ($denominator <= 0) {
            return null;
        }

        return bcmul(
            bcdiv($numerator, (string) $denominator, DecimalScoreCalculator::PERCENTAGE_SCALE + 4),
            '100',
            DecimalScoreCalculator::PERCENTAGE_SCALE,
        );
    }

    /**
     * @param numeric-string|string $awarded
     * @param numeric-string|string $maximum
     *
     * @return numeric-string|null
     */
    public function percentageFromPoints(string $awarded, string $maximum): ?string
    {
        $awardedPoints = $this->assertPoints($awarded);
        $maximumPoints = $this->assertPoints($maximum);
        if (1 !== bccomp($maximumPoints, '0', DecimalScoreCalculator::POINTS_SCALE)) {
            return null;
        }

        $final = -1 === bccomp($awardedPoints, '0', DecimalScoreCalculator::POINTS_SCALE)
            ? bcadd('0', '0', DecimalScoreCalculator::POINTS_SCALE)
            : $awardedPoints;

        return bcmul(
            bcdiv($final, $maximumPoints, DecimalScoreCalculator::PERCENTAGE_SCALE + 4),
            '100',
            DecimalScoreCalculator::PERCENTAGE_SCALE,
        );
    }

    /**
     * @return numeric-string
     */
    public function normalizePoints(string $raw): string
    {
        return $this->assertPoints($raw);
    }

    /**
     * @return numeric-string
     */
    public function normalizePercentage(string $raw): string
    {
        return $this->assertPercentage($raw);
    }

    /**
     * @return numeric-string
     */
    private function assertPercentage(string $raw): string
    {
        $trimmed = trim($raw);
        if ('' === $trimmed || !is_numeric($trimmed) || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw AssessmentAnalyticsException::invalidInput('Analytics percentage must be a canonical numeric string.');
        }

        return bcadd($trimmed, '0', DecimalScoreCalculator::PERCENTAGE_SCALE);
    }

    /**
     * @return numeric-string
     */
    private function assertPoints(string $raw): string
    {
        $trimmed = trim($raw);
        if ('' === $trimmed || !is_numeric($trimmed) || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw AssessmentAnalyticsException::invalidInput('Analytics points must be a canonical numeric string.');
        }

        return bcadd($trimmed, '0', DecimalScoreCalculator::POINTS_SCALE);
    }
}
