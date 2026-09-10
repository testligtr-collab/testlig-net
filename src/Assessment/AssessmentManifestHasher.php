<?php

declare(strict_types=1);

namespace App\Assessment;

/**
 * SHA-256 over canonical assessment public payloads.
 */
final class AssessmentManifestHasher
{
    public function __construct(
        private readonly AssessmentCanonicalEncoder $encoder,
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
