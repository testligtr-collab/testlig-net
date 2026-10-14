<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * The bytes read for a scan did not match the verified asset. The message is a stable code.
 */
final class QuestionImageStreamException extends \RuntimeException
{
    private function __construct(string $code)
    {
        parent::__construct($code);
    }

    public static function read(): self
    {
        return new self('question_image_read');
    }

    public static function emptyRead(): self
    {
        return new self('question_image_empty_read');
    }

    public static function incomplete(): self
    {
        return new self('question_image_incomplete');
    }

    public static function size(): self
    {
        return new self('question_image_stream_size');
    }

    public static function mismatch(): self
    {
        return new self('question_image_stream_mismatch');
    }
}
