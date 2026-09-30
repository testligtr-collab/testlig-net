<?php

declare(strict_types=1);

namespace App\Tests\Question\Import;

use App\Question\Import\QuestionCsvImportCatalog;
use App\Question\Import\QuestionCsvImportException;
use App\Question\Import\QuestionCsvImportLookup;
use App\Question\Import\QuestionCsvImportPlanner;
use App\Question\Import\QuestionCsvImportPlanStore;
use App\Question\Import\QuestionCsvMessages;
use App\Question\Import\QuestionCsvParser;
use App\Question\Import\QuestionCsvTemplate;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class QuestionCsvImportTest extends TestCase
{
    private const CODE_A = 'aaaaaaaaaaaa4aaa8aaaaaaaaaaaaaaa';
    private const CODE_B = 'bbbbbbbbbbbb4bbb8bbbbbbbbbbbbbbb';

    public function testBomSemicolonTurkishAndLineEndings(): void
    {
        $parser = new QuestionCsvParser();
        $body = $this->csv([[
            self::CODE_A, '1', 'MAT.1', 'MAT.1.3.1', 'Şık Türkçe', 'Elma', 'Armut', '', '', 'A', '',
        ]], ';', "\r\n");
        $records = $parser->parse("\xEF\xBB\xBF".$body);
        self::assertSame('Şık Türkçe', $records[0]['values']['stem']);
        self::assertSame('mat_1_3_1', $parser->normalizeCatalogCode('MAT.1.3.1'));
        self::assertCount(1, $parser->parse(str_replace("\r\n", "\n", $body)));
    }

    public function testRejectsUnsafeFilesAndHeaders(): void
    {
        $parser = new QuestionCsvParser();
        $this->expectFile($parser, "\xFF\xFE".'x', QuestionCsvMessages::FILE_ENCODING);
        $this->expectFile($parser, "code,grade_level\0", QuestionCsvMessages::FILE_NUL);
        $this->expectFile($parser, '', QuestionCsvMessages::FILE_EMPTY);
        $this->expectFile($parser, "code,grade_level\n", QuestionCsvMessages::FILE_HEADER);
        $this->expectFile($parser, $this->header().",extra\n", QuestionCsvMessages::FILE_HEADER);
        $this->expectFile($parser, "code,code,grade_level,subject_code,learning_outcome_code,stem,option_a,option_b,option_c,option_d,correct_option,explanation\n", QuestionCsvMessages::FILE_HEADER);
        $this->expectFile($parser, "PK\x03\x04", QuestionCsvMessages::FILE_BINARY);
        $this->expectFile($parser, '<html></html>', QuestionCsvMessages::FILE_BINARY);
        $this->expectFile($parser, str_repeat('a', QuestionCsvParser::MAX_BYTES + 1), QuestionCsvMessages::FILE_TOO_LARGE);
        $rows = [];
        for ($i = 0; $i < 201; ++$i) {
            $rows[] = [self::CODE_A, '1', 'mat', 'mat_1', 'Kök', 'A', 'B', '', '', 'A', ''];
        }
        $this->expectFile($parser, $this->csv($rows), QuestionCsvMessages::FILE_TOO_MANY);
        $this->expectFile($parser, $this->header()."\n\n", QuestionCsvMessages::FILE_NO_ROWS);
    }

    public function testRowRulesAndSingleCatalogLookup(): void
    {
        $catalog = new ArrayCatalog(
            ['mat' => true, 'pasif' => false],
            [
                ['code' => 'mat_1_3_1', 'subjectCode' => 'mat', 'grade' => 1, 'usable' => true],
                ['code' => 'pasif_lo', 'subjectCode' => 'mat', 'grade' => 1, 'usable' => false],
                ['code' => 'baska', 'subjectCode' => 'fizik', 'grade' => 2, 'usable' => true],
            ],
            [self::CODE_B => true],
        );
        $planner = new QuestionCsvImportPlanner(new QuestionCsvParser(), $catalog);
        $codes = [];
        while (\count($codes) < 12) {
            $code = str_replace('-', '', \Symfony\Component\Uid\Uuid::v7()->toRfc4122());
            if (!\in_array($code, [self::CODE_A, self::CODE_B, QuestionCsvTemplate::EXAMPLE_CODE], true)) {
                $codes[] = $code;
            }
        }
        $plan = $planner->plan((new QuestionCsvParser())->parse($this->csv([
            [self::CODE_A, '1', 'MAT', 'MAT.1.3.1', 'İki artı iki?', 'Dört', 'Beş', '', '', 'A', ''],
            [self::CODE_A, '1', 'mat', 'mat_1_3_1', 'Yinelenen', 'A', 'B', '', '', 'A', ''],
            [self::CODE_B, '1', 'mat', 'mat_1_3_1', 'Farklı içerik', 'A', 'B', '', '', 'A', ''],
            [QuestionCsvTemplate::EXAMPLE_CODE, '1', 'mat', 'mat_1_3_1', 'Örnek', 'A', 'B', '', '', 'A', ''],
            ['=FORMUL', '1', 'mat', 'mat_1_3_1', 'Formül kod', 'A', 'B', '', '', 'A', ''],
            [$codes[0], '0', 'mat', 'mat_1_3_1', 'Sınıf', 'A', 'B', '', '', 'A', ''],
            [$codes[1], '1', 'yok', 'mat_1_3_1', 'Ders', 'A', 'B', '', '', 'A', ''],
            [$codes[2], '1', 'pasif', 'mat_1_3_1', 'Pasif ders', 'A', 'B', '', '', 'A', ''],
            [$codes[3], '1', 'mat', 'yok_lo', 'Kazanım', 'A', 'B', '', '', 'A', ''],
            [$codes[4], '1', 'mat', 'pasif_lo', 'Pasif kazanım', 'A', 'B', '', '', 'A', ''],
            [$codes[5], '1', 'mat', 'baska', 'Yanlış ilişki', 'A', 'B', '', '', 'A', ''],
            [$codes[6], '1', 'mat', 'mat_1_3_1', 'Kök', 'A', 'B', '', 'D', 'A', ''],
            [$codes[7], '1', 'mat', 'mat_1_3_1', 'Kök', 'A', 'B', '', '', 'E', ''],
            [$codes[8], '1', 'mat', 'mat_1_3_1', '<script>', 'A', 'B', '', '', 'A', ''],
            [$codes[9], '1', 'mat', 'mat_1_3_1', str_repeat('ü', 4001), 'A', 'B', '', '', 'A', ''],
            [$codes[10], '1', 'mat', 'MAT.1.3.1', '=2+2', 'Elma', 'Armut', '', '', 'B', ''],
        ])));

        self::assertSame(1, $catalog->calls);
        self::assertSame('conflict', $plan->rows[0]['status']);
        self::assertSame('conflict', $plan->rows[1]['status']);
        self::assertSame(QuestionCsvMessages::SKIP, $plan->rows[2]['message']);
        self::assertSame(QuestionCsvMessages::EXAMPLE, $plan->rows[3]['message']);
        self::assertSame(QuestionCsvMessages::CODE_INVALID, $plan->rows[4]['message']);
        self::assertSame(QuestionCsvMessages::GRADE_INVALID, $plan->rows[5]['message']);
        self::assertSame(QuestionCsvMessages::SUBJECT_MISSING, $plan->rows[6]['message']);
        self::assertSame(QuestionCsvMessages::SUBJECT_INACTIVE, $plan->rows[7]['message']);
        self::assertSame(QuestionCsvMessages::OUTCOME_MISSING, $plan->rows[8]['message']);
        self::assertSame(QuestionCsvMessages::OUTCOME_INACTIVE, $plan->rows[9]['message']);
        self::assertSame(QuestionCsvMessages::OUTCOME_MISMATCH, $plan->rows[10]['message']);
        self::assertSame(QuestionCsvMessages::OPTION_GAP, $plan->rows[11]['message']);
        self::assertSame(QuestionCsvMessages::CORRECT_INVALID, $plan->rows[12]['message']);
        self::assertSame(QuestionCsvMessages::MARKUP, $plan->rows[13]['message']);
        self::assertSame(QuestionCsvMessages::FIELD_TOO_LONG, $plan->rows[14]['message']);
        self::assertSame('create', $plan->rows[15]['status']);
        self::assertSame('=2+2', $plan->creates[17]['stem']);
        self::assertSame('B', $plan->rows[15]['correct']);
    }

    public function testAmbiguousOutcomeAndDuplicateOptions(): void
    {
        $catalog = new ArrayCatalog(
            ['mat' => true],
            [
                ['code' => 'cift', 'subjectCode' => 'mat', 'grade' => 1, 'usable' => true],
                ['code' => 'cift', 'subjectCode' => 'mat', 'grade' => 1, 'usable' => true],
            ],
            [],
        );
        $plan = (new QuestionCsvImportPlanner(new QuestionCsvParser(), $catalog))->plan(
            (new QuestionCsvParser())->parse($this->csv([
                [self::CODE_A, '1', 'mat', 'cift', 'Kök', 'Aynı', 'Aynı', '', '', 'A', ''],
            ])),
        );
        self::assertSame(QuestionCsvMessages::OUTCOME_AMBIGUOUS, $plan->rows[0]['message']);
    }

    public function testSecondDryRunSkipsExistingCode(): void
    {
        $parser = new QuestionCsvParser();
        $records = $parser->parse($this->csv([
            [self::CODE_A, '1', 'mat', 'mat_1', 'Kök', 'A', 'B', '', '', 'A', ''],
        ]));
        $first = (new QuestionCsvImportPlanner($parser, new ArrayCatalog(['mat' => true], [
            ['code' => 'mat_1', 'subjectCode' => 'mat', 'grade' => 1, 'usable' => true],
        ], [])))->plan($records);
        $second = (new QuestionCsvImportPlanner($parser, new ArrayCatalog(['mat' => true], [
            ['code' => 'mat_1', 'subjectCode' => 'mat', 'grade' => 1, 'usable' => true],
        ], [self::CODE_A => true])))->plan($records);
        self::assertSame(1, $first->createdCount);
        self::assertSame(1, $second->skippedCount);
        self::assertFalse($second->canApply());
    }

    public function testPlanStoreOwnershipExpiryDigestAndConsume(): void
    {
        $clock = new QuestionCsvFrozenClock(new \DateTimeImmutable('2026-09-30 12:00:00 UTC'));
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'question-csv-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $store = new QuestionCsvImportPlanStore($dir, $clock);
        $records = (new QuestionCsvParser())->parse($this->csv([
            [self::CODE_A, '1', 'mat', 'mat_1', 'Kök', 'A', 'B', '', '', 'A', ''],
        ]));
        $owner = '11111111-1111-4111-8111-111111111111';
        $id = $store->save($owner, hash('sha256', 'file'), hash('sha256', 'plan'), $records);
        try {
            $store->load($id, '22222222-2222-4222-8222-222222222222');
            self::fail('Another user opened the plan.');
        } catch (QuestionCsvImportException $exception) {
            self::assertSame(QuestionCsvImportException::PLAN, $exception->kind);
        }
        $path = $dir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'question-imports'.\DIRECTORY_SEPARATOR.$id.'.json';
        $raw = (string) file_get_contents($path);
        file_put_contents($path, str_replace('Kök', 'Değişti', $raw));
        try {
            $store->load($id, '11111111-1111-4111-8111-111111111111');
            self::fail('Tampered plan was accepted.');
        } catch (QuestionCsvImportException $exception) {
            self::assertSame(QuestionCsvImportException::PLAN, $exception->kind);
        }
        file_put_contents($path, $raw);
        $clock->now = $clock->now->modify('+16 minutes');
        try {
            $store->load($id, '11111111-1111-4111-8111-111111111111');
            self::fail('Expired plan was accepted.');
        } catch (QuestionCsvImportException $exception) {
            self::assertSame(QuestionCsvImportException::EXPIRED, $exception->kind);
        }
    }

    public function testConsumedPlanIsRejected(): void
    {
        $clock = new QuestionCsvFrozenClock(new \DateTimeImmutable('2026-09-30 12:00:00 UTC'));
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'question-csv-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $store = new QuestionCsvImportPlanStore($dir, $clock);
        $records = (new QuestionCsvParser())->parse($this->csv([
            [self::CODE_A, '1', 'mat', 'mat_1', 'Kök', 'A', 'B', '', '', 'A', ''],
        ]));
        $owner = '11111111-1111-4111-8111-111111111111';
        $id = $store->save($owner, hash('sha256', 'file'), hash('sha256', 'plan'), $records);
        $store->consume($id, $owner);
        $this->expectException(QuestionCsvImportException::class);
        $store->load($id, $owner);
    }

    /**
     * @param list<list<string>> $rows
     */
    private function csv(array $rows, string $delimiter = ',', string $newline = "\n"): string
    {
        $lines = [$this->header($delimiter)];
        foreach ($rows as $row) {
            $lines[] = implode($delimiter, $row);
        }

        return implode($newline, $lines).$newline;
    }

    private function header(string $delimiter = ','): string
    {
        return implode($delimiter, QuestionCsvParser::HEADERS);
    }

    private function expectFile(QuestionCsvParser $parser, string $bytes, string $message): void
    {
        try {
            $parser->parse($bytes);
            self::fail('File was accepted.');
        } catch (QuestionCsvImportException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}

/**
 * @phpstan-type OutcomeRow array{code: string, subjectCode: string, grade: int, usable: bool}
 */
final class ArrayCatalog implements QuestionCsvImportCatalog
{
    public int $calls = 0;

    /**
     * @param array<string, bool> $subjects
     * @param list<OutcomeRow>    $outcomes
     * @param array<string, true> $existing
     */
    public function __construct(
        private readonly array $subjects,
        private readonly array $outcomes,
        private readonly array $existing,
    ) {
    }

    public function lookup(array $subjectCodes, array $outcomeCodes, array $questionCodes): QuestionCsvImportLookup
    {
        ++$this->calls;

        return new QuestionCsvImportLookup($this->subjects, $this->outcomes, $this->existing);
    }
}

final class QuestionCsvFrozenClock implements ClockInterface
{
    public function __construct(public \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
