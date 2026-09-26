<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;

/**
 * Stores PDF bytes outside the public web root under a random key.
 */
final class LearningDocumentStorage
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function write(string $bytes): string
    {
        $key = bin2hex(random_bytes(16));
        $path = $this->path($key);
        $dir = $this->directory;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }
        $expected = hash('sha256', $bytes);
        $temp = $path.'.tmp';
        if (false === file_put_contents($temp, $bytes, \LOCK_EX)) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }
        $stored = hash_file('sha256', $temp);
        if ($stored !== $expected) {
            $this->deleteKey($key.'.tmp');
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }
        if (!rename($temp, $path)) {
            $this->deleteKey($key.'.tmp');
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }

        return $key;
    }

    public function read(string $storageKey, string $expectedSha256): string
    {
        $path = $this->path($storageKey);
        if (!is_file($path)) {
            throw LearningContentException::notFound();
        }
        $bytes = file_get_contents($path);
        if (!\is_string($bytes) || hash('sha256', $bytes) !== $expectedSha256) {
            throw LearningContentException::notFound();
        }

        return $bytes;
    }

    public function absolutePath(string $storageKey): string
    {
        return $this->path($storageKey);
    }

    public function deleteQuietly(string $storageKey): void
    {
        $this->deleteKey($storageKey);
    }

    private function path(string $storageKey): string
    {
        if (1 !== preg_match('/^[a-f0-9]{32}(\.tmp)?$/', $storageKey)) {
            throw LearningContentException::notFound();
        }

        return $this->directory.\DIRECTORY_SEPARATOR.$storageKey;
    }

    private function deleteKey(string $storageKey): void
    {
        $path = $this->directory.\DIRECTORY_SEPARATOR.$storageKey;
        if (is_file($path)) {
            unlink($path);
        }
    }
}
