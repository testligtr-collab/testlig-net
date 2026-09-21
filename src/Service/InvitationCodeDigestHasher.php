<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\InvitationCodeKind;
use App\Exception\InvitationCodeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * HMAC-SHA256 digests for personal invitations and participation codes.
 *
 * Plain codes are never persisted, logged, or audited. Key material comes only from
 * INVITATION_CODE_PEPPER — there is no application-secret fallback. Production rejects
 * short or placeholder peppers. Key rotation is tracked via INVITATION_CODE_PEPPER_KEY_ID
 * stored beside each digest. verify() requires the stored key id to match this hasher's
 * key id (constant-time); mismatched ids fail without retrying under the current pepper.
 */
final class InvitationCodeDigestHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const MIN_PEPPER_BYTES = 32;

    public const DEFAULT_KEY_ID = 'v1';

    public const CODE_MIN_LENGTH = 8;

    public const CODE_MAX_LENGTH = 128;

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
        #[Autowire('%env(INVITATION_CODE_PEPPER)%')]
        string $pepper,
        #[Autowire('%env(INVITATION_CODE_PEPPER_KEY_ID)%')]
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

    public function hash(InvitationCodeKind $kind, Uuid $recordId, string $purposeOrScope, string $plainCode): string
    {
        return hash_hmac('sha256', $this->canonicalMessage($kind, $recordId, $purposeOrScope, $plainCode), $this->pepper);
    }

    public function verify(
        string $storedDigest,
        string $storedPepperKeyId,
        InvitationCodeKind $kind,
        Uuid $recordId,
        string $purposeOrScope,
        string $plainCode,
    ): void {
        self::assertDigest($storedDigest);
        $storedPepperKeyId = trim($storedPepperKeyId);
        if ('' === $storedPepperKeyId || !hash_equals($this->keyId, $storedPepperKeyId)) {
            throw InvitationCodeException::pepperKeyMismatch();
        }
        $expected = $this->hash($kind, $recordId, $purposeOrScope, $plainCode);
        if (!hash_equals($expected, $storedDigest)) {
            throw InvitationCodeException::digestMismatch();
        }
    }

    public static function assertDigest(string $digest): string
    {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $digest)) {
            throw InvitationCodeException::invalidInput('codeDigest must be 64 lowercase hex characters.');
        }

        return $digest;
    }

    public static function assertPurposeCode(string $purposeCode): string
    {
        $purposeCode = trim($purposeCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $purposeCode)) {
            throw InvitationCodeException::invalidInput();
        }

        return $purposeCode;
    }

    private function canonicalMessage(
        InvitationCodeKind $kind,
        Uuid $recordId,
        string $purposeOrScope,
        string $plainCode,
    ): string {
        $purposeOrScope = self::assertPurposeCode($purposeOrScope);
        $plainCode = $this->assertPlainCode($plainCode);

        return implode("\n", [
            $kind->value.'_v1',
            $recordId->toRfc4122(),
            $purposeOrScope,
            $plainCode,
        ]);
    }

    private function assertPlainCode(string $plainCode): string
    {
        $plainCode = trim($plainCode);
        $length = \strlen($plainCode);
        if ($length < self::CODE_MIN_LENGTH || $length > self::CODE_MAX_LENGTH) {
            throw InvitationCodeException::invalidInput();
        }
        // Allow high-entropy tokens and lower-entropy join codes without inventing a product alphabet.
        if (1 !== preg_match('/^[\x21-\x7E]+$/', $plainCode)) {
            throw InvitationCodeException::invalidInput();
        }

        return $plainCode;
    }

    private function assertUsablePepper(string $pepper): string
    {
        $pepper = trim($pepper);
        if (\strlen($pepper) < self::MIN_PEPPER_BYTES) {
            throw InvitationCodeException::digestMisconfigured();
        }

        if ($this->enforceProductionPolicy) {
            $lower = strtolower($pepper);
            foreach (self::PRODUCTION_FORBIDDEN_NEEDLES as $needle) {
                if (str_contains($lower, $needle)) {
                    throw InvitationCodeException::digestMisconfigured();
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
            throw InvitationCodeException::digestMisconfigured();
        }

        return $keyId;
    }
}
