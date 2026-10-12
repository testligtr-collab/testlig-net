<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;

/**
 * Downloads a private R2 object once into a verified temporary file.
 *
 * Kernel terminate removes copies from a request that finishes normally.
 * A killed process can leave a file. A later sweep removes unlocked files
 * older than the age limit and skips locked or younger files.
 */
final class R2LearningDocumentStorage implements LearningDocumentByteStore
{
    public const TEMP_MAX_AGE_SECONDS = 3600;

    /** @var array<string, string> */
    private array $verifiedPaths = [];

    /** @var array<string, resource> */
    private array $locks = [];

    public function __construct(
        private readonly R2ObjectClient $client,
        private readonly string $tempDirectory,
        private readonly int $maxBytes = PdfDocumentInspector::MAX_BYTES,
    ) {
    }

    public function write(string $bytes): string
    {
        if (\strlen($bytes) > $this->maxBytes) {
            throw LearningContentException::assetInvalid(PdfDocumentInspector::MESSAGE_SIZE);
        }
        $key = bin2hex(random_bytes(16));
        try {
            $this->client->putPrivate($key, $bytes);
        } catch (LearningContentException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }

        return $key;
    }

    public function read(string $storageKey, string $expectedSha256): string
    {
        $path = $this->verifiedPath($storageKey, $expectedSha256);
        $bytes = file_get_contents($path);
        if (!\is_string($bytes) || hash('sha256', $bytes) !== $expectedSha256) {
            $this->discard($storageKey);
            throw LearningContentException::notFound();
        }

        return $bytes;
    }

    public function absolutePath(string $storageKey): string
    {
        $path = $this->verifiedPaths[$storageKey] ?? null;
        if (!\is_string($path) || !is_file($path)) {
            throw LearningContentException::notFound();
        }

        return $path;
    }

    public function deleteQuietly(string $storageKey): void
    {
        try {
            $this->assertKey($storageKey);
            $this->client->delete($storageKey);
        } catch (\Throwable) {
        }
        $this->discard($storageKey);
    }

    public function releaseVerifiedCopies(): void
    {
        foreach (array_keys($this->verifiedPaths) as $key) {
            $this->discard($key);
        }
        $this->sweepExpired();
    }

    public function sweepExpired(): void
    {
        if (!is_dir($this->tempDirectory)) {
            return;
        }
        $cutoff = time() - self::TEMP_MAX_AGE_SECONDS;
        $names = scandir($this->tempDirectory);
        if (!\is_array($names)) {
            return;
        }
        foreach ($names as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $this->tempDirectory.\DIRECTORY_SEPARATOR.$name;
            if (!is_file($path)) {
                continue;
            }
            $modified = filemtime($path);
            if (false === $modified || $modified > $cutoff) {
                continue;
            }
            $handle = fopen($path, 'r');
            if (false === $handle) {
                continue;
            }
            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                fclose($handle);
                continue;
            }
            flock($handle, \LOCK_UN);
            fclose($handle);
            unlink($path);
        }
    }

    private function verifiedPath(string $storageKey, string $expectedSha256): string
    {
        $this->assertKey($storageKey);
        $this->sweepExpired();
        $cached = $this->verifiedPaths[$storageKey] ?? null;
        if (\is_string($cached) && is_file($cached)) {
            return $cached;
        }

        $this->ensureDirectory();
        $path = $this->tempDirectory.\DIRECTORY_SEPARATOR.bin2hex(random_bytes(16));
        $handle = fopen($path, 'wb+');
        if (false === $handle) {
            throw LearningContentException::notFound();
        }
        chmod($path, 0600);
        if (!flock($handle, \LOCK_EX)) {
            fclose($handle);
            $this->unlinkIfPresent($path);
            throw LearningContentException::notFound();
        }

        $context = hash_init('sha256');
        $written = 0;
        try {
            foreach ($this->client->readPrivate($storageKey, $this->maxBytes) as $chunk) {
                $written += \strlen($chunk);
                if ($written > $this->maxBytes) {
                    throw LearningContentException::notFound();
                }
                hash_update($context, $chunk);
                if (false === fwrite($handle, $chunk)) {
                    throw LearningContentException::notFound();
                }
            }
            if (hash_final($context) !== $expectedSha256) {
                throw LearningContentException::notFound();
            }
            fflush($handle);
            if (!flock($handle, \LOCK_SH)) {
                throw LearningContentException::notFound();
            }
        } catch (\Throwable $exception) {
            flock($handle, \LOCK_UN);
            fclose($handle);
            $this->unlinkIfPresent($path);
            if ($exception instanceof LearningContentException) {
                throw $exception;
            }
            throw LearningContentException::notFound();
        }

        $this->locks[$path] = $handle;
        $this->verifiedPaths[$storageKey] = $path;

        return $path;
    }

    private function discard(string $storageKey): void
    {
        $path = $this->verifiedPaths[$storageKey] ?? null;
        unset($this->verifiedPaths[$storageKey]);
        if (!\is_string($path)) {
            return;
        }
        $handle = $this->locks[$path] ?? null;
        unset($this->locks[$path]);
        if (\is_resource($handle)) {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
        $this->unlinkIfPresent($path);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->tempDirectory) && !mkdir($this->tempDirectory, 0700, true) && !is_dir($this->tempDirectory)) {
            throw LearningContentException::notFound();
        }
        chmod($this->tempDirectory, 0700);
    }

    private function assertKey(string $storageKey): void
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $storageKey)) {
            throw LearningContentException::notFound();
        }
    }

    private function unlinkIfPresent(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
