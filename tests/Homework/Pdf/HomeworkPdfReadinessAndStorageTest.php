<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\HomeworkPdfQuarantineCleaner;
use App\Homework\Pdf\HomeworkPdfScanException;
use App\Homework\Pdf\HomeworkPdfScanReadiness;
use App\Homework\Pdf\HomeworkPdfStorageFactory;
use App\Homework\Pdf\HomeworkPdfStoragePaths;
use App\Homework\Pdf\RuntimeHomeworkPdfScanReadiness;
use App\Question\Image\Scan\ClamavLocalDatabaseVersions;
use App\Question\Image\Scan\ClamdInstreamTransport;
use App\Question\Image\Scan\ClamdTransportException;
use App\Question\Image\Scan\CvdDatabaseDirectoryReader;
use App\Question\Image\Scan\UnixClamdTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class HomeworkPdfReadinessAndStorageTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupDirs) as $dir) {
            $this->removeTree($dir);
        }
        $this->cleanupDirs = [];
        parent::tearDown();
    }

    public function testStoragePathsDefaultsAndRejectsUnsafeLocations(): void
    {
        $root = $this->tempDir('hwpdf-paths');
        $projectDir = $root.\DIRECTORY_SEPARATOR.'project';
        self::assertTrue(mkdir($projectDir, 0700, true));

        $defaults = new HomeworkPdfStoragePaths('', '', $projectDir);
        self::assertSame($projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'homework-pdf', $defaults->durableDirectory());
        self::assertSame($projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'homework-pdf-quarantine', $defaults->quarantineDirectory());
        self::assertTrue($defaults->isSafeDurableDirectoryReady());
        self::assertTrue($defaults->isSafeQuarantineDirectoryReady());

        $sharedDurable = $root.\DIRECTORY_SEPARATOR.'shared'.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'homework-pdf';
        $sharedQuarantine = $root.\DIRECTORY_SEPARATOR.'shared'.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'homework-pdf-quarantine';
        self::assertTrue(mkdir($sharedDurable, 0700, true));
        self::assertTrue(mkdir($sharedQuarantine, 0700, true));

        $explicit = new HomeworkPdfStoragePaths($sharedDurable, $sharedQuarantine, $projectDir);
        self::assertTrue($explicit->isSafeDurableDirectoryReady());
        self::assertTrue($explicit->isSafeQuarantineDirectoryReady());

        $durableStore = HomeworkPdfStorageFactory::createDurableStore($explicit);
        $quarantine = HomeworkPdfStorageFactory::createQuarantine($explicit);
        $candidate = $quarantine->stage("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        try {
            $stored = $durableStore->storeFromLockedHandle($candidate->file());
            self::assertStringStartsWith($sharedDurable.\DIRECTORY_SEPARATOR, $stored->absolutePath());
            self::assertFileExists($stored->absolutePath());
        } finally {
            $candidate->deleteQuietly();
        }

        // Relative paths and path traversal must fail closed.
        $relative = new HomeworkPdfStoragePaths('var/homework-pdf', $sharedQuarantine, $projectDir);
        self::assertFalse($relative->isSafeDurableDirectoryReady());

        $traversal = new HomeworkPdfStoragePaths($root.'/shared/../shared/var/homework-pdf', $sharedQuarantine, $projectDir);
        self::assertFalse($traversal->isSafeDurableDirectoryReady());

        // Public paths must fail closed.
        self::assertTrue(mkdir($projectDir.\DIRECTORY_SEPARATOR.'public', 0700, true));
        $inPublic = new HomeworkPdfStoragePaths($projectDir.\DIRECTORY_SEPARATOR.'public'.\DIRECTORY_SEPARATOR.'homework-pdf', $sharedQuarantine, $projectDir);
        self::assertFalse($inPublic->isSafeDurableDirectoryReady());

        $inPublicHtml = new HomeworkPdfStoragePaths($root.\DIRECTORY_SEPARATOR.'public_html'.\DIRECTORY_SEPARATOR.'homework-pdf', $sharedQuarantine, $projectDir);
        self::assertFalse($inPublicHtml->isSafeDurableDirectoryReady());

        // Overlapping durable & quarantine roots must fail closed.
        $sameRoot = new HomeworkPdfStoragePaths($sharedDurable, $sharedDurable, $projectDir);
        self::assertFalse($sameRoot->isSafeDurableDirectoryReady());
        self::assertFalse($sameRoot->isSafeQuarantineDirectoryReady());

        $nested = new HomeworkPdfStoragePaths($sharedDurable, $sharedDurable.\DIRECTORY_SEPARATOR.'sub', $projectDir);
        self::assertFalse($nested->isSafeDurableDirectoryReady());
        self::assertFalse($nested->isSafeQuarantineDirectoryReady());
    }

    public function testStoragePathsRejectsReleaseScopedAndSymlinkedAppPaths(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX symlink behavior required for release symlink test');
        }

        $root = $this->tempDir('hwpdf-rel');
        $releaseDir = $root.'/releases/20261124000000-abcdef123456';
        $sharedQuarantine = $root.'/shared/var/homework-pdf-quarantine';
        self::assertTrue(mkdir($releaseDir.'/var/homework-pdf', 0700, true));
        self::assertTrue(mkdir($sharedQuarantine, 0700, true));
        $appLink = $root.'/app';
        self::assertTrue(symlink($releaseDir, $appLink));

        // Explicit release directory path fails closed.
        $explicitRelease = new HomeworkPdfStoragePaths($releaseDir.'/var/homework-pdf', $sharedQuarantine, $appLink);
        self::assertTrue($explicitRelease->isReleaseScopedPath($releaseDir.'/var/homework-pdf'));
        self::assertFalse($explicitRelease->isSafeDurableDirectoryReady());

        // Default project-relative path inside a release-linked app/ fails closed too.
        $defaultInsideReleaseApp = new HomeworkPdfStoragePaths('', $sharedQuarantine, $appLink);
        self::assertTrue($defaultInsideReleaseApp->isReleaseScopedPath($defaultInsideReleaseApp->durableDirectory()));
        self::assertFalse($defaultInsideReleaseApp->isSafeDurableDirectoryReady());

        // Direct symlink at storage root fails closed.
        $sharedReal = $root.'/shared/var/real-durable';
        $symlinkRoot = $root.'/shared/var/homework-pdf-link';
        self::assertTrue(mkdir($sharedReal, 0700, true));
        self::assertTrue(symlink($sharedReal, $symlinkRoot));
        $symlinkPaths = new HomeworkPdfStoragePaths($symlinkRoot, $sharedQuarantine, $root.'/project');
        self::assertFalse($symlinkPaths->isSafeDurableDirectoryReady());
    }

    public function testRuntimeReadinessFailsClosedUntilAllGuardsPass(): void
    {
        $root = $this->tempDir('hwpdf-ready');
        $durable = $root.'/shared/var/homework-pdf';
        $quarantine = $root.'/shared/var/homework-pdf-quarantine';
        $project = $root.'/project';
        self::assertTrue(mkdir($durable, 0700, true));
        self::assertTrue(mkdir($quarantine, 0700, true));
        self::assertTrue(mkdir($project, 0700, true));

        $safePaths = new HomeworkPdfStoragePaths($durable, $quarantine, $project);
        $validLocal = $this->localVersions(['main' => 63, 'daily' => 28193, 'bytecode' => 339]);
        $healthyTransport = $this->sequenceTransport([
            "PONG\n",
            "ClamAV 1.4.7/28193/Tue Nov 24 10:00:00 2026\n",
        ]);

        // 1. Flag off -> false.
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            false,
            '/run/clamd.scan/clamd.sock',
            $safePaths,
            $validLocal,
            $healthyTransport,
        ))->isReady());

        // 2. Empty or invalid socket path -> false.
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            true,
            '',
            $safePaths,
            $validLocal,
            $healthyTransport,
        ))->isReady());
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            true,
            'tcp://127.0.0.1:3310',
            $safePaths,
            $validLocal,
            $healthyTransport,
        ))->isReady());

        // 3. Unsafe release-scoped storage path -> false.
        $unsafePaths = new HomeworkPdfStoragePaths($root.'/releases/20261124-0001/var/homework-pdf', $quarantine, $project);
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            true,
            '/run/clamd.scan/clamd.sock',
            $unsafePaths,
            $validLocal,
            $healthyTransport,
        ))->isReady());

        // 4. Unreadable CVD directory -> false.
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            true,
            '/run/clamd.scan/clamd.sock',
            $safePaths,
            $this->localVersions(null),
            $healthyTransport,
        ))->isReady());

        // 5. Transport failure on nPING -> false.
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            true,
            '/run/clamd.scan/clamd.sock',
            $safePaths,
            $validLocal,
            $this->throwingTransport(ClamdTransportException::connectionFailed()),
        ))->isReady());

        // 6. Unexpected nPING response -> false.
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            true,
            '/run/clamd.scan/clamd.sock',
            $safePaths,
            $validLocal,
            $this->sequenceTransport(['NOPE\n', "ClamAV 1.4.7/28193/Tue Nov 24 10:00:00 2026\n"]),
        ))->isReady());

        // 7. Loaded daily mismatch vs disk daily -> false.
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            true,
            '/run/clamd.scan/clamd.sock',
            $safePaths,
            $validLocal,
            $this->sequenceTransport(["PONG\n", "ClamAV 1.4.7/28192/Tue Nov 24 10:00:00 2026\n"]),
        ))->isReady());

        // 8. Malformed nVERSION -> false.
        self::assertFalse((new RuntimeHomeworkPdfScanReadiness(
            true,
            '/run/clamd.scan/clamd.sock',
            $safePaths,
            $validLocal,
            $this->sequenceTransport(["PONG\n", "UNKNOWN\n"]),
        ))->isReady());

        // 9. All guards pass -> true.
        self::assertTrue((new RuntimeHomeworkPdfScanReadiness(
            true,
            '/run/clamd.scan/clamd.sock',
            $safePaths,
            $validLocal,
            $this->sequenceTransport(["PONG\n", "ClamAV 1.4.7/28193/Tue Nov 24 10:00:00 2026\n"]),
        ))->isReady());
    }

    public function testRuntimeReadinessAgainstRealUnixSocketAndCvdHeaders(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('Unix domain socket server test requires POSIX');
        }
        if (!\in_array('unix', stream_get_transports(), true)) {
            self::markTestSkipped('unix stream transport unavailable');
        }

        $root = $this->tempDir('hwpdf-unix');
        $durable = $root.'/shared/var/homework-pdf';
        $quarantine = $root.'/shared/var/homework-pdf-quarantine';
        $dbDir = $root.'/clamav-db';
        $project = $root.'/project';
        self::assertTrue(mkdir($durable, 0700, true));
        self::assertTrue(mkdir($quarantine, 0700, true));
        self::assertTrue(mkdir($dbDir, 0700, true));
        self::assertTrue(mkdir($project, 0700, true));

        $this->writeCvdHeader($dbDir.'/main.cvd', 63);
        $this->writeCvdHeader($dbDir.'/daily.cld', 28193);
        $this->writeCvdHeader($dbDir.'/bytecode.cvd', 339);

        $socketPath = '/tmp/hwpdf-r-'.bin2hex(random_bytes(4)).'.sock';
        @unlink($socketPath);

        $serverCode = <<<'PHP'
