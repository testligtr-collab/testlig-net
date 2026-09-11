<?php

declare(strict_types=1);

namespace App\Tests\Scoring;

use App\Enum\ItemScoreOutcome;
use App\Exception\AssessmentScoringException;
use App\Scoring\DecimalScoreCalculator;
use PHPUnit\Framework\TestCase;

final class DecimalScoreCalculatorTest extends TestCase
{
    private DecimalScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DecimalScoreCalculator();
    }

    public function testPositiveAggregatePercentageAndCounters(): void
    {
        $result = $this->calculator->calculate([
            [
                'awardedPoints' => '2.50',
                'maximumPoints' => '2.50',
                'outcome' => ItemScoreOutcome::Correct,
            ],
            [
                'awardedPoints' => '1.00',
                'maximumPoints' => '2.00',
                'outcome' => ItemScoreOutcome::ManuallyGraded,
            ],
        ]);

        self::assertSame(DecimalScoreCalculator::POLICY_ID, 'testlig_default_v1');
        self::assertSame('3.50', $result['rawPoints']);
        self::assertSame('3.50', $result['finalPoints']);
        self::assertSame('4.50', $result['maximumPoints']);
        self::assertSame('77.7777', $result['percentage']);
        self::assertSame(1, $result['correctCount']);
        self::assertSame(0, $result['incorrectCount']);
        self::assertSame(0, $result['unansweredCount']);
        self::assertSame(0, $result['manualPendingCount']);
    }

    public function testPenaltyAndEmptyNoPenaltyNegativeRawClamped(): void
    {
        $result = $this->calculator->calculate([
            [
                'awardedPoints' => '-0.50',
                'maximumPoints' => '2.50',
                'outcome' => ItemScoreOutcome::Incorrect,
            ],
            [
                'awardedPoints' => '0.00',
                'maximumPoints' => '2.50',
                'outcome' => ItemScoreOutcome::Unanswered,
            ],
        ]);

        self::assertSame('-0.50', $result['rawPoints']);
        self::assertSame('0.00', $result['finalPoints']);
        self::assertSame('5.00', $result['maximumPoints']);
        self::assertSame('0.0000', $result['percentage']);
        self::assertSame(0, $result['correctCount']);
        self::assertSame(1, $result['incorrectCount']);
        self::assertSame(1, $result['unansweredCount']);
    }

    public function testAwardedHelpersAndNormalize(): void
    {
        self::assertSame('2.50', $this->calculator->awardedForCorrect('2.5'));
        self::assertSame('-0.50', $this->calculator->awardedForIncorrect('0.5'));
        self::assertSame('1.00', $this->calculator->normalizePoints('1'));
    }

    public function testManualPendingCounter(): void
    {
        $result = $this->calculator->calculate([
            [
                'awardedPoints' => '0.00',
                'maximumPoints' => '4.00',
                'outcome' => ItemScoreOutcome::ManualPending,
            ],
            [
                'awardedPoints' => '2.00',
                'maximumPoints' => '2.00',
                'outcome' => ItemScoreOutcome::Correct,
            ],
        ]);
        self::assertSame(1, $result['manualPendingCount']);
        self::assertSame(1, $result['correctCount']);
        self::assertSame('2.00', $result['rawPoints']);
    }

    public function testRejectsEmptyItemsAndZeroMaximum(): void
    {
        try {
            $this->calculator->calculate([]);
            self::fail('Expected empty items rejected.');
        } catch (AssessmentScoringException $e) {
            self::assertSame('invalid_input', $e->getReason()->value);
        }

        try {
            $this->calculator->calculate([
                [
                    'awardedPoints' => '0.00',
                    'maximumPoints' => '0.00',
                    'outcome' => ItemScoreOutcome::Unanswered,
                ],
            ]);
            self::fail('Expected zero maximum rejected.');
        } catch (AssessmentScoringException $e) {
            self::assertSame('invalid_input', $e->getReason()->value);
        }
    }

    public function testSourceContainsNoPhpFloatArithmetic(): void
    {
        $path = (new \ReflectionClass(DecimalScoreCalculator::class))->getFileName();
        self::assertNotFalse($path);
        $source = file_get_contents($path);
        self::assertNotFalse($source);

        self::assertStringNotContainsString('(float)', $source);
        self::assertStringNotContainsString('floatval', $source);
        self::assertDoesNotMatchRegularExpression('/\b\d+\.\d+\s*[+\-*\/]/', $source);
        self::assertStringContainsString('bcadd', $source);
        self::assertStringContainsString('bcsub', $source);
        self::assertStringContainsString('bcmul', $source);
        self::assertStringContainsString('bcdiv', $source);
        self::assertSame(2, DecimalScoreCalculator::POINTS_SCALE);
        self::assertSame(4, DecimalScoreCalculator::PERCENTAGE_SCALE);
    }
}
