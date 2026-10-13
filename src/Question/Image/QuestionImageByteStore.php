<?php

declare(strict_types=1);

namespace App\Question\Image;

interface QuestionImageByteStore
{
    public function write(string $storageKey, string $bytes, string $mimeType, string $sha256): void;

    public function deleteQuietly(string $storageKey): void;
}
