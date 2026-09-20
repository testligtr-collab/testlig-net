<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PhoneVerificationPurpose;
use App\Exception\PhoneVerificationException;
use App\Repository\PhoneVerificationClaimRepository;
use App\Service\PhoneNormalizer;
use App\Service\PhoneOtpDigestHasher;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Short-lived phone verification challenge (Stage 2.22.2a foundation).
 *
 * Lifecycle is derived from timestamps (no persisted "expired" status):
 * - revokedAt set → revoked
 * - consumedAt set → consumed
 * - else pending (expired when now >= expiresAt)
 *
 * Plain OTP is never stored — only HMAC digest.
 */
#[ORM\Entity(repositoryClass: PhoneVerificationClaimRepository::class)]
#[ORM\Table(name: 'phone_verification_claims')]
#[ORM\Index(name: 'idx_pvc_user_purpose_created', columns: ['user_id', 'purpose', 'created_at'])]
#[ORM\Index(name: 'idx_pvc_expires_at', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_pvc_target_normalized_phone', columns: ['target_normalized_phone'])]
#[ORM\HasLifecycleCallbacks]
class PhoneVerificationClaim
{
    public const OTP_TTL_SECONDS = 300;

    public const MAX_FAILED_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: PhoneVerificationPurpose::class)]
    private PhoneVerificationPurpose $purpose;

    #[ORM\Column(name: 'target_phone', length: 32)]
    private string $targetPhone;

    #[ORM\Column(name: 'target_normalized_phone', length: 20)]
    private string $targetNormalizedPhone;

    #[ORM\Column(name: 'code_digest', length: 64)]
    #[Ignore]
    private string $codeDigest;

    #[ORM\Column(name: 'pepper_key_id', length: 32)]
    private string $pepperKeyId;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'failed_attempt_count', options: ['unsigned' => true])]
    private int $failedAttemptCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        User $user,
        PhoneVerificationPurpose $purpose,
        string $targetPhone,
        string $targetNormalizedPhone,
        string $codeDigest,
        string $pepperKeyId,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->user = $user;
        $this->purpose = $purpose;
        $this->targetPhone = $targetPhone;
        $this->targetNormalizedPhone = $targetNormalizedPhone;
        $this->codeDigest = PhoneOtpDigestHasher::assertDigest($codeDigest);
        $this->pepperKeyId = $pepperKeyId;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer a future PhoneVerificationClaimManager — controllers must not call this
     */
    public static function createPending(
        User $user,
        PhoneVerificationPurpose $purpose,
        string $targetPhone,
        string $targetNormalizedPhone,
        string $codeDigest,
        string $pepperKeyId,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): self {
        // Stage 2.22.2a: only bind_phone exists on the enum; later purposes expand here.
        $display = trim($targetPhone);
        if ('' === $display || \strlen($display) > 32) {
            throw PhoneVerificationException::invalidInput();
        }
        if (1 !== preg_match(PhoneNormalizer::CANONICAL_PATTERN, $targetNormalizedPhone)) {
            throw PhoneVerificationException::invalidInput();
        }

        $expires = $expiresAt ?? $now->modify('+'.self::OTP_TTL_SECONDS.' seconds');
        if ($expires <= $now) {
            throw PhoneVerificationException::invalidInput();
        }

        return new self(
            $user,
            $purpose,
            $display,
            $targetNormalizedPhone,
            $codeDigest,
            $pepperKeyId,
            $expires,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getUser(): User
    {
        return $this->user;
    }

    public function getPurpose(): PhoneVerificationPurpose
    {
        return $this->purpose;
    }

    public function getTargetPhone(): string
    {
        return $this->targetPhone;
    }

    public function getTargetNormalizedPhone(): string
    {
        return $this->targetNormalizedPhone;
    }

    #[Ignore]
    public function getCodeDigest(): string
    {
        return $this->codeDigest;
    }

    public function getPepperKeyId(): string
    {
        return $this->pepperKeyId;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getFailedAttemptCount(): int
    {
        return $this->failedAttemptCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isConsumed(): bool
    {
        return null !== $this->consumedAt;
    }

    public function isPending(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isConsumed() && !$this->isExpired($now);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        if ($this->isRevoked() || $this->isConsumed()) {
            return false;
        }

        return $now >= $this->expiresAt;
    }

    /**
     * @internal Prefer manager in 2.22.2b
     */
    public function markConsumed(\DateTimeImmutable $now): void
    {
        if ($this->isRevoked() || $this->isConsumed()) {
            throw PhoneVerificationException::invalidInput();
        }
        if ($this->isExpired($now)) {
            throw PhoneVerificationException::invalidInput();
        }

        $this->consumedAt = $now;
        $this->touch($now);
    }

    /**
     * @internal Prefer manager in 2.22.2b
     */
    public function markRevoked(\DateTimeImmutable $now): void
    {
        if ($this->isConsumed()) {
            throw PhoneVerificationException::invalidInput();
        }
        if ($this->isRevoked()) {
            return;
        }

        $this->revokedAt = $now;
        $this->touch($now);
    }

    /**
     * @internal Prefer manager in 2.22.2b — resend must not reset this counter.
     */
    public function recordFailedAttempt(\DateTimeImmutable $now): void
    {
        if (!$this->isPending($now)) {
            throw PhoneVerificationException::invalidInput();
        }
        if ($this->failedAttemptCount >= self::MAX_FAILED_ATTEMPTS) {
            throw PhoneVerificationException::invalidInput();
        }

        ++$this->failedAttemptCount;
        $this->touch($now);
    }

    public function hasExceededFailedAttempts(): bool
    {
        return $this->failedAttemptCount >= self::MAX_FAILED_ATTEMPTS;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->touch(new \DateTimeImmutable('now'));
    }

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
