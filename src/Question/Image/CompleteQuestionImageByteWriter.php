<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Exception\QuestionException;

final class CompleteQuestionImageByteWriter implements QuestionImageByteWriter
{
    public function writeAll(mixed $handle, string $bytes): void
    {
        if (!\is_resource($handle)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $expected = \strlen($bytes);
        $writtenTotal = 0;
        while ($writtenTotal < $expected) {
            $written = fwrite($handle, substr($bytes, $writtenTotal));
            if (!\is_int($written) || $written < 1) {
                throw QuestionException::imageRejected('question_image_store');
            }
            $writtenTotal += $written;
        }
        if (!fflush($handle)) {
            throw QuestionException::imageRejected('question_image_store');
        }
    }
}
