<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Exception\QuestionException;
use Psr\Log\LoggerInterface;

final class LocalQuestionImageStore implements QuestionImageByteStore
{
    public function __construct(
        private readonly string $directory,
        private readonly LoggerInterface $logger,
        private readonly QuestionImageByteWriter $writer = new CompleteQuestionImageByteWriter(),
    ) {
    }

    public function write(string $storageKey, string $bytes, string $mimeType, string $sha256): void
    {
        QuestionImageObjectKey::assert($storageKey);
        if (!\in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw QuestionException::imageRejected('question_image_type');
        }
        if (!hash_equals(hash('sha256', $bytes), $sha256)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $path = $this->path($storageKey);
        $parent = \dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $temporary = $parent.\DIRECTORY_SEPARATOR.bin2hex(random_bytes(8)).'.part';
        $handle = fopen($temporary, 'wb+');
        if (!\is_resource($handle)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        try {
            $this->writer->writeAll($handle, $bytes);
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($temporary);
            throw $error;
        }
        fclose($handle);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw QuestionException::imageRejected('question_image_store');
        }
        @chmod($path, 0600);
    }

    public function deleteQuietly(string $storageKey): void
    {
        try {
            QuestionImageObjectKey::assert($storageKey);
            $path = $this->path($storageKey);
            if (is_file($path)) {
                unlink($path);
            }
        } catch (\Throwable) {
            $this->logger->error('question_image.cleanup_failed');
        }
    }

    private function path(string $storageKey): string
    {
        $root = $this->directory;
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $base = realpath($root);
        if (!\is_string($base)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $path = $base.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $storageKey);
        $parent = \dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $resolvedParent = realpath($parent);
        if (!\is_string($resolvedParent) || !str_starts_with($resolvedParent, $base)) {
            throw QuestionException::imageRejected('question_image_store');
        }

        return $path;
    }
}
