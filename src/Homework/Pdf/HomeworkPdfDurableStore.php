<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Private durable homework PDF objects under a non-web root.
 * Keys are 32-hex; client names never become paths. Re-verifies size+SHA on commit.
 */
class HomeworkPdfDurableStore
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * Copy locked quarantine bytes into durable storage. Caller deletes quarantine after success.
     */
    public function commitFromQuarantine(HomeworkPdfQuarantineFile $file): string
    {
        $sha = $file->hashFromLockedHandle();
        $size = $file->byteSize();
        $key = bin2hex(random_bytes(16));
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $key)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $this->ensureRoot();
        $final = $this->join($key.'.bin');
        $temp = $this->join($key.'.tmp');
        if (file_exists($final) || file_exists($temp) || is_link($final) || is_link($temp)) {
            throw HomeworkPdfScanException::invalidPath();
        }
        $handle = $file->handle();
        if (false === rewind($handle)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $out = fopen($temp, 'w');
        if (!\is_resource($out)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $written = 0;
        $ctx = hash_init('sha256');
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if (false === $chunk) {
                    throw HomeworkPdfScanException::quarantine();
                }
                if ('' === $chunk) {
                    break;
                }
                $n = fwrite($out, $chunk);
                if (false === $n || $n !== \strlen($chunk)) {
                    throw HomeworkPdfScanException::quarantine();
                }
                $written += $n;
                hash_update($ctx, $chunk);
            }
            if (!fflush($out)) {
                throw HomeworkPdfScanException::quarantine();
            }
        } catch (HomeworkPdfScanException $exception) {
            fclose($out);
            @unlink($temp);
            throw $exception;
        } catch (\Throwable) {
            fclose($out);
            @unlink($temp);
            throw HomeworkPdfScanException::quarantine();
        }
        fclose($out);
        $digest = hash_final($ctx);
        if ($written !== $size || $digest !== $sha || $digest !== $file->sha256()) {
            @unlink($temp);
            throw HomeworkPdfScanException::hashMismatch();
        }
        clearstatcache(true, $temp);
        if (!$this->isSafeRegularFile($temp) || filesize($temp) !== $size) {
            @unlink($temp);
            throw HomeworkPdfScanException::quarantine();
        }
        if (!chmod($temp, 0600)) {
            @unlink($temp);
            throw HomeworkPdfScanException::quarantine();
        }
        if (!rename($temp, $final)) {
            @unlink($temp);
            throw HomeworkPdfScanException::quarantine();
        }
        clearstatcache(true, $final);
        if (!$this->isSafeRegularFile($final) || filesize($final) !== $size) {
            @unlink($final);
            throw HomeworkPdfScanException::quarantine();
        }
        if (!hash_equals($sha, hash_file('sha256', $final) ?: '')) {
            @unlink($final);
            throw HomeworkPdfScanException::hashMismatch();
        }

        return $key;
    }

    public function deleteQuietly(string $storageKey): void
    {
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $storageKey)) {
            return;
        }
        $path = $this->join($storageKey.'.bin');
        if (is_link($path)) {
            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function absolutePath(string $storageKey): string
    {
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $storageKey)) {
            throw HomeworkPdfScanException::invalidPath();
        }

        return $this->join($storageKey.'.bin');
    }

    private function ensureRoot(): void
    {
        $root = $this->directory;
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $resolved = realpath($root);
        if (!\is_string($resolved) || is_link($root)) {
            throw HomeworkPdfScanException::invalidPath();
        }
    }

    private function join(string $name): string
    {
        $this->ensureRoot();
        $base = realpath($this->directory);
        if (!\is_string($base)) {
            throw HomeworkPdfScanException::quarantine();
        }
        $path = $base.\DIRECTORY_SEPARATOR.$name;
        $parent = \dirname($path);
        $resolvedParent = realpath($parent);
        if (!\is_string($resolvedParent) || !str_starts_with($resolvedParent, $base)) {
            throw HomeworkPdfScanException::invalidPath();
        }

        return $path;
    }

    private function isSafeRegularFile(string $path): bool
    {
        return is_file($path) && !is_link($path);
    }
}
