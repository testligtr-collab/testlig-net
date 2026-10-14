<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Exception\QuestionException;

final class QuestionImagePrivateCopy implements QuestionImageScanCopy
{
    public function __construct(
        private readonly string $path,
        private readonly string $root,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function byteSize(): int
    {
        $size = filesize($this->path);
        if (!\is_int($size) || $size < 1) {
            throw QuestionException::imageRejected('question_image_store');
        }

        return $size;
    }

    public function sha256(): string
    {
        $hash = hash_file('sha256', $this->path);
        if (!\is_string($hash) || 1 !== preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw QuestionException::imageRejected('question_image_store');
        }

        return $hash;
    }

    public function delete(): void
    {
        $resolved = realpath($this->path);
        $prefix = $this->root.\DIRECTORY_SEPARATOR;
        if (\is_string($resolved) && !str_starts_with($resolved, $prefix)) {
            throw QuestionException::imageRejected('question_image_cleanup');
        }
        if (is_file($this->path) && !unlink($this->path)) {
            throw QuestionException::imageRejected('question_image_cleanup');
        }
        if (is_file($this->path)) {
            throw QuestionException::imageRejected('question_image_cleanup');
        }
    }
}
