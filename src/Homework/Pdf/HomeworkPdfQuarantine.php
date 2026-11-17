<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use App\LearningContent\Document\PdfDocumentInspector;

/**
 * Writes upload bytes under a private quarantine root with random keys.
 *
 * Threat model (this layer only):
 * - Client-supplied names never become storage paths.
 * - Symlinks and non-regular files under the quarantine root are rejected.
 * - Staging never overwrites a pre-existing final path; rename fails if the target exists.
 * - Same-UID attackers that can already write anywhere the PHP process can are out of scope;
 *   exclusive flock + same-handle scan only binds the open inode for this process's scan window.
 */
final class HomeworkPdfQuarantine
{
    private readonly HomeworkPdfQuarantineKeySource $keys;
    private readonly HomeworkPdfQuarantineByteSink $sink;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes = PdfDocumentInspector::MAX_BYTES,
        ?HomeworkPdfQuarantineKeySource $keys = null,
        ?HomeworkPdfQuarantineByteSink $sink = null,
    ) {
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be at least 1.');
        }
        $this->keys = $keys ?? new RandomHomeworkPdfQuarantineKeySource();
        $this->sink = $sink ?? new NativeHomeworkPdfQuarantineByteSink();
    }

    public function stage(string $bytes): HomeworkPdfQuarantineFile
    {
        $size = \strlen($bytes);
        if ($size < 1 || $size > $this->maxBytes) {
            throw HomeworkPdfScanException::rejected(HomeworkPdfScanVerdict::SizeLimit);
        }
        $this->ensureRoot();
        $key = $this->keys->nextKey();
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $key)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $final = $this->join($key.'.bin');
        $temp = $this->join($key.'.tmp');
        if (file_exists($final) || file_exists($temp) || is_link($final) || is_link($temp)) {
            throw HomeworkPdfScanException::invalidPath();
        }
        try {
            $this->assertWritableTarget($temp);
            $this->sink->put($temp, $bytes);
            clearstatcache(true, $temp);
            if (!$this->isSafeRegularFile($temp) || filesize($temp) !== $size) {
                throw HomeworkPdfScanException::quarantine();
            }
            $this->assertWritableTarget($final);
            if (!rename($temp, $final)) {
                throw HomeworkPdfScanException::quarantine();
            }
            clearstatcache(true, $final);
            if (!$this->isSafeRegularFile($final) || filesize($final) !== $size) {
                @unlink($final);
                throw HomeworkPdfScanException::quarantine();
            }
            if (!chmod($final, 0600)) {
                @unlink($final);
                throw HomeworkPdfScanException::quarantine();
            }
            $handle = fopen($final, 'rb');
            if (!\is_resource($handle)) {
                @unlink($final);
                throw HomeworkPdfScanException::quarantine();
            }
            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                fclose($handle);
                @unlink($final);
                throw HomeworkPdfScanException::quarantine();
            }

            return new HomeworkPdfQuarantineFile($final, $handle, $size, hash('sha256', $bytes));
        } catch (HomeworkPdfScanException $exception) {
            $this->cleanup($temp, $final);
            throw $exception;
        } catch (\Throwable) {
            $this->cleanup($temp, $final);
            throw HomeworkPdfScanException::quarantine();
        }
    }

    private function ensureRoot(): void
    {
        if (is_dir($this->directory)) {
            if (is_link($this->directory) || !$this->isInsideResolvedRoot($this->directory)) {
                throw HomeworkPdfScanException::invalidPath();
            }

            return;
        }
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw HomeworkPdfScanException::quarantine();
        }
        if (is_link($this->directory)) {
            throw HomeworkPdfScanException::invalidPath();
        }
        @chmod($this->directory, 0700);
    }

    private function join(string $name): string
    {
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw HomeworkPdfScanException::invalidPath();
        }
        $path = $this->directory.\DIRECTORY_SEPARATOR.$name;
        if (!$this->isInsideResolvedRoot($path)) {
            throw HomeworkPdfScanException::invalidPath();
        }

        return $path;
    }

    private function isInsideResolvedRoot(string $path): bool
    {
        $root = realpath($this->directory);
        if (false === $root) {
            return false;
        }
        $candidate = realpath($path);
        if (false === $candidate) {
            $parent = realpath(\dirname($path));
            if (false === $parent) {
                return false;
            }
            $candidate = $parent.\DIRECTORY_SEPARATOR.basename($path);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $candidate = str_replace('\\', '/', $candidate);

        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function assertWritableTarget(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            throw HomeworkPdfScanException::invalidPath();
        }
        if (!$this->isInsideResolvedRoot($path)) {
            throw HomeworkPdfScanException::invalidPath();
        }
    }

    private function isSafeRegularFile(string $path): bool
    {
        return is_file($path) && !is_link($path) && $this->isInsideResolvedRoot($path);
    }

    private function cleanup(string ...$paths): void
    {
        foreach ($paths as $path) {
            if ('' === $path || is_link($path)) {
                continue;
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
