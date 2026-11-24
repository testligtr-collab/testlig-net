<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Purges stale, unlocked orphaned files (.tmp / .bin) from the homework PDF quarantine directory.
 * Never deletes symlinks, active locked scan candidates, or durable student PDFs.
 */
final class HomeworkPdfQuarantineCleaner
{
    public const DEFAULT_MAX_AGE_SECONDS = 3600;
    public const MIN_MAX_AGE_SECONDS = 60;

    public function __construct(
        private readonly HomeworkPdfStoragePaths $paths,
    ) {
    }

    public function purgeStale(
        int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
        ?int $now = null,
        bool $dryRun = false,
    ): int {
        if ($maxAgeSeconds < self::MIN_MAX_AGE_SECONDS) {
            throw new \InvalidArgumentException('maxAgeSeconds must be at least 60.');
        }

        $directory = $this->paths->quarantineDirectory();
        if (!file_exists($directory) && !is_link($directory)) {
            return 0;
        }
        if (is_link($directory) || !is_dir($directory)) {
            throw HomeworkPdfScanException::invalidPath();
        }
        $resolvedRoot = realpath($directory);
        if (!\is_string($resolvedRoot)) {
            throw HomeworkPdfScanException::invalidPath();
        }
        $resolvedRootNormalized = rtrim(str_replace('\\', '/', $resolvedRoot), '/');

        $currentTime = $now ?? time();
        $purged = 0;
        $iterator = new \DirectoryIterator($resolvedRoot);
        foreach ($iterator as $entry) {
            if ($entry->isDot() || $entry->isLink() || !$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            if (1 !== preg_match('/^[0-9a-f]{32}\.(?:tmp|bin)$/', $name)) {
                continue;
            }
            $path = $resolvedRoot.\DIRECTORY_SEPARATOR.$name;
            $resolvedFile = realpath($path);
            if (!\is_string($resolvedFile) || is_link($path)) {
                continue;
            }
            $resolvedFileNormalized = str_replace('\\', '/', $resolvedFile);
            if (!str_starts_with($resolvedFileNormalized, $resolvedRootNormalized.'/')) {
                continue;
            }
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            if (false === $mtime || ($currentTime - $mtime) < $maxAgeSeconds) {
                continue;
            }
            $handle = @fopen($path, 'r');
            if (!\is_resource($handle)) {
                continue;
            }
            $locked = flock($handle, \LOCK_EX | \LOCK_NB);
            if (!$locked) {
                fclose($handle);
                continue;
            }
            flock($handle, \LOCK_UN);
            fclose($handle);

            if ($dryRun) {
                ++$purged;
                continue;
            }
            if (@unlink($path) || !file_exists($path)) {
                ++$purged;
            }
        }

        return $purged;
    }
}
