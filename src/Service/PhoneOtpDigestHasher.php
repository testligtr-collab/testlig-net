<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\PhoneVerificationPurpose;
use App\Exception\PhoneVerificationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * HMAC-SHA256 digests for phone verification OTP values.
 *
 * Plain OTP is never persisted, logged, or audited. Key material comes only from
 * PHONE_OTP_PEPPER — there is no application-secret fallback. Production rejects short or
 * placeholder peppers.
 */
final class PhoneOtpDigestHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const MIN_PEPPER_BYTES = 32;

    public const DEFAULT_KEY_ID = 'v1';

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

    private readonly string $pepper;

    private readonly string $keyId;

    public function __construct(
        #[Autowire('%env(PHONE_OTP_PEPPER)%')]
        string $pepper,
        #[Autowire('%env(PHONE_OTP_PEPPER_KEY_ID)%')]
        string $keyId,
        #[Autowire('%kernel.environment%')]
        string $environment,
    ) {
        $this->enforceProductionPolicy = 'prod' === $environment;
        $this->pepper = $this->assertUsablePepper($pepper);
        $this->keyId = $this->assertKeyId($keyId);
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    public function hash(
        PhoneVerificationPurpose $purpose,
        Uuid $claimId,
        string $normalizedPhone,
        string $otp,
    ): string {
        return hash_hmac('sha256', $this->canonicalMessage($purpose, $claimId, $normalizedPhone, $otp), $this->pepper);
    }

    public function verify(
        string $storedDigest,
        PhoneVerificationPurpose $purpose,
        Uuid $claimId,
        string $normalizedPhone,
        string $otp,
    ): void {
        self::assertDigest($storedDigest);
        $expected = $this->hash($purpose, $claimId, $normalizedPhone, $otp);
        if (!hash_equals($expected, $storedDigest)) {
            throw PhoneVerificationException::digestMismatch();
        }
    }

    public static function assertDigest(string $digest): string
    {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $digest)) {
            throw PhoneVerificationException::invalidInput('codeDigest must be 64 lowercase hex characters.');
        }

        return $digest;
    }

    private function canonicalMessage(
        PhoneVerificationPurpose $purpose,
        Uuid $claimId,
        string $normalizedPhone,
        string $otp,
    ): string {
        $otp = trim($otp);
        if (1 !== preg_match('/^[0-9]{6}$/', $otp)) {
            throw PhoneVerificationException::invalidInput();
        }

        if (1 !== preg_match(PhoneNormalizer::CANONICAL_PATTERN, $normalizedPhone)) {
            throw PhoneVerificationException::invalidInput();
        }

        return implode("\n", [
            'phone_otp_v1',
            $purpose->value,
            $claimId->toRfc4122(),
            $normalizedPhone,
            $otp,
        ]);
    }

    private function assertUsablePepper(string $pepper): string
    {
        $pepper = trim($pepper);
        if (\strlen($pepper) < self::MIN_PEPPER_BYTES) {
            throw PhoneVerificationException::digestMisconfigured();
        }

        if ($this->enforceProductionPolicy) {
            $lower = strtolower($pepper);
            foreach (self::PRODUCTION_FORBIDDEN_NEEDLES as $needle) {
                if (str_contains($lower, $needle)) {
                    throw PhoneVerificationException::digestMisconfigured();
                }
            }
        }

        return $pepper;
    }

    private function assertKeyId(string $keyId): string
    {
        $keyId = trim($keyId);
        if ('' === $keyId) {
            $keyId = self::DEFAULT_KEY_ID;
        }
        if (1 !== preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,31}$/', $keyId)) {
            throw PhoneVerificationException::digestMisconfigured();
        }

        return $keyId;
    }
}
