<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

/**
 * Local PDF byte contract: write, checksummed read, and failed-record cleanup.
 *
 * absolutePath is the private local file used by Range responses. It is not a
 * public URL and not a provider switch.
 */
interface LearningDocumentByteStore
{
    public function write(string $bytes): string;

    public function read(string $storageKey, string $expectedSha256): string;

    public function absolutePath(string $storageKey): string;

    public function deleteQuietly(string $storageKey): void;
}
