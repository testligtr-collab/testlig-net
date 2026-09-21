<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ParticipationCodeScope;
use App\Exception\InvitationCodeException;
use App\Repository\ParticipationCodeRepository;
use App\Service\InvitationCodeDigestHasher;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Limited multi-use institution/classroom participation code (Stage 2.22.4a foundation).
 *
 * The code itself is not a role or membership. Per-redeem rows belong to a later slice;
 * this aggregate tracks max_redemptions + redemption_count for the next transaction.
 * Plain code is never stored — only HMAC digest.
 */
#[ORM\Entity(repositoryClass: ParticipationCodeRepository::class)]
#[ORM\Table(name: 'participation_codes')]
#[ORM\UniqueConstraint(name: 'uniq_participation_code_digest', columns: ['code_digest'])]
#[ORM\Index(name: 'idx_part_code_institution_scope', columns: ['institution_id', 'scope'])]
#[ORM\Index(name: 'idx_part_code_classroom', columns: ['classroom_id'])]
#[ORM\Index(name: 'idx_part_code_creator_created', columns: ['created_by_user_id', 'created_at'])]
#[ORM\Index(name: 'idx_part_code_expires', columns: ['expires_at'])]
class ParticipationCode
{
    public const MAX_REDEMPTIONS_CAP = 10000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Classroom $classroom;

    #[ORM\Column(length: 32, enumType: ParticipationCodeScope::class)]
    private ParticipationCodeScope $scope;

    #[ORM\Column(name: 'code_digest', length: 64)]
    #[Ignore]
    private string $codeDigest;

    #[ORM\Column(name: 'pepper_key_id', length: 32)]
    private string $pepperKeyId;

    #[ORM\Column(name: 'max_redemptions', options: ['unsigned' => true])]
    private int $maxRedemptions;

    #[ORM\Column(name: 'redemption_count', options: ['unsigned' => true])]
    private int $redemptionCount = 0;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        User $createdBy,
        Institution $institution,
        ?Classroom $classroom,
        ParticipationCodeScope $scope,
        string $codeDigest,
        string $pepperKeyId,
        int $maxRedemptions,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->createdBy = $createdBy;
        $this->institution = $institution;
        $this->classroom = $classroom;
        $this->scope = $scope;
        $this->codeDigest = $codeDigest;
        $this->pepperKeyId = $pepperKeyId;
        $this->maxRedemptions = $maxRedemptions;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer a future ParticipationCodeManager — controllers must not call this
     */
    public static function createActive(
        User $createdBy,
        Institution $institution,
        ParticipationCodeScope $scope,
        string $codeDigest,
        string $pepperKeyId,
        int $maxRedemptions,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?Classroom $classroom = null,
        ?Uuid $id = null,
    ): self {
        $codeDigest = InvitationCodeDigestHasher::assertDigest($codeDigest);
        $pepperKeyId = trim($pepperKeyId);
        if ('' === $pepperKeyId || \strlen($pepperKeyId) > 32) {
            throw InvitationCodeException::invalidInput();
        }
        if ($expiresAt <= $now) {
            throw InvitationCodeException::invalidInput();
        }
        if ($maxRedemptions < 1 || $maxRedemptions > self::MAX_REDEMPTIONS_CAP) {
            throw InvitationCodeException::invalidInput();
        }

        if (ParticipationCodeScope::Classroom === $scope) {
            if (null === $classroom) {
                throw InvitationCodeException::invalidInput();
            }
            if ($classroom->getInstitution()->getId()->toRfc4122() !== $institution->getId()->toRfc4122()) {
                throw InvitationCodeException::invalidInput();
            }
        } elseif (null !== $classroom) {
            throw InvitationCodeException::invalidInput();
        }

        return new self(
            $createdBy,
            $institution,
            $classroom,
            $scope,
            $codeDigest,
            $pepperKeyId,
            $maxRedemptions,
            $expiresAt,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    #[Ignore]
    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getClassroom(): ?Classroom
    {
        return $this->classroom;
    }

    public function getScope(): ParticipationCodeScope
    {
        return $this->scope;
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

    public function getMaxRedemptions(): int
    {
        return $this->maxRedemptions;
    }

    public function getRedemptionCount(): int
    {
        return $this->redemptionCount;
    }

    public function getRemainingRedemptions(): int
    {
        return max(0, $this->maxRedemptions - $this->redemptionCount);
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
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

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isExhausted(): bool
    {
        return $this->redemptionCount >= $this->maxRedemptions;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now) && !$this->isExhausted();
    }

    /**
     * Reserve one use on the aggregate. Per-redeem audit rows are a later slice.
     *
     * @internal prefer a future ParticipationCodeManager
     */
    public function recordRedemption(\DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw InvitationCodeException::unavailable();
        }
        ++$this->redemptionCount;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer a future ParticipationCodeManager
     */
    public function markRevoked(\DateTimeImmutable $now): void
    {
        if ($this->isRevoked()) {
            throw InvitationCodeException::invalidTransition();
        }
        $this->revokedAt = $now;
        $this->updatedAt = $now;
    }
}
