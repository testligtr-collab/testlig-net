<?php

declare(strict_types=1);

namespace App\Attempt\Answer;

use App\Exception\AssessmentAttemptException;

/**
 * XChaCha20-Poly1305 encrypt/decrypt for student attempt answers.
 */
final class AttemptAnswerEncryptor
{
    public function __construct(
        private readonly AttemptAnswerCanonicalizer $canonicalizer,
        private readonly AttemptAnswerEncryptionKeyResolver $keyResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{ciphertext: string, nonce: string, encryptionVersion: int}
     */
    public function encrypt(
        array $payload,
        string $attemptId,
        string $attemptItemId,
        string $userId,
    ): array {
        $version = $this->keyResolver->currentVersion();
        $key = $this->keyResolver->resolveKey($version);
        $plaintext = $this->canonicalizer->encode($payload);
        $aad = $this->canonicalizer->buildAssociatedData($attemptId, $attemptItemId, $userId, $version);
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
        } catch (\Throwable) {
            throw AssessmentAttemptException::encryptionMisconfigured();
        } finally {
            sodium_memzero($key);
        }

        return [
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'encryptionVersion' => $version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decrypt(
        string $ciphertext,
        string $nonce,
        int $encryptionVersion,
        string $attemptId,
        string $attemptItemId,
        string $userId,
    ): array {
        if (\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== \strlen($nonce)) {
            throw AssessmentAttemptException::answerIntegrityFailed();
        }

        $key = $this->keyResolver->resolveKey($encryptionVersion);
        $aad = $this->canonicalizer->buildAssociatedData($attemptId, $attemptItemId, $userId, $encryptionVersion);

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
        } catch (\Throwable) {
            throw AssessmentAttemptException::answerIntegrityFailed();
        } finally {
            sodium_memzero($key);
        }

        if (false === $plaintext) {
            throw AssessmentAttemptException::answerIntegrityFailed();
        }

        return $this->canonicalizer->decode($plaintext);
    }
}
