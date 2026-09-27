<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InstitutionStudentInviteException;
use App\Repository\InstitutionStudentInvitationRepository;
use App\Service\InvitationCodeDigestHasher;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One-time student invitation for one institution, academic year, and classroom.
 * Only the HMAC digest is stored. Accepting creates a student membership and a classroom enrollment.
 * It does not record parental consent.
 */
#[ORM\Entity(repositoryClass: InstitutionStudentInvitationRepository::class)]
#[ORM\Table(name: 'institution_student_invitations')]
#[ORM\UniqueConstraint(name: 'uniq_student_invite_digest', columns: ['token_digest'])]
#[ORM\Index(name: 'idx_student_invite_institution_created', columns: ['institution_id', 'created_at'])]
#[ORM\Index(name: 'idx_student_invite_year', columns: ['academic_year_id'])]
#[ORM\Index(name: 'idx_student_invite_classroom', columns: ['classroom_id'])]
#[ORM\Index(name: 'idx_student_invite_creator', columns: ['created_by_user_id'])]
class InstitutionStudentInvitation
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
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Classroom $classroom;

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
        AcademicYear $academicYear,
        Classroom $classroom,
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
        $this->academicYear = $academicYear;
        $this->classroom = $classroom;
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
     * @internal prefer InstitutionStudentInvitationManager
     */
    public static function issue(
        Institution $institution,
        AcademicYear $academicYear,
        Classroom $classroom,
        User $createdBy,
        string $normalizedEmail,
        string $tokenDigest,
        string $pepperKeyId,
        ?string $operatorNote,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): self {
        $tokenDigest = InvitationCodeDigestHasher::assertDigest($tokenDigest);
        $pepperKeyId = trim($pepperKeyId);
        if ('' === $pepperKeyId || \strlen($pepperKeyId) > 32 || $expiresAt <= $now) {
            throw InstitutionStudentInviteException::invalidInput();
        }

        return new self(
            $institution,
            $academicYear,
            $classroom,
            $createdBy,
            $normalizedEmail,
            $tokenDigest,
            $pepperKeyId,
            $operatorNote,
            $expiresAt,
            $now,
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
    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    #[Ignore]
    public function getClassroom(): Classroom
    {
        return $this->classroom;
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

    public function getOperatorNote(): ?string
    {
        return $this->operatorNote;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isConsumed(): bool
    {
        return null !== $this->consumedAt;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isConsumed() && $now < $this->expiresAt;
    }

    /**
     * @internal prefer InstitutionStudentInvitationManager
     */
    public function replaceToken(
        Classroom $classroom,
        string $tokenDigest,
        string $pepperKeyId,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): void {
        if (!$this->isUsable($now) || !$classroom->getAcademicYear()->getId()->equals($this->academicYear->getId())) {
            throw InstitutionStudentInviteException::unavailable();
        }
        $this->classroom = $classroom;
        $this->tokenDigest = InvitationCodeDigestHasher::assertDigest($tokenDigest);
        $pepperKeyId = trim($pepperKeyId);
        if ('' === $pepperKeyId || \strlen($pepperKeyId) > 32 || $expiresAt <= $now) {
            throw InstitutionStudentInviteException::invalidInput();
        }
        $this->pepperKeyId = $pepperKeyId;
        $this->expiresAt = $expiresAt;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer InstitutionStudentInvitationManager
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        if ($this->isConsumed() || $this->isRevoked()) {
            throw InstitutionStudentInviteException::unavailable();
        }
        $this->revokedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer InstitutionStudentInvitationManager
     */
    public function consume(\DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw InstitutionStudentInviteException::unavailable();
        }
        $this->consumedAt = $now;
        $this->updatedAt = $now;
    }
}
