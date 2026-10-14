<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Parses one complete clamd INSTREAM reply. Anything other than the exact clean line is not clean.
 */
final class ClamdInstreamReply
{
    public static function parse(string $response): QuestionImageScanVerdict
    {
        if ("stream: OK\n" === $response) {
            return QuestionImageScanVerdict::Clean;
        }
        if (1 === preg_match('/^stream: [^\n]+ FOUND\n$/', $response)) {
            return QuestionImageScanVerdict::Infected;
        }
        if (self::isSingleLine($response) && str_contains($response, 'size limit exceeded')) {
            return QuestionImageScanVerdict::SizeLimit;
        }
        if (self::isSingleLine($response) && str_contains($response, 'ERROR')) {
            return QuestionImageScanVerdict::Error;
        }

        return QuestionImageScanVerdict::Malformed;
    }

    private static function isSingleLine(string $response): bool
    {
        return str_ends_with($response, "\n") && 1 === substr_count($response, "\n");
    }
}
