<?php

declare(strict_types=1);

namespace App\Tests\Service\LearningContent;

use App\Question\Import\QuestionCsvParser;
use PHPUnit\Framework\TestCase;

final class LearningContentPackageMat133QuestionsTest extends TestCase
{
    public function testApprovedCsvKeepsFiveUniqueQuestionsAndCadbcAnswers(): void
    {
        $path = \dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'data'.\DIRECTORY_SEPARATOR.'content'
            .\DIRECTORY_SEPARATOR.'tymm-2026'.\DIRECTORY_SEPARATOR.'grade-1'
            .\DIRECTORY_SEPARATOR.'matematik'.\DIRECTORY_SEPARATOR.'mat-1-3-3'
            .\DIRECTORY_SEPARATOR.'questions.csv';
        $bytes = (string) file_get_contents($path);
        self::assertTrue(str_starts_with($bytes, "\xEF\xBB\xBF"));
        self::assertStringContainsString("\r\n", $bytes);

        $parser = new QuestionCsvParser();
        $records = $parser->parse($bytes);
        self::assertCount(5, $records);
        $codes = [];
        $answers = [];
        $stems = [];
        foreach ($records as $record) {
            self::assertFalse($record['column_error']);
            self::assertCount(11, $record['values']);
            $values = $record['values'];
            self::assertSame('1', $values['grade_level']);
            self::assertSame('matematik', $values['subject_code']);
            self::assertSame('mat_1_3_3', $values['learning_outcome_code']);
            $code = $parser->normalizeQuestionCode($values['code']);
            self::assertNotNull($code);
            self::assertArrayNotHasKey($code, $codes);
            $codes[$code] = true;
            foreach (['stem', 'option_a', 'option_b', 'option_c', 'option_d', 'explanation'] as $field) {
                self::assertNotSame('', $values[$field]);
                $trim = ltrim($values[$field]);
                self::assertDoesNotMatchRegularExpression('/^[=+\-@\t]/', $trim);
            }
            $answers[] = strtoupper($values['correct_option']);
            $stems[] = $values['stem'];
        }

        self::assertSame([
            'c42685528712410e898e1c5b1bfca821',
            'b07a55ad45c9411ab8364d8e1b0dca54',
            '21ea85aae2a94af99079a5fbe24ed443',
            '67a48d575fe54d609fc6260e5de778c7',
            'c648ed3074684de987576a75554bb1a6',
        ], array_keys($codes));
        self::assertSame(['C', 'A', 'D', 'B', 'C'], $answers);
        self::assertSame(
            'Top yuvarlak görünür. Kutu köşeli görünür. Düğme yuvarlak görünür. Cetvel uzun ve düz görünür. Yuvarlak görünme özelliğine göre hangileri aynı gruba konur?',
            $stems[0],
        );
        self::assertSame('Üç nesne yuvarlak görünür. Bir nesne köşeli görünür. Hangisi gruptan farklıdır?', $stems[1]);
        self::assertSame('Hangisinin biçimsel özelliği yuvarlaktır?', $stems[2]);
        self::assertSame('İki nesne de yuvarlak görünür. Biri kırmızı, biri mavidir. Hangisi doğrudur?', $stems[3]);
        self::assertSame('Bir nesne köşeli görünür diye anlatılır. Hangisi bu açıklamaya uyar?', $stems[4]);
        self::assertSame('Birinci yuvarlak nesne', $records[1]['values']['option_b']);
        self::assertSame('İkinci yuvarlak nesne', $records[1]['values']['option_c']);
        self::assertSame('Üçüncü yuvarlak nesne', $records[1]['values']['option_d']);
    }
}
