<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccessPackageStatus;
use App\Enum\AccessPackageTargetType;
use App\Exception\AccessEntitlementException;
use App\Repository\AccessPackageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Commercial access package identity (platform catalog). Code is immutable after create.
 */
#[ORM\Entity(repositoryClass: AccessPackageRepository::class)]
#[ORM\Table(name: 'access_packages')]
#[ORM\UniqueConstraint(name: 'uniq_ap_code', columns: ['code'])]
#[ORM\Index(name: 'idx_ap_status_target', columns: ['status', 'target_type'])]
#[ORM\Index(name: 'idx_ap_created_by', columns: ['created_by_id'])]
class AccessPackage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 200)]
    private string $normalizedName;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(name: 'target_type', length: 32, enumType: AccessPackageTargetType::class)]
    private AccessPackageTargetType $targetType;

    #[ORM\Column(length: 32, enumType: AccessPackageStatus::class)]
    private AccessPackageStatus $status;

    #[ORM\Column(name: 'default_validity_days', nullable: true)]
    private ?int $defaultValidityDays;

    #[ORM\Column(name: 'default_seat_limit', nullable: true)]
    private ?int $defaultSeatLimit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'activated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(name: 'retired_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    private function __construct(
        string $code,
        string $name,
        string $normalizedName,
        ?string $description,
        AccessPackageTargetType $targetType,
        ?int $defaultValidityDays,
        ?int $defaultSeatLimit,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        self::assertSeatLimitRules($targetType, $defaultSeatLimit);
        if (null !== $defaultValidityDays && $defaultValidityDays < 1) {
            throw AccessEntitlementException::invalidInput('defaultValidityDays must be null or >= 1.');
        }

        $this->id = $id ?? new UuidV7();
        $this->code = $code;
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->description = $description;
        $this->targetType = $targetType;
        $this->status = AccessPackageStatus::Draft;
        $this->defaultValidityDays = $defaultValidityDays;
        $this->defaultSeatLimit = $defaultSeatLimit;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AccessPackageManager
     */
    public static function createDraft(
        string $code,
        string $name,
        string $normalizedName,
        ?string $description,
        AccessPackageTargetType $targetType,
        ?int $defaultValidityDays,
        ?int $defaultSeatLimit,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $code,
            $name,
            $normalizedName,
            $description,
            $targetType,
            $defaultValidityDays,
            $defaultSeatLimit,
            $createdBy,
            $now,
            $id,
        );
    }

    public function updateDraftFields(
        string $name,
        string $normalizedName,
        ?string $description,
        ?int $defaultValidityDays,
        ?int $defaultSeatLimit,
        \DateTimeImmutable $now,
    ): void {
        if (AccessPackageStatus::Retired === $this->status) {
            throw AccessEntitlementException::invalidTransition();
        }
        if (AccessPackageStatus::Active === $this->status) {
            // Active packages may update display fields only; seat/validity defaults stay mutable for future versions.
        }
        self::assertSeatLimitRules($this->targetType, $defaultSeatLimit);
        if (null !== $defaultValidityDays && $defaultValidityDays < 1) {
            throw AccessEntitlementException::invalidInput('defaultValidityDays must be null or >= 1.');
        }

        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->description = $description;
        $this->defaultValidityDays = $defaultValidityDays;
        $this->defaultSeatLimit = $defaultSeatLimit;
        $this->updatedAt = $now;
    }

    public function activate(\DateTimeImmutable $activatedAt): void
    {
        if (!$this->status->allowsActivation()) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessPackageStatus::Active;
        $this->activatedAt = $activatedAt;
        $this->updatedAt = $activatedAt;
    }

    public function retire(\DateTimeImmutable $retiredAt): void
    {
        if (!$this->status->allowsRetirement()) {
            throw AccessEntitlementException::invalidTransition();
        }
        $this->status = AccessPackageStatus::Retired;
        $this->retiredAt = $retiredAt;
        $this->updatedAt = $retiredAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getTargetType(): AccessPackageTargetType
    {
        return $this->targetType;
    }

    public function getStatus(): AccessPackageStatus
    {
        return $this->status;
    }

    public function getDefaultValidityDays(): ?int
    {
        return $this->defaultValidityDays;
    }

    public function getDefaultSeatLimit(): ?int
    {
        return $this->defaultSeatLimit;
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

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    private static function assertSeatLimitRules(AccessPackageTargetType $targetType, ?int $seatLimit): void
    {
        if (AccessPackageTargetType::Individual === $targetType) {
            if (null !== $seatLimit) {
                throw AccessEntitlementException::invalidInput('Individual packages cannot have a seat limit.');
            }

            return;
        }
        if (null !== $seatLimit && $seatLimit < 1) {
            throw AccessEntitlementException::invalidInput('Institution seat limit must be null or >= 1.');
        }
    }
}
