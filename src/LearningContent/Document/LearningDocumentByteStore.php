<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

/**
 * Local PDF byte contract: write, checksummed read, and failed-record cleanup.
 *
 * absolutePath is a private local file used by Range responses. For R2 that
 * file is the verified temporary copy. It is not a public URL.
 */
interface LearningDocumentByteStore
{
    public function write(string $bytes): string;

    public function read(string $storageKey, string $expectedSha256): string;

    public function absolutePath(string $storageKey): string;

    public function deleteQuietly(string $storageKey): void;
}
