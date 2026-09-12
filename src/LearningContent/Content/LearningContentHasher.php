<?php

declare(strict_types=1);

namespace App\LearningContent\Content;

/**
 * SHA-256 hasher over canonical learning content payloads.
 */
final class LearningContentHasher
{
    public function __construct(
        private readonly LearningContentCanonicalEncoder $encoder,
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
