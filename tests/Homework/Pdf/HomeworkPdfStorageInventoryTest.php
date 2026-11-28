<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\HomeworkPdfAttachmentLifecycle;
use App\Homework\Pdf\HomeworkPdfInventoryReference;
use App\Homework\Pdf\HomeworkPdfInventoryReport;
use App\Homework\Pdf\HomeworkPdfStorageInventory;
use App\Homework\Pdf\HomeworkPdfStoragePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Read-only homework PDF storage inventory against a temporary store, a fixed clock and an in-memory database
 * side. No kernel, no database, no network. The database-backed view is covered in
 * HomeworkPdfInventoryDatabaseTest.
 */
final class HomeworkPdfStorageInventoryTest extends TestCase
{
    private const NOW = '2026-11-10 12:00:00';
    private const DAY = 86400;

    private string $base;
    private string $durable;
    private string $quarantine;
    private string $project;
    private int $now;
    private FakeHomeworkPdfInventorySource $source;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'hwinv-'.bin2hex(random_bytes(5));
        $this->durable = $this->base.'/shared/var/homework-pdf';
        $this->quarantine = $this->base.'/shared/var/homework-pdf-quarantine';
        $this->project = $this->base.'/project';
        self::assertTrue(mkdir($this->project, 0700, true));
        self::assertTrue(mkdir($this->base.'/shared/var', 0700, true));
        $this->now = (new \DateTimeImmutable(self::NOW.' UTC'))->getTimestamp();
        $this->source = new FakeHomeworkPdfInventorySource();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->durable)) {
            @chmod($this->durable, 0700);
        }
        $this->removeTree($this->base);
    }

    public function testDatabaseLinkedOldFilesAreNeverOrphanCandidates(): void
    {
        $this->makeDurable();
        $submitted = $this->put($this->key(1), 'aaaaaaaaaa', $this->now - 30 * self::DAY);
        $evaluated = $this->put($this->key(2), 'bbbbbbbbbbbbbbbbbbbb', $this->now - 30 * self::DAY);
        $draft = $this->put($this->key(3), 'cccccccccccccccccccccccccccccc', $this->now - 30 * self::DAY);
        $this->source->references = [
            $this->ref($this->key(1), 10, HomeworkPdfAttachmentLifecycle::Submitted),
            $this->ref($this->key(2), 20, HomeworkPdfAttachmentLifecycle::Evaluated),
            $this->ref($this->key(3), 30, HomeworkPdfAttachmentLifecycle::Draft),
        ];

        $report = $this->preview();

        self::assertSame(HomeworkPdfInventoryReport::STATUS_OK, $report->status);
        self::assertSame(3, $report->count('matched_files'));
        self::assertSame(60, $report->count('matched_bytes'));
        self::assertSame(0, $report->count('orphan_candidate_files'));
        self::assertSame(0, $report->count('unreferenced_young_files'));
        self::assertSame(3, $report->count('db_references'));
        self::assertSame(60, $report->count('db_declared_bytes'));
        self::assertSame(60, $report->count('db_physical_bytes'));
        self::assertSame([1, 1, 1], [$report->count('draft_references'), $report->count('submitted_references'), $report->count('evaluated_references')]);
        self::assertSame([30, 10, 20], [$report->count('draft_physical_bytes'), $report->count('submitted_physical_bytes'), $report->count('evaluated_physical_bytes')]);
        self::assertFileExists($submitted);
        self::assertFileExists($evaluated);
        self::assertFileExists($draft);
    }

    public function testYoungAndOldUnreferencedFilesAreSeparatedAndTheThresholdIsInclusive(): void
    {
        $this->makeDurable();
        $min = 3 * 3600;
        $this->put($this->key(10), '12345', $this->now - $min + 1);     // one second too young
        $this->put($this->key(11), '123456', $this->now - $min);        // exactly at the threshold: candidate
        $this->put($this->key(12), '1234567', $this->now - $min - 1);   // older: candidate
        $this->put($this->key(13), '12345678', $this->now);             // just written

        $report = $this->preview($min);

        self::assertSame(2, $report->count('unreferenced_young_files'));
        self::assertSame(13, $report->count('unreferenced_young_bytes'));
        self::assertSame(2, $report->count('orphan_candidate_files'));
        self::assertSame(13, $report->count('orphan_candidate_bytes'));
        self::assertSame(4, $report->count('disk_files'));
        self::assertSame(26, $report->count('disk_bytes'));
        self::assertSame(0, $report->count('matched_files'));
        self::assertSame(0, $report->count('timestamp_unreliable_files'));
    }

    public function testFutureTimestampAndTempFilesAreHandledOnTheSafeSide(): void
    {
        $this->makeDurable();
        $this->put($this->key(20), 'future', $this->now + 600);
        $this->put($this->key(21), 'oldtemp', $this->now - 5 * self::DAY, 'tmp');
        $this->put($this->key(22), 'youngtemp', $this->now - 10, 'tmp');

        $report = $this->preview();

        // A timestamp in the future is not evidence of age: kept, counted separately.
        self::assertSame(2, $report->count('unreferenced_young_files'));
        self::assertSame(1, $report->count('timestamp_unreliable_files'));
        // An interrupted write is a candidate only when old enough, and counted as a temp file.
        self::assertSame(1, $report->count('orphan_candidate_files'));
        self::assertSame(1, $report->count('orphan_candidate_temp_files'));
        self::assertSame(3, $report->count('disk_files'));
    }

    public function testMissingRootIsNeverCreatedAndAllRowsAreMissingFiles(): void
    {
        $this->source->references = [
            $this->ref($this->key(30), 10, HomeworkPdfAttachmentLifecycle::Submitted),
            $this->ref($this->key(31), 20, HomeworkPdfAttachmentLifecycle::Draft),
        ];

        $report = $this->preview();

        self::assertSame(HomeworkPdfInventoryReport::STATUS_ROOT_MISSING, $report->status);
        self::assertTrue($report->isUsable());
        self::assertFalse($report->storeEmpty);
        self::assertSame(2, $report->count('db_missing_file'));
        self::assertSame(0, $report->count('db_physical_bytes'));
        self::assertSame(30, $report->count('db_declared_bytes'));
        self::assertDirectoryDoesNotExist($this->durable);
        self::assertSame([], $this->source->lookups);
    }

    public function testEmptyStoreIsDifferentFromMissingAndUnreadableRoots(): void
    {
        $this->makeDurable();

        $empty = $this->preview();
        self::assertSame(HomeworkPdfInventoryReport::STATUS_OK, $empty->status);
        self::assertTrue($empty->storeEmpty);
        self::assertSame(0, $empty->count('disk_files'));
        self::assertSame(0, $empty->count('db_references'));

        file_put_contents($this->durable.'/notes.txt', 'x');
        $withStrangeEntry = $this->preview();
        self::assertFalse($withStrangeEntry->storeEmpty);
        self::assertSame(1, $withStrangeEntry->count('unsafe_invalid_name'));
    }

    public function testUnreadableRootIsNotReportedAsAnEmptyStore(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            self::markTestSkipped('Directory permissions cannot be restricted for this user or platform.');
        }
        $this->makeDurable();
        $this->put($this->key(40), 'data', $this->now - 5 * self::DAY);
        self::assertTrue(chmod($this->durable, 0000));
        clearstatcache();

        $report = $this->preview();

        self::assertSame(HomeworkPdfInventoryReport::STATUS_ROOT_UNREADABLE, $report->status);
        self::assertFalse($report->isUsable());
        self::assertFalse($report->storeEmpty);
        self::assertSame(0, $report->count('disk_files'));
        self::assertSame([], $this->source->lookups);
        self::assertSame(0, $this->source->referenceScans);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsafeRootProvider(): iterable
    {
        yield 'root is a regular file' => ['file'];
        yield 'root overlaps the quarantine root' => ['overlap'];
        yield 'root inside the public directory' => ['public'];
        yield 'root inside a release directory' => ['release'];
        yield 'relative root' => ['relative'];
    }

    #[DataProvider('unsafeRootProvider')]
    public function testUnsafeRootStopsBeforeAnyDatabaseOrDiskRead(string $kind): void
    {
        $quarantine = $this->quarantine;
        $project = $this->project;
        $durable = match ($kind) {
            'file' => $this->base.'/shared/var/not-a-directory',
            'overlap' => $this->base.'/shared/var/homework-pdf-quarantine/inner',
            'public' => $this->project.'/public/homework-pdf',
            'release' => $this->base.'/releases/20261110T100000Z-aaaaaaaaaaaa/var/homework-pdf',
            default => 'var/homework-pdf',
        };
        if ('file' === $kind) {
            file_put_contents($durable, 'x');
        } elseif ('relative' !== $kind) {
            self::assertTrue(mkdir($durable, 0700, true));
            file_put_contents($durable.'/'.$this->key(50).'.bin', 'decoy');
            touch($durable.'/'.$this->key(50).'.bin', $this->now - 30 * self::DAY);
        }
        if ('release' === $kind) {
            $project = $this->base.'/releases/20261110T100000Z-aaaaaaaaaaaa';
        }
        $this->source->references = [$this->ref($this->key(50), 5, HomeworkPdfAttachmentLifecycle::Submitted)];

        $report = (new HomeworkPdfStorageInventory(
            new HomeworkPdfStoragePaths($durable, $quarantine, $project),
            $this->source,
            new MockClock(new \DateTimeImmutable(self::NOW.' UTC')),
        ))->preview();

        self::assertSame(HomeworkPdfInventoryReport::STATUS_ROOT_UNSAFE, $report->status);
        self::assertFalse($report->isUsable());
        self::assertSame(0, $report->count('disk_files'));
        self::assertSame(0, $report->count('db_references'));
        self::assertSame([], $this->source->lookups);
        self::assertSame(0, $this->source->referenceScans);
        if ('relative' !== $kind && 'file' !== $kind) {
            self::assertFileExists($durable.'/'.$this->key(50).'.bin');
        }
    }

    public function testUnsafeEntriesAreCountedAndNeverFollowedOrEntered(): void
    {
        $this->makeDurable();
        $outside = $this->base.'/foreign';
        self::assertTrue(mkdir($outside, 0700, true));
        file_put_contents($outside.'/'.$this->key(60).'.bin', 'foreign-content');
        touch($outside.'/'.$this->key(60).'.bin', $this->now - 30 * self::DAY);

        // A subdirectory holding a valid-looking object is not entered.
        self::assertTrue(mkdir($this->durable.'/sub'));
        file_put_contents($this->durable.'/sub/'.$this->key(61).'.bin', 'nested');
        touch($this->durable.'/sub/'.$this->key(61).'.bin', $this->now - 30 * self::DAY);
        // Names that are not 32 lowercase hex keys with a known suffix.
        file_put_contents($this->durable.'/notes.txt', 'x');
        file_put_contents($this->durable.'/'.strtoupper($this->key(62)).'.bin', 'upper');
        // One real, unreferenced old object so the scan has something to count.
        $this->put($this->key(63), 'real', $this->now - 30 * self::DAY);

        $symlinks = 0;
        if (@symlink($outside.'/'.$this->key(60).'.bin', $this->durable.'/'.$this->key(60).'.bin')) {
            $symlinks = 1;
        }

        $report = $this->preview();

        self::assertSame(1, $report->count('unsafe_directory'));
        self::assertSame($symlinks, $report->count('unsafe_symlink'));
        self::assertGreaterThanOrEqual(1, $report->count('unsafe_invalid_name'));
        self::assertSame(
            $report->count('unsafe_directory') + $report->count('unsafe_symlink') + $report->count('unsafe_special')
                + $report->count('unsafe_invalid_name') + $report->count('unsafe_stat_failed'),
            $report->count('unsafe_entries'),
        );
        // Only the one real file counts as stored; the nested, foreign and linked content is never counted.
        self::assertSame(1, $report->count('disk_files'));
        self::assertSame(4, $report->count('disk_bytes'));
        self::assertSame(1, $report->count('orphan_candidate_files'));
        self::assertSame('foreign-content', file_get_contents($outside.'/'.$this->key(60).'.bin'));
        self::assertSame('nested', file_get_contents($this->durable.'/sub/'.$this->key(61).'.bin'));
    }

    public function testQuarantineFilesAreNotPartOfTheDurableInventory(): void
    {
        $this->makeDurable();
        self::assertTrue(mkdir($this->quarantine, 0700, true));
        file_put_contents($this->quarantine.'/'.$this->key(70).'.bin', 'quarantined');
        touch($this->quarantine.'/'.$this->key(70).'.bin', $this->now - 30 * self::DAY);

        $report = $this->preview();

        self::assertSame(0, $report->count('disk_files'));
        self::assertSame(0, $report->count('orphan_candidate_files'));
        self::assertFileExists($this->quarantine.'/'.$this->key(70).'.bin');
    }

    public function testDatabaseSideInconsistenciesAreSeparatedAndNeverCountedTwice(): void
    {
        $this->makeDurable();
        $this->put($this->key(80), '12345', $this->now - 2 * self::DAY);      // declared 99, really 5 bytes
        $this->put($this->key(81), '1234567', $this->now - 2 * self::DAY);    // referenced by two rows
        $this->put($this->key(82), '123', $this->now - 2 * self::DAY);        // owner cannot be derived
        $this->put($this->key(83), '12', $this->now - 2 * self::DAY);         // institution B
        $this->put($this->key(84), '1234', $this->now - 2 * self::DAY);       // institution A
        $linkOk = false;
        $outside = $this->base.'/foreign';
        self::assertTrue(mkdir($outside, 0700, true));
        file_put_contents($outside.'/target.bin', 'target');
        if (@symlink($outside.'/target.bin', $this->durable.'/'.$this->key(85).'.bin')) {
            $linkOk = true;
        }
        $a = '11111111-1111-4111-8111-111111111111';
        $b = '22222222-2222-4222-8222-222222222222';
        $this->source->references = [
            $this->ref($this->key(79), 10, HomeworkPdfAttachmentLifecycle::Submitted, $a),        // file missing
            $this->ref($this->key(80), 99, HomeworkPdfAttachmentLifecycle::Submitted, $a),        // size mismatch
            $this->ref($this->key(81), 7, HomeworkPdfAttachmentLifecycle::Submitted, $a),
            $this->ref($this->key(81), 7, HomeworkPdfAttachmentLifecycle::Submitted, $a),         // duplicate reference
            $this->ref($this->key(82), 3, HomeworkPdfAttachmentLifecycle::Evaluated, null),       // ambiguous owner
            $this->ref($this->key(83), 2, HomeworkPdfAttachmentLifecycle::Draft, $b),
            $this->ref($this->key(84), 4, HomeworkPdfAttachmentLifecycle::Submitted, $a),
            $this->ref($this->key(85), 6, HomeworkPdfAttachmentLifecycle::Submitted, $a),         // symlink instead of a file
            $this->ref('NOT-A-STORAGE-KEY', 8, HomeworkPdfAttachmentLifecycle::Submitted, $a),    // malformed key
        ];

        $report = $this->preview();

        self::assertSame(1, $report->count('db_size_mismatch'));
        self::assertSame(1, $report->count('db_invalid_key'));
        self::assertSame($linkOk ? 1 : 2, $report->count('db_missing_file'));
        self::assertSame($linkOk ? 1 : 0, $report->count('db_unreadable_file'));
        self::assertSame(1, $report->count('duplicate_reference'));
        self::assertSame(1, $report->count('ambiguous_owner_references'));
        // The shared key is one physical object: 7 bytes once, while the database declares it twice.
        self::assertSame(9, $report->count('db_references'));
        self::assertSame(5 + 7 + 3 + 2 + 4, $report->count('db_physical_bytes'));
        self::assertSame(10 + 99 + 7 + 7 + 3 + 2 + 4 + 6 + 8, $report->count('db_declared_bytes'));
        // Institution totals are separate, and the ambiguous row is charged to nobody.
        self::assertSame(['references' => 7, 'declared_bytes' => 10 + 99 + 7 + 7 + 4 + 6 + 8, 'physical_bytes' => 5 + 7 + 4], $report->institutions[$a]);
        self::assertSame(['references' => 1, 'declared_bytes' => 2, 'physical_bytes' => 2], $report->institutions[$b]);
        self::assertSame(['references' => 1, 'declared_bytes' => 3, 'physical_bytes' => 3], $report->unknownOwner);
        self::assertSame([$a, $b], array_keys($report->institutions));
    }

    public function testDraftsOlderThanTheThresholdAreOnlyCountedNeverMadeCandidates(): void
    {
        $this->makeDurable();
        $this->put($this->key(90), 'old-draft', $this->now - 40 * self::DAY);
        $this->put($this->key(91), 'new-draft', $this->now - 60);
        $this->source->references = [
            $this->ref($this->key(90), 9, HomeworkPdfAttachmentLifecycle::Draft, null, $this->now - 40 * self::DAY),
            $this->ref($this->key(91), 9, HomeworkPdfAttachmentLifecycle::Draft, null, $this->now - 60),
            $this->ref($this->key(92), 1, HomeworkPdfAttachmentLifecycle::Draft, null, null),
            $this->ref($this->key(93), 1, HomeworkPdfAttachmentLifecycle::Evaluated, null, $this->now - 90 * self::DAY),
            $this->ref($this->key(94), 1, HomeworkPdfAttachmentLifecycle::Unknown, null, $this->now - 90 * self::DAY),
        ];

        $report = $this->preview();

        self::assertSame(1, $report->count('draft_older_than_threshold'));
        self::assertSame(0, $report->count('orphan_candidate_files'));
        self::assertSame(2, $report->count('matched_files'));
        self::assertSame(1, $report->count('unknown_lifecycle_references'));
    }

    public function testInventoryChangesNothingOnDiskAndReadsInBoundedBatches(): void
    {
        $this->makeDurable();
        $count = 2 * HomeworkPdfStorageInventory::BATCH_SIZE + 203;
        for ($i = 1; $i <= $count; ++$i) {
            $this->put($this->key(1000 + $i), 'x', $this->now - 30 * self::DAY);
        }
        $this->put($this->key(2), 'orphan-old', $this->now - 30 * self::DAY);
        $before = $this->snapshot($this->base);

        $report = $this->preview();

        self::assertSame($before, $this->snapshot($this->base), 'the inventory must not change any file, name, size, time or directory');
        self::assertSame($count + 1, $report->count('orphan_candidate_files'));
        // One batched lookup per 500 listed objects, never one lookup per file.
        self::assertSame([500, 500, 204], $this->source->lookups);
        self::assertSame(1, $this->source->referenceScans);
        self::assertDirectoryDoesNotExist($this->quarantine);
    }

    public function testDatabaseFailureSurfacesAsAnExceptionNeverAsAnUnreadableRoot(): void
    {
        $this->makeDurable();
        $this->put($this->key(5), 'data', $this->now - 30 * self::DAY);
        $this->source->failWith = new \RuntimeException('database down');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database down');
        $this->preview();
    }

    public function testThresholdBelowTheLockWaitFloorIsRejected(): void
    {
        $this->makeDurable();
        $this->expectException(\InvalidArgumentException::class);
        $this->preview(HomeworkPdfStorageInventory::MIN_ORPHAN_MIN_AGE_SECONDS - 1);
    }

    public function testInventoryCodeContainsNoWriteDeleteOrContentReadingCall(): void
    {
        $root = \dirname(__DIR__, 3).'/src/';
        $files = [
            $root.'Homework/Pdf/HomeworkPdfStorageInventory.php',
            $root.'Homework/Pdf/DbalHomeworkPdfInventorySource.php',
            $root.'Command/HomeworkPdfInventoryCommand.php',
        ];
        $forbidden = [
            'unlink(', 'rename(', 'copy(', 'mkdir(', 'rmdir(', 'chmod(', 'chown(', 'touch(', 'symlink(', 'file_put_contents(',
            'fopen(', 'fwrite(', 'file_get_contents(', 'readfile(', 'hash_file(', 'md5_file(', 'sha1_file(', 'flock(', 'exec(', 'shell_exec(',
            'proc_open(', 'system(', 'passthru(', 'putenv(', 'RecursiveDirectoryIterator', 'glob(', 'scandir(',
        ];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString($needle, $code, basename($file).' must not use '.$needle);
            }
        }
        $sql = (string) file_get_contents($root.'Homework/Pdf/DbalHomeworkPdfInventorySource.php');
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'REPLACE ', 'ALTER ', 'DROP ', 'TRUNCATE ', 'executeStatement', 'beginTransaction', 'FOR UPDATE', 'LOCK IN SHARE'] as $needle) {
            self::assertStringNotContainsString($needle, $sql);
        }
    }

    private function preview(int $minAge = HomeworkPdfStorageInventory::DEFAULT_ORPHAN_MIN_AGE_SECONDS): HomeworkPdfInventoryReport
    {
        return (new HomeworkPdfStorageInventory(
            new HomeworkPdfStoragePaths($this->durable, $this->quarantine, $this->project),
            $this->source,
            new MockClock(new \DateTimeImmutable(self::NOW.' UTC')),
        ))->preview($minAge);
    }

    private function makeDurable(): void
    {
        self::assertTrue(mkdir($this->durable, 0700, true));
    }

    private function key(int $n): string
    {
        return \sprintf('%032x', $n);
    }

    private function put(string $key, string $content, int $mtime, string $suffix = 'bin'): string
    {
        $path = $this->durable.'/'.$key.'.'.$suffix;
        file_put_contents($path, $content);
        self::assertTrue(touch($path, $mtime));

        return $path;
    }

    private function ref(
        string $key,
        int $bytes,
        HomeworkPdfAttachmentLifecycle $lifecycle,
        ?string $institution = '33333333-3333-4333-8333-333333333333',
        ?int $createdAt = 0,
    ): HomeworkPdfInventoryReference {
        $created = null === $createdAt ? null : (new \DateTimeImmutable('@'.($createdAt > 0 ? $createdAt : $this->now - 60)))->setTimezone(new \DateTimeZone('UTC'));

        return new HomeworkPdfInventoryReference($key, $bytes, $created, $lifecycle, $institution);
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(string $dir): array
    {
        $result = [];
        $this->walk($dir, '', $result);
        ksort($result);

        return $result;
    }

    /**
     * @param array<string, string> $result
     */
    private function walk(string $dir, string $prefix, array &$result): void
    {
        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            $rel = $prefix.'/'.$entry;
            if (is_link($path)) {
                $result[$rel] = 'link:'.readlink($path);
            } elseif (is_dir($path)) {
                $result[$rel] = 'dir';
                $this->walk($path, $rel, $result);
            } else {
                clearstatcache(true, $path);
                $result[$rel] = 'file:'.filesize($path).':'.filemtime($path).':'.md5((string) file_get_contents($path));
            }
        }
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
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
