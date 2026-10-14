<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Question\Image\Scan\QuestionImageScanCopy;

interface QuestionImageByteStore
{
    public function write(string $storageKey, string $bytes, string $mimeType, string $sha256): void;

    public function read(string $storageKey, int $maxBytes): QuestionImageScanCopy;

    public function deleteQuietly(string $storageKey): void;
}
