<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Exception\CommerceException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * HMAC-SHA256 hasher for commerce idempotency keys.
 *
 * Raw idempotency keys are never persisted, logged, or audited — only the 64 hex
 * lowercase digest. Key material comes exclusively from
 * COMMERCE_IDEMPOTENCY_HASH_KEY: there is **no APP_SECRET / AUDIT_HASH_KEY
 * fallback**, and production rejects placeholder or short values.
 */
final class CommerceIdempotencyKeyHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const MIN_KEY_BYTES = 32;

    public const MIN_IDEMPOTENCY_KEY_LENGTH = 8;

    public const MAX_IDEMPOTENCY_KEY_LENGTH = 128;

    /**
     * @var list<string>
     */
    private const PRODUCTION_FORBIDDEN_NEEDLES = [
        'change-me',
        'change_me',
        'changeme',
        'not-for-production',
        'not_for_production',
        'placeholder',
        'example',
        'dummy',
        'test_',
        'test-',
        'ci_',
    ];

    private readonly bool $enforceProductionPolicy;

    public function __construct(
        #[Autowire('%env(COMMERCE_IDEMPOTENCY_HASH_KEY)%')]
        private readonly string $rawKeyMaterial,
        #[Autowire('%kernel.environment%')]
        string $environment,
    ) {
        $this->enforceProductionPolicy = 'prod' === $environment;
    }

    /**
     * @param string $scope snake_case domain scope such as payment_attempt / fulfillment
     */
    public function hash(string $scope, string $idempotencyKey): string
    {
        return hash_hmac(
            'sha256',
            $this->normalizeScope($scope).':'.self::normalizeIdempotencyKey($idempotencyKey),
            $this->keyMaterial(),
        );
    }

    /**
     * Derives a per-order-item digest so a single caller-supplied key can fan out to
     * one deterministic fulfillment row per item without ever reusing a digest.
     */
    public function hashScoped(string $scope, string $idempotencyKey, string $discriminator): string
    {
        return hash_hmac(
            'sha256',
            $this->normalizeScope($scope).':'.self::normalizeIdempotencyKey($idempotencyKey)
                .':'.$this->normalizeDiscriminator($discriminator),
            $this->keyMaterial(),
        );
    }

    public function verify(string $storedHash, string $scope, string $idempotencyKey): void
    {
        self::assertHash($storedHash);
        if (!hash_equals($this->hash($scope, $idempotencyKey), $storedHash)) {
            throw CommerceException::idempotencyConflict();
        }
    }

    public function verifyScoped(
        string $storedHash,
        string $scope,
        string $idempotencyKey,
        string $discriminator,
    ): void {
        self::assertHash($storedHash);
        if (!hash_equals($this->hashScoped($scope, $idempotencyKey, $discriminator), $storedHash)) {
            throw CommerceException::idempotencyConflict();
        }
    }

    public static function assertHash(string $hash): string
    {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $hash)) {
            throw CommerceException::invalidInput('idempotencyKeyHash must be 64 lowercase hex characters.');
        }

        return $hash;
    }

    public static function normalizeIdempotencyKey(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);
        $length = \strlen($idempotencyKey);
        if ($length < self::MIN_IDEMPOTENCY_KEY_LENGTH || $length > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw CommerceException::invalidInput(\sprintf(
                'idempotency key must be %d-%d characters.',
                self::MIN_IDEMPOTENCY_KEY_LENGTH,
                self::MAX_IDEMPOTENCY_KEY_LENGTH,
            ));
        }
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $idempotencyKey)) {
            throw CommerceException::invalidInput('idempotency key must be an opaque non-PII token.');
        }

        return $idempotencyKey;
    }

    private function keyMaterial(): string
    {
        $material = trim($this->rawKeyMaterial);
        if (\strlen($material) < self::MIN_KEY_BYTES) {
            throw CommerceException::idempotencyMisconfigured();
        }
        if ($this->enforceProductionPolicy) {
            $lower = strtolower($material);
            foreach (self::PRODUCTION_FORBIDDEN_NEEDLES as $needle) {
                if (str_contains($lower, $needle)) {
                    throw CommerceException::idempotencyMisconfigured();
                }
            }
        }

        return $material;
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $scope)) {
            throw CommerceException::invalidInput('idempotency scope must be snake_case.');
        }

        return $scope;
    }

    private function normalizeDiscriminator(string $discriminator): string
    {
        $discriminator = strtolower(trim($discriminator));
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/', $discriminator)) {
            throw CommerceException::invalidInput('idempotency discriminator must be an opaque non-PII token.');
        }

        return $discriminator;
    }
}
