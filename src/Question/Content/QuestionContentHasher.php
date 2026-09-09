<?php

declare(strict_types=1);

namespace App\Question\Content;

/**
 * SHA-256 hasher over canonical question content.
 */
final class QuestionContentHasher
{
    public function __construct(
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', $this->encoder->encode($payload));
    }
}
