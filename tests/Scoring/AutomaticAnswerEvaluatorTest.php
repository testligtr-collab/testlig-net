<?php

declare(strict_types=1);

namespace App\Tests\Scoring;

use App\Enum\ItemScoreOutcome;
use App\Enum\QuestionType;
use App\Enum\ScoringMethod;
use App\Scoring\AutomaticAnswerEvaluator;
use App\Scoring\DecimalScoreCalculator;
use PHPUnit\Framework\TestCase;

final class AutomaticAnswerEvaluatorTest extends TestCase
{
    private AutomaticAnswerEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AutomaticAnswerEvaluator(new DecimalScoreCalculator());
    }

    public function testSingleChoiceCorrectIncorrectEmpty(): void
    {
        $key = ['correctStableKey' => 'opt_b'];
        $correct = $this->evaluator->evaluate(
            QuestionType::SingleChoice,
            $key,
            ['answerType' => 'single_choice', 'selectedStableKey' => 'opt_b'],
            '2.50',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Correct, $correct->outcome);
        self::assertSame('2.50', $correct->awardedPoints);
        self::assertSame('0.00', $correct->penaltyApplied);
        self::assertFalse($correct->manualPending);

        $incorrect = $this->evaluator->evaluate(
            QuestionType::SingleChoice,
            $key,
            ['answerType' => 'single_choice', 'selectedStableKey' => 'opt_a'],
            '2.50',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $incorrect->outcome);
        self::assertSame('-0.50', $incorrect->awardedPoints);
        self::assertSame('0.50', $incorrect->penaltyApplied);

        $empty = $this->evaluator->evaluate(QuestionType::SingleChoice, $key, null, '2.50', '0.50');
        self::assertSame(ItemScoreOutcome::Unanswered, $empty->outcome);
        self::assertSame('0.00', $empty->awardedPoints);
        self::assertSame('0.00', $empty->penaltyApplied);
        self::assertSame(ScoringMethod::Automatic, $empty->scoringMethod);
    }

    public function testMultipleChoiceOrderIndependentExactSetAndDuplicates(): void
    {
        $key = ['correctStableKeys' => ['opt_a', 'opt_c']];

        $ok = $this->evaluator->evaluate(
            QuestionType::MultipleChoice,
            $key,
            ['answerType' => 'multiple_choice', 'selectedStableKeys' => ['opt_c', 'opt_a']],
            '3.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::Correct, $ok->outcome);
        self::assertSame('3.00', $ok->awardedPoints);

        $partial = $this->evaluator->evaluate(
            QuestionType::MultipleChoice,
            $key,
            ['answerType' => 'multiple_choice', 'selectedStableKeys' => ['opt_a']],
            '3.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $partial->outcome);
        self::assertSame('-1.00', $partial->awardedPoints);

        $dup = $this->evaluator->evaluate(
            QuestionType::MultipleChoice,
            $key,
            ['answerType' => 'multiple_choice', 'selectedStableKeys' => ['opt_a', 'opt_a', 'opt_c']],
            '3.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $dup->outcome);

        $empty = $this->evaluator->evaluate(QuestionType::MultipleChoice, $key, null, '3.00', '1.00');
        self::assertSame(ItemScoreOutcome::Unanswered, $empty->outcome);
    }

    public function testTrueFalseCorrectIncorrectEmpty(): void
    {
        $key = ['correct' => true];
        $ok = $this->evaluator->evaluate(
            QuestionType::TrueFalse,
            $key,
            ['answerType' => 'true_false', 'selected' => true],
            '1.00',
            '0.25',
        );
        self::assertSame(ItemScoreOutcome::Correct, $ok->outcome);

        $bad = $this->evaluator->evaluate(
            QuestionType::TrueFalse,
            $key,
            ['answerType' => 'true_false', 'selected' => false],
            '1.00',
            '0.25',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $bad->outcome);
        self::assertSame('-0.25', $bad->awardedPoints);

        $empty = $this->evaluator->evaluate(QuestionType::TrueFalse, $key, null, '1.00', '0.25');
        self::assertSame(ItemScoreOutcome::Unanswered, $empty->outcome);
    }

    public function testNumericExactToleranceAndEmpty(): void
    {
        $exactKey = ['value' => '10'];
        $exactOk = $this->evaluator->evaluate(
            QuestionType::Numeric,
            $exactKey,
            ['answerType' => 'numeric', 'value' => '10.00'],
            '2.00',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Correct, $exactOk->outcome);

        $exactBad = $this->evaluator->evaluate(
            QuestionType::Numeric,
            $exactKey,
            ['answerType' => 'numeric', 'value' => '10.01'],
            '2.00',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $exactBad->outcome);

        $tolKey = ['value' => '3.14', 'tolerance' => '0.01'];
        $tolOk = $this->evaluator->evaluate(
            QuestionType::Numeric,
            $tolKey,
            ['answerType' => 'numeric', 'value' => '3.145'],
            '2.00',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Correct, $tolOk->outcome);

        $tolBad = $this->evaluator->evaluate(
            QuestionType::Numeric,
            $tolKey,
            ['answerType' => 'numeric', 'value' => '3.16'],
            '2.00',
            '0.50',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $tolBad->outcome);

        $empty = $this->evaluator->evaluate(QuestionType::Numeric, $tolKey, null, '2.00', '0.50');
        self::assertSame(ItemScoreOutcome::Unanswered, $empty->outcome);
        self::assertSame('0.00', $empty->penaltyApplied);
    }

    public function testShortAnswerAutoVsManualUnicodeWhitespace(): void
    {
        $autoKey = ['acceptedAnswers' => ['Ankara', 'ANKARA'], 'caseSensitive' => false];
        $ok = $this->evaluator->evaluate(
            QuestionType::ShortAnswer,
            $autoKey,
            ['answerType' => 'short_answer', 'text' => "  ankara\t"],
            '4.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::Correct, $ok->outcome);
        self::assertSame(ScoringMethod::Automatic, $ok->scoringMethod);

        $bad = $this->evaluator->evaluate(
            QuestionType::ShortAnswer,
            $autoKey,
            ['answerType' => 'short_answer', 'text' => 'Izmir'],
            '4.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $bad->outcome);

        $caseKey = ['acceptedAnswers' => ['Cafe'], 'caseSensitive' => true];
        $caseBad = $this->evaluator->evaluate(
            QuestionType::ShortAnswer,
            $caseKey,
            ['answerType' => 'short_answer', 'text' => 'cafe'],
            '4.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $caseBad->outcome);

        $unicodeKey = ['acceptedAnswers' => ['café'], 'caseSensitive' => false];
        $unicode = $this->evaluator->evaluate(
            QuestionType::ShortAnswer,
            $unicodeKey,
            ['answerType' => 'short_answer', 'text' => ' CAFÉ '],
            '4.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::Correct, $unicode->outcome);

        $manual = $this->evaluator->evaluate(
            QuestionType::ShortAnswer,
            ['acceptedAnswers' => [], 'caseSensitive' => false],
            ['answerType' => 'short_answer', 'text' => 'any'],
            '4.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::ManualPending, $manual->outcome);
        self::assertSame(ScoringMethod::Manual, $manual->scoringMethod);
        self::assertTrue($manual->manualPending);
        self::assertSame('0.00', $manual->awardedPoints);

        $empty = $this->evaluator->evaluate(
            QuestionType::ShortAnswer,
            $autoKey,
            null,
            '4.00',
            '1.00',
        );
        self::assertSame(ItemScoreOutcome::Unanswered, $empty->outcome);
    }

    public function testAnswerTypeMismatchIsIncorrect(): void
    {
        $result = $this->evaluator->evaluate(
            QuestionType::SingleChoice,
            ['correctStableKey' => 'opt_b'],
            ['answerType' => 'true_false', 'selected' => true],
            '1.00',
            '0.25',
        );
        self::assertSame(ItemScoreOutcome::Incorrect, $result->outcome);
    }
}
