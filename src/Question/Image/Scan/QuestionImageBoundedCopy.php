<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Exception\QuestionException;
use App\Question\Image\CompleteQuestionImageByteWriter;
use App\Question\Image\QuestionImageInspector;

final class QuestionImageBoundedCopy
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @param callable(callable(string): void): void $producer
     */
    public function write(int $maxBytes, callable $producer): QuestionImagePrivateCopy
    {
        if ($maxBytes < 1 || $maxBytes > QuestionImageInspector::MAX_BYTES) {
            throw QuestionException::imageRejected('question_image_limit');
        }
        $root = $this->root();
        $path = $root.\DIRECTORY_SEPARATOR.bin2hex(random_bytes(16)).'.scan';
        $handle = fopen($path, 'wb+');
        if (!\is_resource($handle)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $written = 0;
        $writer = new CompleteQuestionImageByteWriter();
        try {
            $producer(static function (string $chunk) use (&$written, $maxBytes, $handle, $writer): void {
                if ('' === $chunk) {
                    return;
                }
                if ($written > $maxBytes - \strlen($chunk)) {
                    throw QuestionException::imageRejected('question_image_limit');
                }
                $writer->writeAll($handle, $chunk);
                $written += \strlen($chunk);
            });
            if (!fflush($handle)) {
                throw QuestionException::imageRejected('question_image_store');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            if (is_file($path)) {
                unlink($path);
            }
            if (is_file($path)) {
                throw QuestionException::imageRejected('question_image_cleanup');
            }
            throw $error;
        }
        fclose($handle);
        if ($written < 1) {
            if (is_file($path)) {
                unlink($path);
            }
            throw QuestionException::imageRejected('question_image_store');
        }
        @chmod($path, 0600);

        return new QuestionImagePrivateCopy($path, $root);
    }

    private function root(): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $base = realpath($this->directory);
        if (!\is_string($base)) {
            throw QuestionException::imageRejected('question_image_store');
        }

        return $base;
    }
}
