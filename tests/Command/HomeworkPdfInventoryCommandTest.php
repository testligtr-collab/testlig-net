<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HomeworkPdfInventoryCommand;
use App\Homework\Pdf\HomeworkPdfAttachmentLifecycle;
use App\Homework\Pdf\HomeworkPdfInventoryReference;
use App\Homework\Pdf\HomeworkPdfStorageInventory;
use App\Homework\Pdf\HomeworkPdfStoragePaths;
use App\Tests\Homework\Pdf\FakeHomeworkPdfInventorySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Output contract and safety of `app:homework-pdf:inventory`. The service behind it is covered by
 * HomeworkPdfStorageInventoryTest and the database side by HomeworkPdfInventoryDatabaseTest.
 */
final class HomeworkPdfInventoryCommandTest extends TestCase
{
    private const NOW = '2026-11-10 12:00:00';
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';

    private string $base;
    private string $durable;
    private int $now;
    private FakeHomeworkPdfInventorySource $source;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'hwinvcmd-'.bin2hex(random_bytes(5));
        $this->durable = $this->base.'/shared/var/homework-pdf';
        self::assertTrue(mkdir($this->durable, 0700, true));
        self::assertTrue(mkdir($this->base.'/project', 0700, true));
        $this->now = (new \DateTimeImmutable(self::NOW.' UTC'))->getTimestamp();
        $this->source = new FakeHomeworkPdfInventorySource();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    public function testPrintsCountsOnlyWithoutKeysNamesPathsOrContent(): void
    {
        $this->seedStore();
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay(true);

        foreach ([
            'homework_pdf_inventory_status=ok',
            'preview_only=yes',
            'writes=none',
            'point_in_time=yes',
            'orphan_min_age_seconds=86400',
            'store_empty=no',
            'disk_files=4',
            'matched_files=2',
            'matched_bytes=12',
            'orphan_candidate_files=1',
            'unreferenced_young_files=1',
            'db_references=3',
            'db_missing_file=1',
            'db_declared_bytes=22',
            'db_physical_bytes=12',
            'db_declared_minus_physical_bytes=10',
            'submitted_references=2',
            'draft_references=1',
        ] as $expected) {
            self::assertStringContainsString($expected."\n", $display);
        }
        // No identifying value: not a storage key, a file name, a path, an e-mail, an institution id or content.
        self::assertStringNotContainsString($this->base, $display);
        self::assertStringNotContainsString('.bin', $display);
        self::assertStringNotContainsString('.tmp', $display);
        self::assertStringNotContainsString('homework-pdf', $display);
        self::assertStringNotContainsString('institution=', $display);
        self::assertStringNotContainsString(self::A, $display);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{32}/', $display);
        self::assertStringNotContainsString('%PDF', $display);
    }

    public function testInstitutionTotalsAreOptInAndAmbiguousOwnerStaysSeparate(): void
    {
        $this->seedStore();
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--by-institution' => true]));
        $display = $tester->getDisplay(true);

        self::assertStringContainsString("institution_count=2\n", $display);
        self::assertStringContainsString('institution='.self::A.' references=1 declared_bytes=5 physical_bytes=5'."\n", $display);
        self::assertStringContainsString('institution='.self::B.' references=1 declared_bytes=7 physical_bytes=7'."\n", $display);
        self::assertStringContainsString('institution=ambiguous references=1 declared_bytes=10 physical_bytes=0'."\n", $display);
    }

