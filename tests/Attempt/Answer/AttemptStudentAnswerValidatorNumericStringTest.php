<?php

declare(strict_types=1);

namespace App\Tests\Attempt\Answer;

use App\Attempt\Answer\AttemptStudentAnswerValidator;
use App\Enum\AssessmentAttemptFailureReason;
use App\Enum\QuestionType;
use App\Exception\AssessmentAttemptException;
use App\Repository\QuestionRevisionOptionRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AttemptStudentAnswerValidatorNumericStringTest extends TestCase
{
    private AttemptStudentAnswerValidator $validator;

    protected function setUp(): void
    {
        $options = $this->createMock(QuestionRevisionOptionRepository::class);
        $this->validator = new AttemptStudentAnswerValidator($options);
    }

    public function testRejectsFloatValue(): void
    {
        $this->expectAnswerInvalid([
            'version' => AttemptStudentAnswerValidator::PAYLOAD_VERSION,
            'answerType' => QuestionType::Numeric->value,
            'value' => 3.14,
        ]);
    }

    public function testRejectsIntValue(): void
    {
        $this->expectAnswerInvalid([
            'version' => AttemptStudentAnswerValidator::PAYLOAD_VERSION,
            'answerType' => QuestionType::Numeric->value,
            'value' => 10,
        ]);
    }

    public function testRejectsExponent(): void
    {
        $this->expectAnswerInvalid([
            'version' => AttemptStudentAnswerValidator::PAYLOAD_VERSION,
            'answerType' => QuestionType::Numeric->value,
            'value' => '1e3',
        ]);
    }

    public function testRejectsNaN(): void
    {
        $this->expectAnswerInvalid([
            'version' => AttemptStudentAnswerValidator::PAYLOAD_VERSION,
            'answerType' => QuestionType::Numeric->value,
            'value' => 'NaN',
        ]);
    }

    public function testAcceptsCanonicalString(): void
    {
        $normalized = $this->validator->validateAndNormalize(
            QuestionType::Numeric,
            Uuid::v7(),
            [
                'version' => AttemptStudentAnswerValidator::PAYLOAD_VERSION,
                'answerType' => QuestionType::Numeric->value,
                'value' => '3.14',
            ],
        );
        self::assertArrayHasKey('value', $normalized);
        $value = $normalized['value'] ?? null;
        self::assertSame('3.14', $value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function expectAnswerInvalid(array $payload): void
    {
        try {
            $this->validator->validateAndNormalize(QuestionType::Numeric, Uuid::v7(), $payload);
            self::fail('Expected numeric payload rejected.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::AnswerInvalid, $e->getReason());
        }
    }
}
