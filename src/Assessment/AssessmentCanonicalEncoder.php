<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Question\Content\QuestionContentCanonicalEncoder;

/**
 * Canonical JSON for assessment public hashes (delegates recursive ksort).
 */
final class AssessmentCanonicalEncoder
{
    public function __construct(
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public function encode(array $data): string
    {
        return $this->encoder->encode($data);
    }
}
