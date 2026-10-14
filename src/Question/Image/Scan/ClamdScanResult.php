<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * A clamd reply is usable only when the streamed bytes matched the expected asset.
 */
final class ClamdScanResult
{
    public function __construct(
        public readonly QuestionImageScanVerdict $verdict,
        public readonly bool $streamMatched,
    ) {
    }
}
