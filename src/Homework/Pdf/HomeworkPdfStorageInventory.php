<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use Psr\Clock\ClockInterface;

/**
 * Read-only, point-in-time inventory of the durable homework PDF store against the attachment rows.
 *
 * It never writes: no delete, no move, no chmod, no mkdir, no database write, no lock. It scans only the
 * configured durable root, never follows a symlink, never leaves that root, never creates a missing root, and
 * never reads PDF content or computes a hash. Sizes come from file metadata.
 *
 * Two bounded passes (no per-file query, no full listing in memory):
 *  1. Directory pass: classify every top-level entry. `<32-hex>.bin` names are looked up in batches against the
 *     database; unmatched files are "young" (kept) or "orphan candidates" by modification time.
 *  2. Database pass: keyset pages of attachment rows; each row is checked on disk by metadata only.
 *
 * An orphan candidate is not a deletable file. A concurrent upload writes the durable file before its database
 * row is visible, an adoption copy or a restore can reset a modification time, and the two passes see different
 * instants. The result is a preview that only narrows what a later, separately approved step may examine.
 */
final class HomeworkPdfStorageInventory
{
    /** Test default only. The production retention window is an open product decision. */
    public const DEFAULT_ORPHAN_MIN_AGE_SECONDS = 86400;
    /** Longer than any database lock wait, so an in-flight upload is never an orphan candidate. */
    public const MIN_ORPHAN_MIN_AGE_SECONDS = 3600;
    public const BATCH_SIZE = 500;

