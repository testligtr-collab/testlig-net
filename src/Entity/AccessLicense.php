<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccessLicenseLicenseeType;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessLicenseStatus;
use App\Exception\AccessEntitlementException;
use App\Repository\AccessLicenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * License bound to a package version. validUntil is an exclusive upper bound:
 * validFrom <= now < validUntil.
 */
#[ORM\Entity(repositoryClass: AccessLicenseRepository::class)]
#[ORM\Table(name: 'access_licenses')]
#[ORM\UniqueConstraint(name: 'uniq_al_id_package', columns: ['id', 'package_id'])]
#[ORM\UniqueConstraint(name: 'uniq_al_id_package_version', columns: ['id', 'package_version_id'])]
#[ORM\UniqueConstraint(name: 'uniq_al_id_institution', columns: ['id', 'institution_id'])]
#[ORM\Index(name: 'idx_al_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_al_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_al_package_status', columns: ['package_id', 'status'])]
#[ORM\Index(name: 'idx_al_valid_range', columns: ['valid_from', 'valid_until'])]
class AccessLicense
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AccessPackage $package;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AccessPackageVersion $packageVersion;

    #[ORM\Column(name: 'licensee_type', length: 32, enumType: AccessLicenseLicenseeType::class)]
    private AccessLicenseLicenseeType $licenseeType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution;

    #[ORM\Column(length: 32, enumType: AccessLicenseStatus::class)]
    private AccessLicenseStatus $status;

    #[ORM\Column(name: 'source_type', length: 32, enumType: AccessLicenseSourceType::class)]
    private AccessLicenseSourceType $sourceType;

    #[ORM\Column(name: 'external_reference', length: 128, nullable: true)]
    private ?string $externalReference;

    #[ORM\Column(name: 'valid_from', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_until', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validUntil;

    #[ORM\Column(name: 'seat_limit', nullable: true)]
    private ?int $seatLimit;

    #[ORM\Column(name: 'policy_snapshot_hash', length: 64)]
    private string $policySnapshotHash;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'activated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $activatedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revoked_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $revokedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'activated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(name: 'suspended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'expired_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    #[ORM\Column(name: 'revocation_reason_code', length: 64, nullable: true)]
    private ?string $revocationReasonCode = null;

    private function __construct(
        AccessPackage $package,
        AccessPackageVersion $packageVersion,
        AccessLicenseLicenseeType $licenseeType,
        ?User $user,
        ?Institution $institution,
        AccessLicenseSourceType $sourceType,
        ?string $externalReference,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validUntil,
        ?int $seatLimit,
        string $policySnapshotHash,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        self::assertLicenseePair($licenseeType, $user, $institution);
        if (!$packageVersion->getPackage()->getId()->equals($package->getId())) {
            throw AccessEntitlementException::scopeMismatch();
        }
        if ($validUntil <= $validFrom) {
            throw AccessEntitlementException::invalidInput('validUntil must be after validFrom.');
        }
        if (AccessLicenseLicenseeType::User === $licenseeType && null !== $seatLimit) {
            throw AccessEntitlementException::invalidInput('User licenses cannot have a seat limit.');
        }
        if (AccessLicenseLicenseeType::Institution === $licenseeType && null !== $seatLimit && $seatLimit < 1) {
            throw AccessEntitlementException::invalidInput('Institution seat limit must be >= 1 when set.');
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $policySnapshotHash)) {
            throw AccessEntitlementException::invalidInput('policySnapshotHash must be 64 lowercase hex.');
        }

        $this->id = $id ?? new UuidV7();
        $this->package = $package;
        $this->packageVersion = $packageVersion;
        $this->licenseeType = $licenseeType;
        $this->user = $user;
        $this->institution = $institution;
        $this->status = AccessLicenseStatus::Pending;
        $this->sourceType = $sourceType;
        $this->externalReference = $externalReference;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->seatLimit = $seatLimit;
        $this->policySnapshotHash = $policySnapshotHash;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AccessLicenseManager
     */
    public static function createPending(
        AccessPackage $package,
        AccessPackageVersion $packageVersion,
        AccessLicenseLicenseeType $licenseeType,
        ?User $user,
        ?Institution $institution,
        AccessLicenseSourceType $sourceType,
        ?string $externalReference,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validUntil,
        ?int $seatLimit,
        string $policySnapshotHash,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $package,
            $packageVersion,
            $licenseeType,
            $user,
            $institution,
            $sourceType,
            $externalReference,
            $validFrom,
            $validUntil,
            $seatLimit,
            $policySnapshotHash,
            $createdBy,
            $now,
            $id,
        );
    }

    public function activate(User $actor, \DateTimeImmutable $activatedAt): void
    {
        if (AccessLicenseStatus::Pending !== $this->status) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessLicenseStatus::Active;
        $this->activatedBy = $actor;
        $this->activatedAt = $activatedAt;
        $this->updatedAt = $activatedAt;
    }

    public function suspend(\DateTimeImmutable $suspendedAt): void
    {
        if (AccessLicenseStatus::Active !== $this->status) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessLicenseStatus::Suspended;
        $this->suspendedAt = $suspendedAt;
        $this->updatedAt = $suspendedAt;
    }

    public function reactivate(\DateTimeImmutable $reactivatedAt): void
    {
        if (AccessLicenseStatus::Suspended !== $this->status) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessLicenseStatus::Active;
        $this->suspendedAt = null;
        $this->updatedAt = $reactivatedAt;
    }

    public function revoke(User $actor, string $reasonCode, \DateTimeImmutable $revokedAt): void
    {
        if ($this->status->isTerminal()) {
            throw AccessEntitlementException::invalidTransition();
        }
        if (AccessLicenseStatus::Pending !== $this->status
            && AccessLicenseStatus::Active !== $this->status
            && AccessLicenseStatus::Suspended !== $this->status
        ) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessLicenseStatus::Revoked;
        $this->revokedBy = $actor;
        $this->revokedAt = $revokedAt;
        $this->revocationReasonCode = $reasonCode;
        $this->updatedAt = $revokedAt;
    }

    public function markExpired(\DateTimeImmutable $expiredAt): void
    {
        if (AccessLicenseStatus::Active !== $this->status && AccessLicenseStatus::Suspended !== $this->status) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessLicenseStatus::Expired;
        $this->expiredAt = $expiredAt;
        $this->updatedAt = $expiredAt;
    }

    public function isWithinValidityWindow(\DateTimeImmutable $now): bool
    {
        return $this->validFrom <= $now && $now < $this->validUntil;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getPackage(): AccessPackage
    {
        return $this->package;
    }

    #[Ignore]
    public function getPackageVersion(): AccessPackageVersion
    {
        return $this->packageVersion;
    }

    public function getLicenseeType(): AccessLicenseLicenseeType
    {
        return $this->licenseeType;
    }

    #[Ignore]
    public function getUser(): ?User
    {
        return $this->user;
    }

    #[Ignore]
    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    public function getStatus(): AccessLicenseStatus
    {
        return $this->status;
    }

    public function getSourceType(): AccessLicenseSourceType
    {
        return $this->sourceType;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getSeatLimit(): ?int
    {
        return $this->seatLimit;
    }

    public function getPolicySnapshotHash(): string
    {
        return $this->policySnapshotHash;
    }

    #[Ignore]
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevocationReasonCode(): ?string
    {
        return $this->revocationReasonCode;
    }

    private static function assertLicenseePair(
        AccessLicenseLicenseeType $licenseeType,
        ?User $user,
        ?Institution $institution,
    ): void {
        if (AccessLicenseLicenseeType::User === $licenseeType) {
            if (null === $user || null !== $institution) {
                throw AccessEntitlementException::invalidInput('User license requires user and null institution.');
            }

            return;
        }
        if (null === $institution || null !== $user) {
            throw AccessEntitlementException::invalidInput('Institution license requires institution and null user.');
        }
    }
}
