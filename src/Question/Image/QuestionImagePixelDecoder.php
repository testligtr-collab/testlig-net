<?php

declare(strict_types=1);

namespace App\Question\Image;

interface QuestionImagePixelDecoder
{
    public function assertAvailable(): void;

    /**
     * @return array{width: int, height: int}
     */
    public function decode(string $bytes): array;
}
