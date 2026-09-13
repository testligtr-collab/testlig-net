<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\AccessEntitlementException;
use App\Repository\InstitutionLicenseActiveSeatGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * At most one active seat per (license_id, membership_id). Trigger-owned.
 */
#[ORM\Entity(repositoryClass: InstitutionLicenseActiveSeatGuardRepository::class)]
#[ORM\Table(name: 'institution_license_active_seat_guards')]
#[ORM\UniqueConstraint(name: 'uniq_ilasg_seat', columns: ['seat_id'])]
class InstitutionLicenseActiveSeatGuard
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'license_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessLicense $license;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionMembership $membership;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'seat_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionLicenseSeat $seat;

    private function __construct(
        AccessLicense $license,
        InstitutionMembership $membership,
        InstitutionLicenseSeat $seat,
    ) {
        if (!$seat->getLicense()->getId()->equals($license->getId())) {
            throw AccessEntitlementException::scopeMismatch();
        }
        if (!$seat->getMembership()->getId()->equals($membership->getId())) {
            throw AccessEntitlementException::scopeMismatch();
        }
        $this->license = $license;
        $this->membership = $membership;
        $this->seat = $seat;
    }

    /**
     * @internal prefer DB trigger ownership
     */
    public static function bind(
        AccessLicense $license,
        InstitutionMembership $membership,
        InstitutionLicenseSeat $seat,
    ): self {
        return new self($license, $membership, $seat);
    }

    public function getLicenseId(): Uuid
    {
        return $this->license->getId();
    }

    public function getMembershipId(): Uuid
    {
        return $this->membership->getId();
    }

    #[Ignore]
    public function getSeat(): InstitutionLicenseSeat
    {
        return $this->seat;
    }
}