$sock = stream_socket_server('unix://' . $argv[1], $errno, $errstr);
if (!is_resource($sock)) { exit(2); }
$deadline = microtime(true) + 5.0;
while (microtime(true) < $deadline) {
    $r = [$sock]; $w = null; $e = null;
    if (stream_select($r, $w, $e, 0, 200000) > 0) {
        $conn = @stream_socket_accept($sock, 0.2);
        if (is_resource($conn)) {
            $cmd = fgets($conn, 128);
            if ("nPING\n" === $cmd) {
                fwrite($conn, "PONG\n");
            } elseif ("nVERSION\n" === $cmd) {
                fwrite($conn, "ClamAV 1.4.7/28193/Tue Nov 24 10:00:00 2026\n");
            }
            fclose($conn);
        }
    }
}
@unlink($argv[1]);
PHP;
        $proc = proc_open(
            [\PHP_BINARY, '-r', $serverCode, '--', $socketPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        try {
            $waitUntil = microtime(true) + 3.0;
            while (!file_exists($socketPath) && microtime(true) < $waitUntil) {
                usleep(20_000);
            }
            self::assertFileExists($socketPath);

            $readiness = new RuntimeHomeworkPdfScanReadiness(
                true,
                $socketPath,
                new HomeworkPdfStoragePaths($durable, $quarantine, $project),
                new CvdDatabaseDirectoryReader($dbDir),
                new UnixClamdTransport($socketPath),
            );
            self::assertTrue($readiness->isReady());
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($proc);
            proc_close($proc);
            @unlink($socketPath);
        }
    }

    public function testQuarantineCleanerRemovesOnlyUnlockedExpiredCandidates(): void
    {
        $root = $this->tempDir('hwpdf-clean');
        $durable = $root.\DIRECTORY_SEPARATOR.'durable';
        $quarantine = $root.\DIRECTORY_SEPARATOR.'quarantine';
        $project = $root.\DIRECTORY_SEPARATOR.'project';
        self::assertTrue(mkdir($durable, 0700, true));
        self::assertTrue(mkdir($quarantine, 0700, true));
        self::assertTrue(mkdir($project, 0700, true));

        $now = 1_800_000_000;
        $staleTmp = $quarantine.\DIRECTORY_SEPARATOR.'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.tmp';
        $staleBin = $quarantine.\DIRECTORY_SEPARATOR.'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.bin';
        $lockedBin = $quarantine.\DIRECTORY_SEPARATOR.'cccccccccccccccccccccccccccccccc.bin';
        $freshTmp = $quarantine.\DIRECTORY_SEPARATOR.'dddddddddddddddddddddddddddddddd.tmp';
        $nonMatching = $quarantine.\DIRECTORY_SEPARATOR.'README.txt';
        $durableFile = $durable.\DIRECTORY_SEPARATOR.'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee.bin';

        file_put_contents($staleTmp, 'tmp');
        file_put_contents($staleBin, 'bin');
        file_put_contents($lockedBin, 'locked');
        file_put_contents($freshTmp, 'fresh');
        file_put_contents($nonMatching, 'keep');
        file_put_contents($durableFile, 'durable');

        touch($staleTmp, $now - 7200);
        touch($staleBin, $now - 3601);
        touch($lockedBin, $now - 7200);
        touch($freshTmp, $now - 100);
        touch($nonMatching, $now - 7200);

        $symlinkEntry = null;
        if ('\\' !== \DIRECTORY_SEPARATOR) {
            $symlinkEntry = $quarantine.\DIRECTORY_SEPARATOR.'ffffffffffffffffffffffffffffffff.bin';
            self::assertTrue(symlink($durableFile, $symlinkEntry));
        }

        $cleaner = new HomeworkPdfQuarantineCleaner(new HomeworkPdfStoragePaths($durable, $quarantine, $project));

        $lockHandle = fopen($lockedBin, 'r+');
        self::assertIsResource($lockHandle);
        self::assertTrue(flock($lockHandle, \LOCK_EX | \LOCK_NB));

        try {
            // Dry run counts stale unlocked files without deleting.
            $dryCount = $cleaner->purgeStale(3600, $now, true);
            $expectedCount = '\\' === \DIRECTORY_SEPARATOR ? 3 : 2;
            self::assertSame($expectedCount, $dryCount);
            self::assertFileExists($staleTmp);
            self::assertFileExists($staleBin);

            // Real run deletes stale unlocked candidates only.
            $purged = $cleaner->purgeStale(3600, $now, false);
            self::assertSame($expectedCount, $purged);
            self::assertFileDoesNotExist($staleTmp);
            self::assertFileDoesNotExist($staleBin);
            self::assertFileExists($freshTmp);
            self::assertFileExists($nonMatching);
            self::assertFileExists($durableFile);
            if ('\\' !== \DIRECTORY_SEPARATOR) {
                self::assertFileExists($lockedBin);
                self::assertNotNull($symlinkEntry);
                self::assertTrue(is_link($symlinkEntry));
            }
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }

        if ('\\' !== \DIRECTORY_SEPARATOR) {
            // Symlink quarantine root fails closed.
            $linkRoot = $root.\DIRECTORY_SEPARATOR.'q-link';
            self::assertTrue(symlink($quarantine, $linkRoot));
            $badCleaner = new HomeworkPdfQuarantineCleaner(new HomeworkPdfStoragePaths($durable, $linkRoot, $project));
            $this->expectException(HomeworkPdfScanException::class);
            $badCleaner->purgeStale(3600, $now, false);
        }
    }

    public function testConfigDefaultsKeepUploadClosedAndWireRuntimeReadinessInProd(): void
    {
        $root = \dirname(__DIR__, 3);
        $services = Yaml::parseFile($root.'/config/services.yaml');
        self::assertIsArray($services);
        self::assertSame('0', $services['parameters']['env(HOMEWORK_PDF_UPLOAD_ENABLED)'] ?? null);
        self::assertSame('0', $services['parameters']['env(HOMEWORK_PDF_SCAN_READY)'] ?? null);
        self::assertSame('', $services['parameters']['env(HOMEWORK_PDF_STORAGE_DIR)'] ?? null);
        self::assertSame('', $services['parameters']['env(HOMEWORK_PDF_QUARANTINE_DIR)'] ?? null);
        self::assertSame(
            RuntimeHomeworkPdfScanReadiness::class,
            $services['services'][HomeworkPdfScanReadiness::class]['alias'] ?? null,
        );

        foreach (['.env', '.env.example', '.env.test'] as $envFile) {
            $contents = (string) file_get_contents($root.'/'.$envFile);
            self::assertStringContainsString('HOMEWORK_PDF_UPLOAD_ENABLED=0', $contents, $envFile);
            self::assertStringContainsString('HOMEWORK_PDF_SCAN_READY=0', $contents, $envFile);
            self::assertStringContainsString('HOMEWORK_PDF_STORAGE_DIR=', $contents, $envFile);
            self::assertStringContainsString('HOMEWORK_PDF_QUARANTINE_DIR=', $contents, $envFile);
        }
    }

    /**
     * @param ?array{main: int, daily: int, bytecode: int} $versions
     */
    private function localVersions(?array $versions): ClamavLocalDatabaseVersions
    {
        return new class($versions) implements ClamavLocalDatabaseVersions {
            /** @param ?array{main: int, daily: int, bytecode: int} $versions */
            public function __construct(private readonly ?array $versions)
            {
            }

            public function read(): ?array
            {
                return $this->versions;
            }
        };
    }

    /**
     * @param list<string> $replies
     */
    private function sequenceTransport(array $replies): ClamdInstreamTransport
    {
        return new class($replies) implements ClamdInstreamTransport {
            private int $index = 0;

            /** @param list<string> $replies */
            public function __construct(private readonly array $replies)
            {
            }

            public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string
            {
                $reply = $this->replies[$this->index] ?? '';
                ++$this->index;

                return $reply;
            }
        };
    }

    private function throwingTransport(\Throwable $error): ClamdInstreamTransport
    {
        return new class($error) implements ClamdInstreamTransport {
            public function __construct(private readonly \Throwable $error)
            {
            }

            public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string
            {
                throw $this->error;
            }
        };
    }

    private function writeCvdHeader(string $path, int $version): void
    {
        $header = \sprintf('ClamAV-VDB:24 Nov 2026 09-00 +0000:%d:100:0:90:abc:builder', $version);
        file_put_contents($path, str_pad($header, 512, "\0"));
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700));
        $this->cleanupDirs[] = $dir;

        return $dir;
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
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->removeTree($path.\DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }
}
