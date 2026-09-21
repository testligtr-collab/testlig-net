<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ParentStudentLinkStatus;
use App\Exception\ParentStudentLinkException;
use App\Repository\ParentStudentLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Parent–student relationship row (Stage 2.22.5a foundation).
 *
 * Does not grant child-data access, UserRole, InstitutionMembership, or enrollment.
 * {@see ParentStudentLinkStatus::Verified} is representational only in this slice —
 * no accept/API/voter is wired. Active (pending|verified) uniqueness is enforced by
 * {@see ParentStudentLinkActiveGuard}, not a partial unique index.
 *
 * Ended rows are retained for history; a later pending row for the same pair is allowed
 * after the active guard is released.
 *
 * @internal prefer a future ParentStudentLinkManager — no public create/accept routes here
 */
#[ORM\Entity(repositoryClass: ParentStudentLinkRepository::class)]
#[ORM\Table(name: 'parent_student_links')]
#[ORM\UniqueConstraint(name: 'uniq_psl_id_parent_student', columns: ['id', 'parent_user_id', 'student_user_id'])]
#[ORM\Index(name: 'idx_psl_parent_status', columns: ['parent_user_id', 'status'])]
#[ORM\Index(name: 'idx_psl_student_status', columns: ['student_user_id', 'status'])]
#[ORM\Index(name: 'idx_psl_requested_at', columns: ['requested_at'])]
class ParentStudentLink
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'parent_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $parent;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $student;

    #[ORM\Column(length: 32, enumType: ParentStudentLinkStatus::class)]
    private ParentStudentLinkStatus $status;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'requested_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $requestedBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'verified_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $verifiedBy = null;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        User $parent,
        User $student,
        User $requestedBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->parent = $parent;
        $this->student = $student;
        $this->requestedBy = $requestedBy;
        $this->status = ParentStudentLinkStatus::Pending;
        $this->requestedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Pending request only. Does not open child-data access or imply an approval policy.
     *
     * @internal prefer a future ParentStudentLinkManager
     */
    public static function createPending(
        User $parent,
        User $student,
        User $requestedBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        if ($parent->getId()->equals($student->getId())) {
            throw ParentStudentLinkException::invalidInput();
        }

        return new self($parent, $student, $requestedBy, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getParent(): User
    {
        return $this->parent;
    }

    #[Ignore]
    public function getStudent(): User
    {
        return $this->student;
    }

    public function getStatus(): ParentStudentLinkStatus
    {
        return $this->status;
    }

    #[Ignore]
    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    #[Ignore]
    public function getVerifiedBy(): ?User
    {
        return $this->verifiedBy;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isPending(): bool
    {
        return ParentStudentLinkStatus::Pending === $this->status;
    }

    public function isVerified(): bool
    {
        return ParentStudentLinkStatus::Verified === $this->status;
    }

    public function isEnded(): bool
    {
        return ParentStudentLinkStatus::Ended === $this->status;
    }

    /**
     * Whether this row is the active pair occupant (requires active-guard row in DB).
     * Verified still does not imply child-data authorization in this foundation slice.
     */
    public function isActivePairOccupant(): bool
    {
        return $this->isPending() || $this->isVerified();
    }

    /**
     * Domain state transition only — not a public accept flow and not an access grant.
     *
     * @internal prefer a future ParentStudentLinkManager
     */
    public function markVerified(User $verifiedBy, \DateTimeImmutable $now): void
    {
        if (!$this->isPending()) {
            throw ParentStudentLinkException::invalidTransition();
        }
        if ($now < $this->requestedAt) {
            throw ParentStudentLinkException::invalidInput();
        }

        $this->status = ParentStudentLinkStatus::Verified;
        $this->verifiedBy = $verifiedBy;
        $this->verifiedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Soft-end. Releases the conceptual active slot; history row is retained.
     *
     * Contract for the future manager: {@see ParentStudentLinkActiveGuard} removal and
     * {@see markEnded()} MUST run in the same DB transaction so a concurrent create cannot
     * observe an ended link that still occupies the active-guard pair slot (or the reverse).
     *
     * @internal prefer a future ParentStudentLinkManager
     */
    public function markEnded(\DateTimeImmutable $now): void
    {
        if ($this->isEnded()) {
            throw ParentStudentLinkException::invalidTransition();
        }
        if ($now < $this->requestedAt) {
            throw ParentStudentLinkException::invalidInput();
        }
        if (null !== $this->verifiedAt && $now < $this->verifiedAt) {
            throw ParentStudentLinkException::invalidInput();
        }

        $this->status = ParentStudentLinkStatus::Ended;
        $this->endedAt = $now;
        $this->updatedAt = $now;
    }
}
