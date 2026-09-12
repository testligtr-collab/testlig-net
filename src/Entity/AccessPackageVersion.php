<?php

declare(strict_types=1);

namespace App\Entity;

use App\Access\AccessPackagePolicyHasher;
use App\Enum\AccessPackageVersionStatus;
use App\Exception\AccessEntitlementException;
use App\Repository\AccessPackageVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AccessPackageVersionRepository::class)]
#[ORM\Table(name: 'access_package_versions')]
#[ORM\UniqueConstraint(name: 'uniq_apv_package_version', columns: ['package_id', 'version_number'])]
#[ORM\UniqueConstraint(name: 'uniq_apv_id_package', columns: ['id', 'package_id'])]
#[ORM\Index(name: 'idx_apv_package_status', columns: ['package_id', 'status'])]
#[ORM\Index(name: 'idx_apv_created_by', columns: ['created_by_id'])]
class AccessPackageVersion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessPackage $package;

    #[ORM\Column(name: 'version_number')]
    private int $versionNumber;

    #[ORM\Column(length: 32, enumType: AccessPackageVersionStatus::class)]
    private AccessPackageVersionStatus $status;

    #[ORM\Column(name: 'validity_days', nullable: true)]
    private ?int $validityDays;

    #[ORM\Column(name: 'seat_limit', nullable: true)]
    private ?int $seatLimit;

    #[ORM\Column(name: 'policy_hash', length: 64)]
    private string $policyHash;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'activated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $activatedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'activated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(name: 'superseded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $supersededAt = null;

    private function __construct(
        AccessPackage $package,
        int $versionNumber,
        ?int $validityDays,
        ?int $seatLimit,
        string $policyHash,
        User $createdBy,
        \DateTimeImmutable $now,
        int $schemaVersion = AccessPackagePolicyHasher::SCHEMA_VERSION,
        ?Uuid $id = null,
    ) {
        if ($versionNumber < 1) {
            throw AccessEntitlementException::invalidInput('versionNumber must be >= 1.');
        }
        if (null !== $validityDays && $validityDays < 1) {
            throw AccessEntitlementException::invalidInput('validityDays must be null or >= 1.');
        }
        self::assertSeatLimit($package, $seatLimit);
        self::assertPolicyHash($policyHash);

        $this->id = $id ?? new UuidV7();
        $this->package = $package;
        $this->versionNumber = $versionNumber;
        $this->status = AccessPackageVersionStatus::Draft;
        $this->validityDays = $validityDays;
        $this->seatLimit = $seatLimit;
        $this->policyHash = $policyHash;
        $this->schemaVersion = $schemaVersion;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AccessPackageVersionManager
     */
    public static function createDraft(
        AccessPackage $package,
        int $versionNumber,
        ?int $validityDays,
        ?int $seatLimit,
        string $policyHash,
        User $createdBy,
        \DateTimeImmutable $now,
        int $schemaVersion = AccessPackagePolicyHasher::SCHEMA_VERSION,
        ?Uuid $id = null,
    ): self {
        return new self(
            $package,
            $versionNumber,
            $validityDays,
            $seatLimit,
            $policyHash,
            $createdBy,
            $now,
            $schemaVersion,
            $id,
        );
    }

    public function updateDraft(
        ?int $validityDays,
        ?int $seatLimit,
        string $policyHash,
        \DateTimeImmutable $now,
    ): void {
        if (!$this->status->allowsDraftMutation()) {
            throw AccessEntitlementException::invalidTransition();
        }
        if (null !== $validityDays && $validityDays < 1) {
            throw AccessEntitlementException::invalidInput('validityDays must be null or >= 1.');
        }
        self::assertSeatLimit($this->package, $seatLimit);
        self::assertPolicyHash($policyHash);

        $this->validityDays = $validityDays;
        $this->seatLimit = $seatLimit;
        $this->policyHash = $policyHash;
        $this->updatedAt = $now;
    }

    public function replacePolicyHash(string $policyHash, \DateTimeImmutable $now): void
    {
        if (!$this->status->allowsDraftMutation()) {
            throw AccessEntitlementException::invalidTransition();
        }
        self::assertPolicyHash($policyHash);
        $this->policyHash = $policyHash;
        $this->updatedAt = $now;
    }

    public function activate(User $actor, \DateTimeImmutable $activatedAt): void
    {
        if (AccessPackageVersionStatus::Draft !== $this->status) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessPackageVersionStatus::Active;
        $this->activatedBy = $actor;
        $this->activatedAt = $activatedAt;
        $this->updatedAt = $activatedAt;
    }

    public function supersede(\DateTimeImmutable $supersededAt): void
    {
        if (AccessPackageVersionStatus::Active !== $this->status) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessPackageVersionStatus::Superseded;
        $this->supersededAt = $supersededAt;
        $this->updatedAt = $supersededAt;
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

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getStatus(): AccessPackageVersionStatus
    {
        return $this->status;
    }

    public function getValidityDays(): ?int
    {
        return $this->validityDays;
    }

    public function getSeatLimit(): ?int
    {
        return $this->seatLimit;
    }

    public function getPolicyHash(): string
    {
        return $this->policyHash;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    #[Ignore]
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    #[Ignore]
    public function getActivatedBy(): ?User
    {
        return $this->activatedBy;
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

    public function getSupersededAt(): ?\DateTimeImmutable
    {
        return $this->supersededAt;
    }

    private static function assertSeatLimit(AccessPackage $package, ?int $seatLimit): void
    {
        if ('individual' === $package->getTargetType()->value) {
            if (null !== $seatLimit) {
                throw AccessEntitlementException::invalidInput('Individual package versions cannot have a seat limit.');
            }

            return;
        }
        if (null !== $seatLimit && $seatLimit < 1) {
            throw AccessEntitlementException::invalidInput('Institution seat limit must be null or >= 1.');
        }
    }

    private static function assertPolicyHash(string $policyHash): void
    {
        if (1 !== preg_match('/^[0-9a-f]{'.AccessPackagePolicyHasher::HASH_HEX_LENGTH.'}$/', $policyHash)) {
            throw AccessEntitlementException::invalidInput('policyHash must be 64 lowercase hex characters.');
        }
    }
}
