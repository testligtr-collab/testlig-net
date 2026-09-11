<?php

declare(strict_types=1);

namespace App\Tests\Scoring;

use App\Enum\ItemScoreOutcome;
use App\Enum\QuestionType;
use App\Scoring\AutomaticAnswerEvaluator;
use App\Scoring\DecimalScoreCalculator;
use PHPUnit\Framework\TestCase;

final class AutomaticAnswerEvaluatorNumericStringTest extends TestCase
{
    private AutomaticAnswerEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AutomaticAnswerEvaluator(new DecimalScoreCalculator());
    }

    public function testFloatStudentValueIsIncorrectNotException(): void
    {
        $result = $this->evaluator->evaluate(
            QuestionType::Numeric,
            ['value' => '10'],
            ['answerType' => 'numeric', 'value' => 10.0],
            '2.00',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $result->outcome);
        self::assertSame('-0.50', $result->awardedPoints);
    }

    public function testIntStudentValueIsIncorrectNotException(): void
    {
        $result = $this->evaluator->evaluate(
            QuestionType::Numeric,
            ['value' => '10'],
            ['answerType' => 'numeric', 'value' => 10],
            '2.00',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $result->outcome);
        self::assertSame('-0.50', $result->awardedPoints);
    }

    public function testCanonicalStringStillCorrect(): void
    {
        $result = $this->evaluator->evaluate(
            QuestionType::Numeric,
            ['value' => '10'],
            ['answerType' => 'numeric', 'value' => '10'],
            '2.00',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Correct, $result->outcome);
    }
}
