<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InvitationCodeException;
use App\Invitation\InvitationPurposeContract;
use App\Repository\PersonalInvitationRepository;
use App\Service\InvitationCodeDigestHasher;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Single-recipient, single-use personal invitation (Stage 2.22.4a foundation).
 *
 * Does not grant UserRole or InstitutionMembership. Plain invite token is never stored —
 * only HMAC digest. Redeem / delivery / auto e-mail matching are out of this slice.
 *
 * Intended recipient User is required (ADR §7.A). purpose_code is opaque metadata only —
 * see InvitationPurposeContract (fail-closed at redeem; never a privilege source).
 *
 * Lifecycle (derived): revoked → consumed → expired (now >= expiresAt) → pending/usable.
 */
#[ORM\Entity(repositoryClass: PersonalInvitationRepository::class)]
#[ORM\Table(name: 'personal_invitations')]
#[ORM\UniqueConstraint(name: 'uniq_personal_invitation_code_digest', columns: ['code_digest'])]
#[ORM\Index(name: 'idx_personal_inv_creator_created', columns: ['created_by_user_id', 'created_at'])]
#[ORM\Index(name: 'idx_personal_inv_recipient', columns: ['intended_recipient_user_id'])]
#[ORM\Index(name: 'idx_personal_inv_institution', columns: ['institution_id'])]
#[ORM\Index(name: 'idx_personal_inv_expires', columns: ['expires_at'])]
class PersonalInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'intended_recipient_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $intendedRecipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Classroom $classroom;

    #[ORM\Column(name: 'purpose_code', length: 64)]
    private string $purposeCode;

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

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        User $createdBy,
        User $intendedRecipient,
        ?Institution $institution,
        ?Classroom $classroom,
        string $purposeCode,
        string $codeDigest,
        string $pepperKeyId,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->createdBy = $createdBy;
        $this->intendedRecipient = $intendedRecipient;
        $this->institution = $institution;
        $this->classroom = $classroom;
        $this->purposeCode = $purposeCode;
        $this->codeDigest = $codeDigest;
        $this->pepperKeyId = $pepperKeyId;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer a future PersonalInvitationManager — controllers must not call this
     */
    public static function createPending(
        User $createdBy,
        User $intendedRecipient,
        string $purposeCode,
        string $codeDigest,
        string $pepperKeyId,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?Institution $institution = null,
        ?Classroom $classroom = null,
        ?Uuid $id = null,
    ): self {
        $purposeCode = InvitationPurposeContract::normalizeForStorage($purposeCode);
        $codeDigest = InvitationCodeDigestHasher::assertDigest($codeDigest);
        $pepperKeyId = trim($pepperKeyId);
        if ('' === $pepperKeyId || \strlen($pepperKeyId) > 32) {
            throw InvitationCodeException::invalidInput();
        }
        if ($expiresAt <= $now) {
            throw InvitationCodeException::invalidInput();
        }
        if ($intendedRecipient->getId()->equals($createdBy->getId())) {
            throw InvitationCodeException::invalidInput();
        }
        if (null !== $classroom) {
            if (null === $institution) {
                throw InvitationCodeException::invalidInput();
            }
            if ($classroom->getInstitution()->getId()->toRfc4122() !== $institution->getId()->toRfc4122()) {
                throw InvitationCodeException::invalidInput();
            }
        }

        return new self(
            $createdBy,
            $intendedRecipient,
            $institution,
            $classroom,
            $purposeCode,
            $codeDigest,
            $pepperKeyId,
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
    public function getIntendedRecipient(): User
    {
        return $this->intendedRecipient;
    }

    #[Ignore]
    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getClassroom(): ?Classroom
    {
        return $this->classroom;
    }

    public function getPurposeCode(): string
    {
        return $this->purposeCode;
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

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isConsumed() && !$this->isExpired($now);
    }

    /**
     * @internal prefer a future PersonalInvitationManager
     */
    public function markConsumed(\DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw InvitationCodeException::unavailable();
        }
        $this->consumedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer a future PersonalInvitationManager
     */
    public function markRevoked(\DateTimeImmutable $now): void
    {
        if ($this->isConsumed() || $this->isRevoked()) {
            throw InvitationCodeException::invalidTransition();
        }
        $this->revokedAt = $now;
        $this->updatedAt = $now;
    }
}
