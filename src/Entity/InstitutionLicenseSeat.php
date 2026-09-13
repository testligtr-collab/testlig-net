<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InstitutionLicenseSeatStatus;
use App\Exception\AccessEntitlementException;
use App\Repository\InstitutionLicenseSeatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Seat assignment on an institution license. Revoked seats are not reactivated — assign a new row.
 */
#[ORM\Entity(repositoryClass: InstitutionLicenseSeatRepository::class)]
#[ORM\Table(name: 'institution_license_seats')]
#[ORM\UniqueConstraint(name: 'uniq_ils_id_license', columns: ['id', 'license_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ils_id_membership', columns: ['id', 'membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ils_id_license_membership', columns: ['id', 'license_id', 'membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ils_id_institution', columns: ['id', 'institution_id'])]
#[ORM\Index(name: 'idx_ils_license_status', columns: ['license_id', 'status'])]
#[ORM\Index(name: 'idx_ils_membership', columns: ['membership_id'])]
#[ORM\Index(name: 'idx_ils_user', columns: ['user_id'])]
class InstitutionLicenseSeat
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'license_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessLicense $license;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionMembership $membership;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: InstitutionLicenseSeatStatus::class)]
    private InstitutionLicenseSeatStatus $status;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assigned_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $assignedBy;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revoked_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $revokedBy = null;

    #[ORM\Column(name: 'revocation_reason_code', length: 64, nullable: true)]
    private ?string $revocationReasonCode = null;

    private function __construct(
        AccessLicense $license,
        Institution $institution,
        InstitutionMembership $membership,
        User $user,
        User $assignedBy,
        \DateTimeImmutable $assignedAt,
        ?Uuid $id = null,
    ) {
        if (!$license->getInstitution() instanceof Institution
            || !$license->getInstitution()->getId()->equals($institution->getId())
        ) {
            throw AccessEntitlementException::scopeMismatch('Seat institution must match license institution.');
        }
        if (!$membership->getInstitution()->getId()->equals($institution->getId())) {
            throw AccessEntitlementException::scopeMismatch('Membership institution mismatch.');
        }
        if (!$membership->getUser()->getId()->equals($user->getId())) {
            throw AccessEntitlementException::scopeMismatch('Membership user mismatch.');
        }

        $this->id = $id ?? new UuidV7();
        $this->license = $license;
        $this->institution = $institution;
        $this->membership = $membership;
        $this->user = $user;
        $this->status = InstitutionLicenseSeatStatus::Active;
        $this->assignedAt = $assignedAt;
        $this->assignedBy = $assignedBy;
    }

    /**
     * @internal prefer InstitutionLicenseSeatManager
     */
    public static function assign(
        AccessLicense $license,
        Institution $institution,
        InstitutionMembership $membership,
        User $user,
        User $assignedBy,
        \DateTimeImmutable $assignedAt,
        ?Uuid $id = null,
    ): self {
        return new self($license, $institution, $membership, $user, $assignedBy, $assignedAt, $id);
    }

    public function revoke(User $actor, string $reasonCode, \DateTimeImmutable $revokedAt): void
    {
        if (InstitutionLicenseSeatStatus::Active !== $this->status) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = InstitutionLicenseSeatStatus::Revoked;
        $this->revokedBy = $actor;
        $this->revokedAt = $revokedAt;
        $this->revocationReasonCode = $reasonCode;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getLicense(): AccessLicense
    {
        return $this->license;
    }

    #[Ignore]
    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getMembership(): InstitutionMembership
    {
        return $this->membership;
    }

    #[Ignore]
    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): InstitutionLicenseSeatStatus
    {
        return $this->status;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    #[Ignore]
    public function getAssignedBy(): User
    {
        return $this->assignedBy;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevocationReasonCode(): ?string
    {
        return $this->revocationReasonCode;
    }
}
