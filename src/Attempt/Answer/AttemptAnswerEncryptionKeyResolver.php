<?php

declare(strict_types=1);

namespace App\Attempt\Answer;

use App\Exception\AssessmentAttemptException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves versioned attempt-answer encryption keys.
 *
 * Keys are never logged. Production rejects placeholders and short values.
 */
final class AttemptAnswerEncryptionKeyResolver
{
    public const CURRENT_VERSION = 1;

    private readonly string $rawKeyMaterial;
    private readonly bool $enforceProductionPolicy;

    public function __construct(
        #[Autowire('%env(ATTEMPT_ANSWER_ENCRYPTION_KEY)%')]
        string $rawKeyMaterial,
        #[Autowire('%kernel.environment%')]
        string $environment,
    ) {
        $this->rawKeyMaterial = $rawKeyMaterial;
        $this->enforceProductionPolicy = 'prod' === $environment;
    }

    public function currentVersion(): int
    {
        return self::CURRENT_VERSION;
    }

    public function resolveKey(int $version): string
    {
        if (self::CURRENT_VERSION !== $version) {
            throw AssessmentAttemptException::encryptionMisconfigured();
        }

        $material = trim($this->rawKeyMaterial);
        if ('' === $material) {
            throw AssessmentAttemptException::encryptionMisconfigured();
        }

        if ($this->enforceProductionPolicy) {
            $lower = strtolower($material);
            foreach (['change-me', 'change_me', 'not-for-production', 'not_for_production', 'placeholder', 'test_'] as $needle) {
                if (str_contains($lower, $needle)) {
                    throw AssessmentAttemptException::encryptionMisconfigured();
                }
            }
        }

        $key = $this->decodeMaterial($material);
        if (\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($key)) {
            throw AssessmentAttemptException::encryptionMisconfigured();
        }

        return $key;
    }

    private function decodeMaterial(string $material): string
    {
        if (1 === preg_match('/^[0-9a-fA-F]+$/', $material) && 0 === \strlen($material) % 2) {
            $decoded = hex2bin($material);
            if (\is_string($decoded)) {
                return $decoded;
            }
        }

        $decoded = base64_decode($material, true);
        if (\is_string($decoded) && '' !== $decoded) {
            return $decoded;
        }

        return $material;
    }
}