    public function testPreviewLimitOnlyPrintsAPercentageAndRejectsNothing(): void
    {
        $this->seedStore();
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--preview-limit-bytes' => '48', '--by-institution' => true]));
        $display = $tester->getDisplay(true);

        self::assertStringContainsString("preview_limit_bytes=48\n", $display);
        self::assertStringContainsString("preview_limit_applied=no\n", $display);
        self::assertStringContainsString("usage_percent_physical_total=25.00\n", $display);
        self::assertStringContainsString('institution='.self::A.' references=1 declared_bytes=5 physical_bytes=5 usage_percent=10.42'."\n", $display);
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function invalidOptionProvider(): iterable
    {
        yield 'age below the floor' => [['--orphan-min-age' => '3599']];
        yield 'age not numeric' => [['--orphan-min-age' => 'abc']];
        yield 'age negative' => [['--orphan-min-age' => '-5']];
        yield 'limit zero' => [['--preview-limit-bytes' => '0']];
        yield 'limit negative' => [['--preview-limit-bytes' => '-1']];
        yield 'limit not numeric' => [['--preview-limit-bytes' => '10MB']];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('invalidOptionProvider')]
    public function testInvalidOptionsAreRejectedBeforeAnyRead(array $options): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute($options));
        self::assertSame("homework_pdf_inventory_status=invalid_option\n", $tester->getDisplay(true));
        self::assertSame(0, $this->source->referenceScans);
        self::assertSame([], $this->source->lookups);
    }

    public function testUnsafeOrMissingRootStatusesAreDistinctAndFailOnlyWhenUnusable(): void
    {
        // Missing root: usable, nothing is created.
        $missing = $this->base.'/shared/var/not-yet';
        $tester = $this->tester($missing);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString("homework_pdf_inventory_status=root_missing\n", $tester->getDisplay(true));
        self::assertDirectoryDoesNotExist($missing);

        // Unsafe root: fails with the status only, no counters.
        $tester = $this->tester('var/relative-homework-pdf');
        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay(true);
        self::assertStringContainsString("homework_pdf_inventory_status=root_unsafe\n", $display);
        self::assertStringNotContainsString('disk_files=', $display);
    }

    public function testInternalFailureIsReportedByCodeWithoutLeakingTheMessage(): void
    {
        $this->source->failWith = new \RuntimeException('SQLSTATE secret '.$this->base);
        file_put_contents($this->durable.'/'.str_repeat('a', 32).'.bin', 'x');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame("homework_pdf_inventory_status=error\nhomework_pdf_inventory_code=inventory_failed\n", $tester->getDisplay(true));
    }

    public function testCommandHasNoWriteDeleteOrApplyMode(): void
    {
        $definition = (new HomeworkPdfInventoryCommand($this->service($this->durable)))->getDefinition();

        foreach (['apply', 'delete', 'force', 'purge', 'yes', 'write', 'fix', 'execute'] as $name) {
            self::assertFalse($definition->hasOption($name), $name);
        }
        self::assertSame(['orphan-min-age', 'by-institution', 'preview-limit-bytes'], array_keys($definition->getOptions()));
    }

    public function testInventoryIsNotWiredIntoAnySchedulerWorkflowOrAdminScreen(): void
    {
        $root = \dirname(__DIR__, 2);
        $needle = 'homework-pdf:inventory';
        $scanned = 0;
        foreach (['.github', 'config', 'deploy', 'templates', 'assets', 'docker'] as $dir) {
            if (!is_dir($root.'/'.$dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getSize() > 2_000_000) {
                    continue;
                }
                ++$scanned;
                self::assertStringNotContainsString($needle, (string) file_get_contents($file->getPathname()), $file->getFilename());
            }
        }
        self::assertGreaterThan(20, $scanned);
        // The controllers never call the service either.
        foreach (glob($root.'/src/Controller/*.php') ?: [] as $controller) {
            self::assertStringNotContainsString('HomeworkPdfStorageInventory', (string) file_get_contents($controller), basename($controller));
        }
    }

    public function testUploadAndScanDefaultsStayClosedAndTheInventoryDoesNotTouchThem(): void
    {
        $root = \dirname(__DIR__, 2);
        $services = (string) file_get_contents($root.'/config/services.yaml');
        self::assertStringContainsString("env(HOMEWORK_PDF_UPLOAD_ENABLED): '0'", $services);
        self::assertStringContainsString("env(HOMEWORK_PDF_SCAN_READY): '0'", $services);
        self::assertStringContainsString("env(HOMEWORK_PDF_CLAMD_STREAM_MAX_BYTES): '0'", $services);

        foreach ([
            'src/Homework/Pdf/HomeworkPdfStorageInventory.php',
            'src/Homework/Pdf/DbalHomeworkPdfInventorySource.php',
            'src/Homework/Pdf/HomeworkPdfInventoryReport.php',
            'src/Command/HomeworkPdfInventoryCommand.php',
        ] as $file) {
            $code = (string) file_get_contents($root.'/'.$file);
            foreach (['HOMEWORK_PDF_UPLOAD_ENABLED', 'HOMEWORK_PDF_SCAN_READY', 'HomeworkPdfUploadGate', 'HomeworkPdfScanReadiness', 'getenv(', '$_ENV', '$_SERVER'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $code, $file.' must not read or change '.$forbidden);
            }
        }
    }

    private function seedStore(): void
    {
        $old = $this->now - 3 * 86400;
        $files = [
            ['11', 'aaaaa', $old],            // referenced, institution A (5 bytes)
            ['12', 'bbbbbbb', $old],          // referenced, institution B (7 bytes)
            ['13', 'cc', $old],               // unreferenced and old: orphan candidate
            ['14', 'dd', $this->now - 30],    // unreferenced and young
        ];
        foreach ($files as [$n, $content, $mtime]) {
            $path = $this->durable.'/'.\sprintf('%032x', (int) $n).'.bin';
            file_put_contents($path, $content);
            touch($path, $mtime);
        }
        $created = (new \DateTimeImmutable('@'.($this->now - 100)))->setTimezone(new \DateTimeZone('UTC'));
        $this->source->references = [
            new HomeworkPdfInventoryReference(\sprintf('%032x', 11), 5, $created, HomeworkPdfAttachmentLifecycle::Submitted, self::A),
            new HomeworkPdfInventoryReference(\sprintf('%032x', 12), 7, $created, HomeworkPdfAttachmentLifecycle::Submitted, self::B),
            new HomeworkPdfInventoryReference(\sprintf('%032x', 99), 10, $created, HomeworkPdfAttachmentLifecycle::Draft, null),
        ];
    }

    private function tester(?string $durable = null): CommandTester
    {
        return new CommandTester(new HomeworkPdfInventoryCommand($this->service($durable ?? $this->durable)));
    }

    private function service(string $durable): HomeworkPdfStorageInventory
    {
        return new HomeworkPdfStorageInventory(
            new HomeworkPdfStoragePaths($durable, $this->base.'/shared/var/homework-pdf-quarantine', $this->base.'/project'),
            $this->source,
            new MockClock(new \DateTimeImmutable(self::NOW.' UTC')),
        );
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
