<?php

declare(strict_types=1);

namespace App\Tests\Question\Answer;

use App\Enum\QuestionFailureReason;
use App\Enum\QuestionType;
use App\Exception\QuestionException;
use App\Question\Answer\QuestionTypeAnswerValidator;
use App\Question\Content\QuestionContentValidator;
use PHPUnit\Framework\TestCase;

final class QuestionTypeAnswerValidatorNumericStringTest extends TestCase
{
    private QuestionTypeAnswerValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new QuestionTypeAnswerValidator(new QuestionContentValidator());
    }

    public function testRejectsFloatValue(): void
    {
        $this->expectAnswerInvalid(['value' => 3.14]);
    }

    public function testRejectsIntValue(): void
    {
        $this->expectAnswerInvalid(['value' => 10]);
    }

    public function testRejectsExponent(): void
    {
        $this->expectAnswerInvalid(['value' => '1e3']);
    }

    public function testRejectsFloatTolerance(): void
    {
        $this->expectAnswerInvalid(['value' => '10', 'tolerance' => 0.01]);
    }

    public function testRejectsExponentTolerance(): void
    {
        $this->expectAnswerInvalid(['value' => '10', 'tolerance' => '1e-2']);
    }

    public function testAcceptsCanonicalStrings(): void
    {
        $result = $this->validator->validateAndNormalize(
            QuestionType::Numeric,
            [],
            ['value' => '3.1400', 'tolerance' => '0.01'],
        );
        self::assertSame('3.14', $result['answerPayload']['value']);
        self::assertSame('0.01', $result['answerPayload']['tolerance']);
    }

    /**
     * @param array<string, mixed> $answerSpec
     */
    private function expectAnswerInvalid(array $answerSpec): void
    {
        try {
            $this->validator->validateAndNormalize(QuestionType::Numeric, [], $answerSpec);
            self::fail('Expected numeric answer spec rejected.');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::AnswerInvalid, $e->getReason());
        }
    }
}
