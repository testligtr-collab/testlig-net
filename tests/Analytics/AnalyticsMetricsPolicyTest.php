<?php

declare(strict_types=1);

namespace App\Tests\Analytics;

use App\Analytics\AnalyticsMetricsPolicy;
use App\Analytics\AnalyticsPrivacyPolicy;
use App\Enum\LearningOutcomePerformanceBand;
use App\Exception\AssessmentAnalyticsException;
use PHPUnit\Framework\TestCase;

final class AnalyticsMetricsPolicyTest extends TestCase
{
    private AnalyticsMetricsPolicy $metrics;

    private AnalyticsPrivacyPolicy $privacy;

    protected function setUp(): void
    {
        $this->metrics = new AnalyticsMetricsPolicy();
        $this->privacy = new AnalyticsPrivacyPolicy();
    }

    public function testMeanMedianMinMaxDeterministic(): void
    {
        $values = ['10.0000', '40.0000', '30.0000', '20.0000', '50.0000'];
        self::assertSame('30.0000', $this->metrics->mean($values));
        self::assertSame('30.0000', $this->metrics->median($values));
        self::assertSame('10.0000', $this->metrics->min($values));
        self::assertSame('50.0000', $this->metrics->max($values));
    }

    public function testEvenMedianAveragesTwoMiddle(): void
    {
        $values = ['10.0000', '20.0000', '30.0000', '40.0000'];
        self::assertSame('25.0000', $this->metrics->median($values));
    }

    public function testEmptyListsReturnNull(): void
    {
        self::assertNull($this->metrics->mean([]));
        self::assertNull($this->metrics->median([]));
        self::assertNull($this->metrics->min([]));
        self::assertNull($this->metrics->max([]));
    }

    public function testBandsAndThresholdConstants(): void
    {
        self::assertSame(LearningOutcomePerformanceBand::Strong, $this->metrics->bandForPercentage('80.0000'));
        self::assertSame(LearningOutcomePerformanceBand::Strong, $this->metrics->bandForPercentage('99.5000'));
        self::assertSame(LearningOutcomePerformanceBand::Developing, $this->metrics->bandForPercentage('50.0000'));
        self::assertSame(LearningOutcomePerformanceBand::Developing, $this->metrics->bandForPercentage('79.9999'));
        self::assertSame(LearningOutcomePerformanceBand::NeedsSupport, $this->metrics->bandForPercentage('49.9999'));
        self::assertSame('80.0000', AnalyticsMetricsPolicy::STRONG_MIN_PERCENTAGE);
        self::assertSame('50.0000', AnalyticsMetricsPolicy::DEVELOPING_MIN_PERCENTAGE);
    }

    public function testZeroDenominatorRateIsNull(): void
    {
        self::assertNull($this->metrics->rate('5', 0));
        self::assertSame('50.0000', $this->metrics->rate('1', 2));
    }

    public function testDistributionBuckets(): void
    {
        $buckets = $this->metrics->distributionBuckets([
            '0.0000',
            '19.9999',
            '20.0000',
            '50.0000',
            '80.0000',
            '100.0000',
        ]);
        $byLabel = [];
        foreach ($buckets as $bucket) {
            $byLabel[$bucket['label']] = $bucket['count'];
        }
        self::assertSame(2, $byLabel['0-20']);
        self::assertSame(1, $byLabel['20-40']);
        self::assertSame(1, $byLabel['40-60']);
        self::assertSame(0, $byLabel['60-80']);
        self::assertSame(2, $byLabel['80-100']);
    }

    public function testPrivacyThresholdBoundary(): void
    {
        self::assertSame(5, $this->privacy->minCohortSize());
        self::assertTrue($this->privacy->shouldSuppressCohort(4));
        self::assertFalse($this->privacy->shouldSuppressCohort(5));
    }

    public function testInvalidPercentageRejected(): void
    {
        $this->expectException(AssessmentAnalyticsException::class);
        $this->metrics->bandForPercentage('not-a-number');
    }
}
