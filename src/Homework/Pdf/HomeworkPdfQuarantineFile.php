<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Private quarantine regular file with an exclusively locked open handle.
 * Scan and post-scan hash must use this handle, not a second path open.
 */
final class HomeworkPdfQuarantineFile
{
    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle
     */
    public function __construct(
        private readonly string $absolutePath,
        $handle,
        private readonly int $byteSize,
        private readonly string $sha256,
    ) {
        if (!\is_resource($handle) || $byteSize < 1 || 1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $this->handle = $handle;
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

    /**
     * @return resource
     */
    public function handle()
    {
        if (!\is_resource($this->handle)) {
            throw HomeworkPdfScanException::quarantine();
        }

        return $this->handle;
    }

    /**
     * Re-hash the bytes visible through the locked handle (not a path re-open).
     */
    public function hashFromLockedHandle(): string
    {
        $handle = $this->handle();
        if (false === rewind($handle)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $ctx = hash_init('sha256');
        $total = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if (false === $chunk) {
                throw HomeworkPdfScanException::quarantine();
            }
            if ('' === $chunk) {
                break;
            }
            $total += \strlen($chunk);
            hash_update($ctx, $chunk);
        }
        if ($total !== $this->byteSize) {
            throw HomeworkPdfScanException::hashMismatch();
        }
        $digest = hash_final($ctx);
        if ($digest !== $this->sha256) {
            throw HomeworkPdfScanException::hashMismatch();
        }

        return $digest;
    }

    /**
     * Closes the handle and unlinks the path. Returns false when unlink fails while the path still exists.
     */
    public function delete(): bool
    {
        $this->closeHandle();
        if (is_link($this->absolutePath)) {
            return false;
        }
        if (!is_file($this->absolutePath)) {
            return true;
        }

        return @unlink($this->absolutePath) || !file_exists($this->absolutePath);
    }

    public function deleteQuietly(): void
    {
        $this->delete();
    }

    private function closeHandle(): void
    {
        if (\is_resource($this->handle)) {
            flock($this->handle, \LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
