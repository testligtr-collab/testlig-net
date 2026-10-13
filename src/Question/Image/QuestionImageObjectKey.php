<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Exception\QuestionException;

/**
 * App-generated question image object keys. Not the PDF 32-hex contract.
 */
final class QuestionImageObjectKey
{
    public const PATTERN = '#^media/(platform|institution/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/image/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/[0-9a-f]{16}$#';

    public static function assert(string $storageKey): void
    {
        if (1 !== preg_match(self::PATTERN, $storageKey)) {
            throw QuestionException::imageRejected('question_image_store');
        }
    }
}
