<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

interface QuestionImageScanCopy
{
    public function path(): string;

    public function byteSize(): int;

    public function sha256(): string;

    public function delete(): void;
}
