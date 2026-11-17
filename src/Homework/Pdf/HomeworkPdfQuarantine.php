<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use App\LearningContent\Document\PdfDocumentInspector;

/**
 * Stages PDF bytes under a private directory outside the web root contract.
 * Storage keys are random; client names are display metadata only.
 */
final class HomeworkPdfQuarantine
{
    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes = PdfDocumentInspector::MAX_BYTES,
    ) {
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be at least 1.');
        }
    }

    public function stage(string $bytes): HomeworkPdfQuarantineFile
    {
        $size = \strlen($bytes);
        if ($size < 1 || $size > $this->maxBytes) {
            throw HomeworkPdfScanException::rejected(HomeworkPdfScanVerdict::SizeLimit);
        }
        $this->ensureDirectory();
        $key = bin2hex(random_bytes(16));
        $path = $this->directory.\DIRECTORY_SEPARATOR.$key.'.bin';
        $this->assertWritableTarget($path);
        $expected = hash('sha256', $bytes);
        $temp = $path.'.tmp';
        $written = false;
        try {
            if (false === file_put_contents($temp, $bytes, \LOCK_EX)) {
                throw HomeworkPdfScanException::quarantine();
            }
            $written = true;
            if (!$this->isSafeRegularFile($temp)) {
                throw HomeworkPdfScanException::invalidPath();
            }
            $stored = hash_file('sha256', $temp);
            $actualSize = filesize($temp);
            if (!\is_string($stored) || !\is_int($actualSize) || $actualSize !== $size || !hash_equals($expected, $stored)) {
                throw HomeworkPdfScanException::rejected(HomeworkPdfScanVerdict::HashMismatch);
            }
            if (!rename($temp, $path)) {
                throw HomeworkPdfScanException::quarantine();
            }
            $written = false;
            if (!$this->isSafeRegularFile($path)) {
                @unlink($path);
                throw HomeworkPdfScanException::invalidPath();
            }
            @chmod($path, 0600);
            $rehash = hash_file('sha256', $path);
            $resize = filesize($path);
            if (!\is_string($rehash) || !\is_int($resize) || $resize !== $size || !hash_equals($expected, $rehash)) {
                @unlink($path);
                throw HomeworkPdfScanException::rejected(HomeworkPdfScanVerdict::HashMismatch);
            }

            return new HomeworkPdfQuarantineFile($path, $size, $expected);
        } catch (\Throwable $exception) {
            if ($written && is_file($temp)) {
                @unlink($temp);
            }
            if (is_file($path)) {
                @unlink($path);
            }
            if ($exception instanceof HomeworkPdfScanException) {
                throw $exception;
            }
            throw HomeworkPdfScanException::quarantine();
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw HomeworkPdfScanException::quarantine();
        }
        @chmod($this->directory, 0700);
    }

    private function assertWritableTarget(string $path): void
    {
        $realDir = realpath($this->directory);
        if (!\is_string($realDir) || !is_dir($realDir)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $base = $realDir.\DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $base) || str_contains($path, "\0")) {
            throw HomeworkPdfScanException::invalidPath();
        }
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw HomeworkPdfScanException::invalidPath();
        }
    }

    private function isSafeRegularFile(string $path): bool
    {
        if (is_link($path) || !is_file($path)) {
            return false;
        }
        $real = realpath($path);
        $realDir = realpath($this->directory);
        if (!\is_string($real) || !\is_string($realDir)) {
            return false;
        }

        return str_starts_with($real, $realDir.\DIRECTORY_SEPARATOR);
    }
}
