<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Exception\InstitutionMembershipException;
use App\Repository\InstitutionMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * User membership scoped to a single institution. Global ROLE_* does not imply access.
 */
#[ORM\Entity(repositoryClass: InstitutionMembershipRepository::class)]
#[ORM\Table(name: 'institution_memberships')]
#[ORM\UniqueConstraint(name: 'uniq_institution_membership_user', columns: ['institution_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_membership_id_institution', columns: ['id', 'institution_id'])]
#[ORM\Index(name: 'idx_membership_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_membership_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_membership_institution_role_status', columns: ['institution_id', 'role', 'status'])]
class InstitutionMembership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: InstitutionMembershipRole::class)]
    private InstitutionMembershipRole $role;

    #[ORM\Column(length: 32, enumType: InstitutionMembershipStatus::class)]
    private InstitutionMembershipStatus $status;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $joinedAt;

    #[ORM\Column(name: 'suspended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Institution $institution,
        User $user,
        InstitutionMembershipRole $role,
        InstitutionMembershipStatus $status,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $joinedAt,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->institution = $institution;
        $this->user = $user;
        $this->role = $role;
        $this->status = $status;
        $this->joinedAt = $joinedAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer InstitutionMembershipManager / InstitutionCreator
     */
    public static function createActive(
        Institution $institution,
        User $user,
        InstitutionMembershipRole $role,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($institution, $user, $role, InstitutionMembershipStatus::Active, $now, $now, $id);
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
    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): InstitutionMembershipRole
    {
        return $this->role;
    }

    public function getStatus(): InstitutionMembershipStatus
    {
        return $this->status;
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
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

    #[Ignore]
    public function changeRole(InstitutionMembershipRole $role, \DateTimeImmutable $now): void
    {
        if (InstitutionMembershipStatus::Ended === $this->status) {
            throw InstitutionMembershipException::invalidTransition();
        }
        $this->role = $role;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function suspend(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(InstitutionMembershipStatus::Suspended)) {
            throw InstitutionMembershipException::invalidTransition();
        }
        $this->status = InstitutionMembershipStatus::Suspended;
        $this->suspendedAt = $now;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function reactivate(\DateTimeImmutable $now): void
    {
        if (InstitutionMembershipStatus::Ended === $this->status) {
            throw InstitutionMembershipException::invalidTransition();
        }
        if (!$this->status->canTransitionTo(InstitutionMembershipStatus::Active)) {
            throw InstitutionMembershipException::invalidTransition();
        }
        $this->status = InstitutionMembershipStatus::Active;
        $this->joinedAt ??= $now;
        $this->suspendedAt = null;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function end(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(InstitutionMembershipStatus::Ended)) {
            throw InstitutionMembershipException::invalidTransition();
        }
        $this->status = InstitutionMembershipStatus::Ended;
        $this->endedAt = $now;
        $this->updatedAt = $now;
    }
}
