<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InstitutionTeacherInviteException;
use App\Repository\InstitutionTeacherInvitationRepository;
use App\Service\InvitationCodeDigestHasher;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One-time teacher invitation for a single institution and normalized email.
 *
 * PersonalInvitation cannot represent a recipient who has no user row yet.
 * Only the HMAC digest is stored. Accepting creates an InstitutionMembership
 * of role teacher and does not grant a global role.
 */
#[ORM\Entity(repositoryClass: InstitutionTeacherInvitationRepository::class)]
#[ORM\Table(name: 'institution_teacher_invitations')]
#[ORM\UniqueConstraint(name: 'uniq_teacher_invite_digest', columns: ['token_digest'])]
#[ORM\Index(name: 'idx_teacher_invite_institution_created', columns: ['institution_id', 'created_at'])]
#[ORM\Index(name: 'idx_teacher_invite_creator', columns: ['created_by_user_id'])]
class InstitutionTeacherInvitation
{
    public const TTL = 'PT72H';
    public const NOTE_MAX = 280;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'normalized_email', length: 180)]
    private string $normalizedEmail;

    #[ORM\Column(name: 'token_digest', length: 64)]
    #[Ignore]
    private string $tokenDigest;

    #[ORM\Column(name: 'pepper_key_id', length: 32)]
    private string $pepperKeyId;

    #[ORM\Column(name: 'operator_note', length: 280, nullable: true)]
    private ?string $operatorNote;

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
        Institution $institution,
        User $createdBy,
        string $normalizedEmail,
        string $tokenDigest,
        string $pepperKeyId,
        ?string $operatorNote,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->institution = $institution;
        $this->createdBy = $createdBy;
        $this->normalizedEmail = $normalizedEmail;
        $this->tokenDigest = $tokenDigest;
        $this->pepperKeyId = $pepperKeyId;
        $this->operatorNote = $operatorNote;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer InstitutionTeacherInvitationManager
     */
    public static function issue(
        Institution $institution,
        User $createdBy,
        string $normalizedEmail,
        string $tokenDigest,
        string $pepperKeyId,
        ?string $operatorNote,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        $tokenDigest = InvitationCodeDigestHasher::assertDigest($tokenDigest);
        $pepperKeyId = trim($pepperKeyId);
        if ('' === $pepperKeyId || \strlen($pepperKeyId) > 32 || $expiresAt <= $now) {
            throw InstitutionTeacherInviteException::invalidInput();
        }

        return new self(
            $institution,
            $createdBy,
            $normalizedEmail,
            $tokenDigest,
            $pepperKeyId,
            $operatorNote,
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
    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getNormalizedEmail(): string
    {
        return $this->normalizedEmail;
    }

    #[Ignore]
    public function getTokenDigest(): string
    {
        return $this->tokenDigest;
    }

    public function getPepperKeyId(): string
    {
        return $this->pepperKeyId;
    }

    public function getOperatorNote(): ?string
    {
        return $this->operatorNote;
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
     * @internal prefer InstitutionTeacherInvitationManager
     */
    public function replaceToken(string $tokenDigest, string $pepperKeyId, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw InstitutionTeacherInviteException::unavailable();
        }
        $this->tokenDigest = InvitationCodeDigestHasher::assertDigest($tokenDigest);
        $pepperKeyId = trim($pepperKeyId);
        if ('' === $pepperKeyId || \strlen($pepperKeyId) > 32 || $expiresAt <= $now) {
            throw InstitutionTeacherInviteException::invalidInput();
        }
        $this->pepperKeyId = $pepperKeyId;
        $this->expiresAt = $expiresAt;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer InstitutionTeacherInvitationManager
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        if ($this->isConsumed() || $this->isRevoked()) {
            throw InstitutionTeacherInviteException::unavailable();
        }
        $this->revokedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer InstitutionTeacherInvitationManager
     */
    public function consume(\DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw InstitutionTeacherInviteException::unavailable();
        }
        $this->consumedAt = $now;
        $this->updatedAt = $now;
    }
}
