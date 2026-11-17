<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Private quarantine regular file. Client filenames are never used as paths.
 */
final class HomeworkPdfQuarantineFile
{
    public function __construct(
        private readonly string $absolutePath,
        private readonly int $byteSize,
        private readonly string $sha256,
    ) {
        if ($byteSize < 1 || 1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw HomeworkPdfScanException::quarantine();
        }
    }

    public function absolutePath(): string
    {
        return $this->absolutePath;
    }

    public function byteSize(): int
    {
        return $this->byteSize;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function deleteQuietly(): void
    {
        if (is_file($this->absolutePath) && !is_link($this->absolutePath)) {
            @unlink($this->absolutePath);
        }
    }
}
