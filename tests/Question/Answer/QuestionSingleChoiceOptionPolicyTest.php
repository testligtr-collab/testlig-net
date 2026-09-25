<?php

declare(strict_types=1);

namespace App\Tests\Question\Answer;

use App\Enum\QuestionType;
use App\Exception\QuestionException;
use App\Question\Answer\QuestionTypeAnswerValidator;
use App\Question\Content\QuestionContentDocument;
use App\Question\Content\QuestionContentValidator;
use PHPUnit\Framework\TestCase;

final class QuestionSingleChoiceOptionPolicyTest extends TestCase
{
    private QuestionTypeAnswerValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new QuestionTypeAnswerValidator(new QuestionContentValidator());
    }

    public function testAcceptsTwoToSixWithExactlyOneCorrect(): void
    {
        $result = $this->validator->validateAndNormalize(QuestionType::SingleChoice, $this->options(4), [
            'correctStableKey' => 'opt_2',
        ]);

        self::assertSame('opt_2', $result['answerPayload']['correctStableKey']);
        self::assertCount(4, $result['options']);
    }

    public function testRejectsSeventhOptionBlankAndDuplicateText(): void
    {
        $this->expectException(QuestionException::class);
        $this->validator->validateAndNormalize(QuestionType::SingleChoice, $this->options(7), [
            'correctStableKey' => 'opt_1',
        ]);
    }

    public function testRejectsDuplicateVisibleText(): void
    {
        $options = $this->options(2);
        $options[1] = [
            'stableKey' => 'opt_2',
            'content' => QuestionContentDocument::paragraph('Secenek 1'),
            'position' => 2,
        ];

        $this->expectException(QuestionException::class);
        $this->validator->validateAndNormalize(QuestionType::SingleChoice, $options, [
            'correctStableKey' => 'opt_1',
        ]);
    }

    public function testRejectsBlankParagraph(): void
    {
        $options = $this->options(2);
        $options[0] = [
            'stableKey' => 'opt_1',
            'content' => QuestionContentDocument::paragraph('   '),
            'position' => 1,
        ];

        $this->expectException(QuestionException::class);
        $this->validator->validateAndNormalize(QuestionType::SingleChoice, $options, [
            'correctStableKey' => 'opt_2',
        ]);
    }

    /**
     * @return list<array{stableKey: string, content: QuestionContentDocument, position: int}>
     */
    private function options(int $count): array
    {
        $options = [];
        for ($i = 1; $i <= $count; ++$i) {
            $options[] = [
                'stableKey' => 'opt_'.$i,
                'content' => QuestionContentDocument::paragraph('Secenek '.$i),
                'position' => $i,
            ];
        }

        return $options;
    }
}
