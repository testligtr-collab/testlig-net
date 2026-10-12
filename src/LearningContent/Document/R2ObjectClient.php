<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

/**
 * Private R2 object operations. No public URL and no presigned browser URL.
 */
interface R2ObjectClient
{
    public function putPrivate(string $key, string $bytes): void;

    /**
     * @return iterable<string>
     */
    public function readPrivate(string $key, int $maxBytes): iterable;

    public function delete(string $key): void;
}
