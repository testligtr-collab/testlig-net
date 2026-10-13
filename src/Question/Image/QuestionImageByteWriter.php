<?php

declare(strict_types=1);

namespace App\Question\Image;

interface QuestionImageByteWriter
{
    public function writeAll(mixed $handle, string $bytes): void;
}