    private const NAME_PATTERN = '/^([0-9a-f]{32})\.(bin|tmp)$/';
    private const KEY_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        private readonly HomeworkPdfStoragePaths $paths,
        private readonly HomeworkPdfInventorySource $source,
        private readonly ClockInterface $clock,
    ) {
    }

    public function preview(int $orphanMinAgeSeconds = self::DEFAULT_ORPHAN_MIN_AGE_SECONDS): HomeworkPdfInventoryReport
    {
        if ($orphanMinAgeSeconds < self::MIN_ORPHAN_MIN_AGE_SECONDS) {
            throw new \InvalidArgumentException('orphanMinAgeSeconds must be at least 3600.');
        }

        [$status, $root] = $this->inspectRoot();
        if (HomeworkPdfInventoryReport::STATUS_ROOT_UNSAFE === $status || HomeworkPdfInventoryReport::STATUS_ROOT_UNREADABLE === $status) {
            return HomeworkPdfInventoryReport::withoutCounts($status, $orphanMinAgeSeconds);
        }

        $now = $this->clock->now()->getTimestamp();
        $counters = array_fill_keys(HomeworkPdfInventoryReport::COUNTERS, 0);
        $storeEmpty = false;

        if (null !== $root) {
            try {
                $iterator = new \DirectoryIterator($root);
            } catch (\UnexpectedValueException) {
                // A root that cannot be listed is not an empty store. Only this listing failure maps to the status:
                // a database failure inside the scan must surface as an error, not as an unreadable root.
                return HomeworkPdfInventoryReport::withoutCounts(HomeworkPdfInventoryReport::STATUS_ROOT_UNREADABLE, $orphanMinAgeSeconds);
            }
            $storeEmpty = $this->scanDirectory($iterator, $now, $orphanMinAgeSeconds, $counters);
        }

        $institutions = [];
        $unknownOwner = ['references' => 0, 'declared_bytes' => 0, 'physical_bytes' => 0];
        $this->scanReferences($root, $now, $orphanMinAgeSeconds, $counters, $institutions, $unknownOwner);
        ksort($institutions);

        return new HomeworkPdfInventoryReport($status, $storeEmpty, $orphanMinAgeSeconds, $counters, $institutions, $unknownOwner);
    }

    /**
     * @return array{0: string, 1: string|null} status and the resolved root (null while the root is missing)
     */
    private function inspectRoot(): array
    {
        $durable = $this->paths->durableDirectory();
        $quarantine = $this->paths->quarantineDirectory();
        if ($this->paths->isReleaseScopedPath($durable)
            || $this->paths->isInsidePublicDir($durable)
            || $this->paths->pathsOverlap($durable, $quarantine)
            || is_link($durable)
        ) {
            return [HomeworkPdfInventoryReport::STATUS_ROOT_UNSAFE, null];
        }
        if (!file_exists($durable)) {
            return [HomeworkPdfInventoryReport::STATUS_ROOT_MISSING, null];
        }
        if (!is_dir($durable)) {
            return [HomeworkPdfInventoryReport::STATUS_ROOT_UNSAFE, null];
        }
        $resolved = realpath($durable);
        if (!\is_string($resolved) || !is_readable($durable)) {
            return [HomeworkPdfInventoryReport::STATUS_ROOT_UNREADABLE, null];
        }

        return [HomeworkPdfInventoryReport::STATUS_OK, $resolved];
    }

    /**
     * @param array<string, int> $counters
     *
     * @return bool true when the directory holds no entry at all
     */
    private function scanDirectory(\DirectoryIterator $iterator, int $now, int $minAge, array &$counters): bool
    {
        $empty = true;
        /** @var array<string, array{0: int, 1: int|null}> $batch */
        $batch = [];
        foreach ($iterator as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $empty = false;
            $name = $entry->getFilename();
            $matches = [];
            $size = 0;
            $mtime = null;
            try {
                if ($entry->isLink()) {
                    $this->bump($counters, 'unsafe_entries');
                    $this->bump($counters, 'unsafe_symlink');
                    continue;
                }
                if ($entry->isDir()) {
                    $this->bump($counters, 'unsafe_entries');
                    $this->bump($counters, 'unsafe_directory');
                    continue;
                }
                if (!$entry->isFile()) {
                    $this->bump($counters, 'unsafe_entries');
                    $this->bump($counters, 'unsafe_special');
                    continue;
                }
                if (1 !== preg_match(self::NAME_PATTERN, $name, $matches)) {
                    $this->bump($counters, 'unsafe_entries');
                    $this->bump($counters, 'unsafe_invalid_name');
                    continue;
                }
                $measured = $entry->getSize();
                if (false === $measured) {
                    $this->bump($counters, 'unsafe_entries');
                    $this->bump($counters, 'unsafe_stat_failed');
                    continue;
                }
                $size = $measured;
                $modified = $entry->getMTime();
                $mtime = false === $modified ? null : $modified;
            } catch (\RuntimeException) {
                $this->bump($counters, 'unsafe_entries');
                $this->bump($counters, 'unsafe_stat_failed');
                continue;
            }

            if ('tmp' === $matches[2]) {
                // An interrupted durable write leaves a temp file. It is never referenced by a row.
                $this->classifyUnreferenced($size, $mtime, $now, $minAge, true, $counters);
                continue;
            }
            $batch[$matches[1]] = [$size, $mtime];
            if (\count($batch) >= self::BATCH_SIZE) {
                $this->resolveBatch($batch, $now, $minAge, $counters);
                $batch = [];
            }
        }
        if ([] !== $batch) {
            $this->resolveBatch($batch, $now, $minAge, $counters);
        }

        return $empty;
    }

    /**
     * @param array<string, array{0: int, 1: int|null}> $batch
     * @param array<string, int>                        $counters
     */
    private function resolveBatch(array $batch, int $now, int $minAge, array &$counters): void
    {
        $known = array_flip($this->source->existingStorageKeys(array_map('strval', array_keys($batch))));
        foreach ($batch as $key => [$size, $mtime]) {
            if (isset($known[(string) $key])) {
                $this->bump($counters, 'disk_files');
                $this->bump($counters, 'disk_bytes', $size);
                $this->bump($counters, 'matched_files');
                $this->bump($counters, 'matched_bytes', $size);
                continue;
            }
            $this->classifyUnreferenced($size, $mtime, $now, $minAge, false, $counters);
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function classifyUnreferenced(int $size, ?int $mtime, int $now, int $minAge, bool $temp, array &$counters): void
    {
        $this->bump($counters, 'disk_files');
        $this->bump($counters, 'disk_bytes', $size);
        if (null === $mtime || $mtime <= 0 || $mtime > $now) {
            // A missing, zero or future timestamp is not evidence of age: keep the file.
            $this->bump($counters, 'unreferenced_young_files');
            $this->bump($counters, 'unreferenced_young_bytes', $size);
            $this->bump($counters, 'timestamp_unreliable_files');

            return;
        }
        if (($now - $mtime) < $minAge) {
            $this->bump($counters, 'unreferenced_young_files');
            $this->bump($counters, 'unreferenced_young_bytes', $size);

            return;
        }
        $this->bump($counters, 'orphan_candidate_files');
        $this->bump($counters, 'orphan_candidate_bytes', $size);
        if ($temp) {
            $this->bump($counters, 'orphan_candidate_temp_files');
        }
    }

    /**
     * @param array<string, int>                                                              $counters
     * @param array<string, array{references: int, declared_bytes: int, physical_bytes: int}> $institutions
     * @param array{references: int, declared_bytes: int, physical_bytes: int}                $unknownOwner
     */
    private function scanReferences(?string $root, int $now, int $minAge, array &$counters, array &$institutions, array &$unknownOwner): void
    {
        $previousKey = null;
        foreach ($this->source->references() as $reference) {
            $this->bump($counters, 'db_references');
            $this->bump($counters, 'db_declared_bytes', $reference->byteSize);

            $duplicate = $previousKey === $reference->storageKey;
            $previousKey = $reference->storageKey;
            if ($duplicate) {
                $this->bump($counters, 'duplicate_reference');
            }

            // The same physical object is never counted twice, however many rows name it.
            $physical = $duplicate ? 0 : $this->measure($root, $reference, $counters);
            $this->bump($counters, 'db_physical_bytes', $physical);

            $prefix = match ($reference->lifecycle) {
                HomeworkPdfAttachmentLifecycle::Draft => 'draft',
                HomeworkPdfAttachmentLifecycle::Submitted => 'submitted',
                HomeworkPdfAttachmentLifecycle::Evaluated => 'evaluated',
                HomeworkPdfAttachmentLifecycle::Unknown => null,
            };
            if (null === $prefix) {
                $this->bump($counters, 'unknown_lifecycle_references');
            } else {
                $this->bump($counters, $prefix.'_references');
                $this->bump($counters, $prefix.'_declared_bytes', $reference->byteSize);
                $this->bump($counters, $prefix.'_physical_bytes', $physical);
            }
            if (HomeworkPdfAttachmentLifecycle::Draft === $reference->lifecycle
                && null !== $reference->createdAt
                && ($now - $reference->createdAt->getTimestamp()) >= $minAge
            ) {
                // Information only: a draft row never makes its file an orphan candidate.
                $this->bump($counters, 'draft_older_than_threshold');
            }

            if (null === $reference->institutionId) {
                $this->bump($counters, 'ambiguous_owner_references');
                ++$unknownOwner['references'];
                $unknownOwner['declared_bytes'] += $reference->byteSize;
                $unknownOwner['physical_bytes'] += $physical;
                continue;
            }
            $bucket = $institutions[$reference->institutionId] ?? ['references' => 0, 'declared_bytes' => 0, 'physical_bytes' => 0];
            ++$bucket['references'];
            $bucket['declared_bytes'] += $reference->byteSize;
            $bucket['physical_bytes'] += $physical;
            $institutions[$reference->institutionId] = $bucket;
        }
    }

    /**
     * Verified physical size of the object a row names, from metadata only. 0 when it cannot be verified.
     *
     * @param array<string, int> $counters
     */
    private function measure(?string $root, HomeworkPdfInventoryReference $reference, array &$counters): int
    {
        if (1 !== preg_match(self::KEY_PATTERN, $reference->storageKey)) {
            // A malformed key is never joined to a path.
            $this->bump($counters, 'db_invalid_key');

            return 0;
        }
        if (null === $root) {
            $this->bump($counters, 'db_missing_file');

            return 0;
        }
        $path = $root.\DIRECTORY_SEPARATOR.$reference->storageKey.'.bin';
        clearstatcache(true, $path);
        if (is_link($path)) {
            $this->bump($counters, 'db_unreadable_file');

            return 0;
        }
        if (!file_exists($path)) {
            $this->bump($counters, 'db_missing_file');

            return 0;
        }
        if (!is_file($path) || !is_readable($path)) {
            $this->bump($counters, 'db_unreadable_file');

            return 0;
        }
        $size = @filesize($path);
        if (!\is_int($size)) {
            $this->bump($counters, 'db_unreadable_file');

            return 0;
        }
        if ($size !== $reference->byteSize) {
            $this->bump($counters, 'db_size_mismatch');
        }

        return $size;
    }

    /**
     * @param array<string, int> $counters
     */
    private function bump(array &$counters, string $name, int $by = 1): void
    {
        $counters[$name] = ($counters[$name] ?? 0) + $by;
    }
}
