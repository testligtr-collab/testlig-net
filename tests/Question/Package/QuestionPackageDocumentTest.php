<?php

declare(strict_types=1);

namespace App\Tests\Question\Package;

use App\Exception\QuestionPackageException;
use App\Question\Import\QuestionCsvParser;
use App\Question\Package\QuestionPackageAllowlist;
use App\Question\Package\QuestionPackageTarget;
use PHPUnit\Framework\TestCase;

final class QuestionPackageDocumentTest extends TestCase
{
    public function testApprovedCsvMatchesPinnedChecksumAndAnswers(): void
    {
        $target = QuestionPackageTarget::mat133();
        $bytes = (string) file_get_contents($this->csvPath());
        self::assertSame($target->fixtureChecksum, hash('sha256', $bytes));
        self::assertTrue(str_starts_with($bytes, "\xEF\xBB\xBF"));
        self::assertTrue(str_contains($bytes, "\n"));

        $parser = new QuestionCsvParser();
        $records = $parser->parse($bytes);
        self::assertCount($target->expectedRows, $records);
        $codes = [];
        $answers = [];
        foreach ($records as $record) {
            self::assertFalse($record['column_error']);
            self::assertCount($target->expectedColumns, $record['values']);
            $values = $record['values'];
            self::assertSame((string) $target->gradeLevel, $values['grade_level']);
            self::assertSame($target->subjectCode, $values['subject_code']);
            self::assertSame($target->outcomeCode, $values['learning_outcome_code']);
            $code = $parser->normalizeQuestionCode($values['code']);
            self::assertNotNull($code);
            $codes[] = $code;
            foreach (['stem', 'option_a', 'option_b', 'option_c', 'option_d', 'explanation'] as $field) {
                self::assertNotSame('', $values[$field]);
                self::assertDoesNotMatchRegularExpression('/^[=+\-@\t]/', ltrim($values[$field]));
            }
            $answers[] = strtoupper($values['correct_option']);
        }

        self::assertSame($target->expectedCodes, $codes);
        self::assertSame($target->expectedAnswers, $answers);
        self::assertSame('single_choice', $target->questionType);
        self::assertSame(1, $target->pointsEach);
    }

    public function testAllowlistRejectsTraversalAndUnknownPackages(): void
    {
        $allowlist = new QuestionPackageAllowlist();
        $target = $allowlist->resolve('data/content/tymm-2026/grade-1/matematik/mat-1-3-3');
        self::assertSame(QuestionPackageTarget::mat133()->packageKey, $target->packageKey);

        foreach ([
            'data/content/tymm-2026/grade-1/matematik/mat-1-3-2',
            'data/content/tymm-2026/grade-1/matematik/../matematik/mat-1-3-3',
            'C:/xampp/htdocs/testlig-net/data/content/tymm-2026/grade-1/matematik/mat-1-3-3',
            'data/content/evil',
        ] as $rejected) {
            try {
                $allowlist->resolve($rejected);
                self::fail($rejected);
            } catch (QuestionPackageException $exception) {
                self::assertSame('Package is not on the allowlist.', $exception->getMessage());
            }
        }
    }

    private function csvPath(): string
    {
        return \dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'data'.\DIRECTORY_SEPARATOR.'content'
            .\DIRECTORY_SEPARATOR.'tymm-2026'.\DIRECTORY_SEPARATOR.'grade-1'
            .\DIRECTORY_SEPARATOR.'matematik'.\DIRECTORY_SEPARATOR.'mat-1-3-3'
            .\DIRECTORY_SEPARATOR.'questions.csv';
    }
}
