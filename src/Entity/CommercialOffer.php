<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommercialOfferHasher;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\CommercialOfferStatus;
use App\Enum\CommercialOfferTargetType;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\CommercialOfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Sellable price/terms wrapper around one access package version.
 *
 * `code` is immutable after creation (DB trigger enforced). Prices are **tax exclusive**
 * integer minor units; `taxRateBasisPoints` snapshots the rate an order freezes.
 * `validUntil` is an exclusive upper bound: validFrom <= now < validUntil.
 */
#[ORM\Entity(repositoryClass: CommercialOfferRepository::class)]
#[ORM\Table(name: 'commercial_offers')]
#[ORM\UniqueConstraint(name: 'uniq_co_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_co_id_package', columns: ['id', 'package_id'])]
#[ORM\UniqueConstraint(name: 'uniq_co_id_package_version', columns: ['id', 'package_version_id'])]
#[ORM\UniqueConstraint(name: 'uniq_co_id_currency', columns: ['id', 'currency'])]
#[ORM\Index(name: 'idx_co_status_target', columns: ['status', 'target_type'])]
#[ORM\Index(name: 'idx_co_package_status', columns: ['package_id', 'status'])]
#[ORM\Index(name: 'idx_co_valid_range', columns: ['valid_from', 'valid_until'])]
#[ORM\Index(name: 'idx_co_created_by', columns: ['created_by_id'])]
class CommercialOffer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AccessPackage $package;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AccessPackageVersion $packageVersion;

    #[ORM\Column(name: 'target_type', length: 32, enumType: CommercialOfferTargetType::class)]
    private CommercialOfferTargetType $targetType;

    #[ORM\Column(name: 'billing_type', length: 32, enumType: CommercialOfferBillingType::class)]
    private CommercialOfferBillingType $billingType;

    #[ORM\Column(name: 'billing_interval', length: 32, nullable: true, enumType: CommercialOfferBillingInterval::class)]
    private ?CommercialOfferBillingInterval $billingInterval;

    #[ORM\Column(name: 'price_amount_minor', type: Types::BIGINT)]
    private int $priceAmountMinor;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(name: 'tax_rate_basis_points')]
    private int $taxRateBasisPoints;

    #[ORM\Column(length: 32, enumType: CommercialOfferStatus::class)]
    private CommercialOfferStatus $status;

    #[ORM\Column(name: 'valid_from', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_until', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil;

    #[ORM\Column(name: 'offer_hash', length: 64)]
    private string $offerHash;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'activated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $activatedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'retired_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $retiredBy = null;

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
        ?string $description,
        AccessPackage $package,
        AccessPackageVersion $packageVersion,
        CommercialOfferTargetType $targetType,
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
        Money $price,
        int $taxRateBasisPoints,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
        string $offerHash,
        User $createdBy,
        \DateTimeImmutable $now,
        int $schemaVersion,
        ?Uuid $id = null,
    ) {
        if (!$packageVersion->getPackage()->getId()->equals($package->getId())) {
            throw CommerceException::scopeMismatch('Offer package version must belong to the offer package.');
        }
        if (!$targetType->matchesPackageTarget($package->getTargetType())) {
            throw CommerceException::scopeMismatch('Offer target type must match the package target type.');
        }
        self::assertBillingPair($billingType, $billingInterval);
        self::assertValidityWindow($validFrom, $validUntil);
        if ($taxRateBasisPoints < 0 || $taxRateBasisPoints > 10000) {
            throw CommerceException::invalidInput('taxRateBasisPoints must be between 0 and 10000.');
        }
        if ($price->isZero()) {
            throw CommerceException::invalidInput('Offer price must be greater than zero.');
        }
        CommercialOfferHasher::assertHash($offerHash);

        $this->id = $id ?? new UuidV7();
        $this->code = $code;
        $this->name = $name;
        $this->description = $description;
        $this->package = $package;
        $this->packageVersion = $packageVersion;
        $this->targetType = $targetType;
        $this->billingType = $billingType;
        $this->billingInterval = $billingInterval;
        $this->priceAmountMinor = $price->getAmountMinor();
        $this->currency = $price->getCurrency();
        $this->taxRateBasisPoints = $taxRateBasisPoints;
        $this->status = CommercialOfferStatus::Draft;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->offerHash = $offerHash;
        $this->schemaVersion = $schemaVersion;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CommercialOfferManager
     */
    public static function createDraft(
        string $code,
        string $name,
        ?string $description,
        AccessPackage $package,
        AccessPackageVersion $packageVersion,
        CommercialOfferTargetType $targetType,
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
        Money $price,
        int $taxRateBasisPoints,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
        string $offerHash,
        User $createdBy,
        \DateTimeImmutable $now,
        int $schemaVersion = CommercialOfferHasher::SCHEMA_VERSION,
        ?Uuid $id = null,
    ): self {
        return new self(
            $code,
            $name,
            $description,
            $package,
            $packageVersion,
            $targetType,
            $billingType,
            $billingInterval,
            $price,
            $taxRateBasisPoints,
            $validFrom,
            $validUntil,
            $offerHash,
            $createdBy,
            $now,
            $schemaVersion,
            $id,
        );
    }

    public function updateDraft(
        string $name,
        ?string $description,
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
        Money $price,
        int $taxRateBasisPoints,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
        string $offerHash,
        \DateTimeImmutable $now,
    ): void {
        if (!$this->status->allowsDraftMutation()) {
            throw CommerceException::invalidTransition();
        }
        self::assertBillingPair($billingType, $billingInterval);
        self::assertValidityWindow($validFrom, $validUntil);
        if ($taxRateBasisPoints < 0 || $taxRateBasisPoints > 10000) {
            throw CommerceException::invalidInput('taxRateBasisPoints must be between 0 and 10000.');
        }
        if ($price->isZero()) {
            throw CommerceException::invalidInput('Offer price must be greater than zero.');
        }
        if ($price->getCurrency() !== $this->currency) {
            throw CommerceException::currencyMismatch();
        }
        CommercialOfferHasher::assertHash($offerHash);

        $this->name = $name;
        $this->description = $description;
        $this->billingType = $billingType;
        $this->billingInterval = $billingInterval;
        $this->priceAmountMinor = $price->getAmountMinor();
        $this->taxRateBasisPoints = $taxRateBasisPoints;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->offerHash = $offerHash;
        $this->updatedAt = $now;
    }

    public function activate(User $actor, \DateTimeImmutable $activatedAt): void
    {
        if (!$this->status->allowsActivation()) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommercialOfferStatus::Active;
        $this->activatedBy = $actor;
        $this->activatedAt = $activatedAt;
        $this->updatedAt = $activatedAt;
    }

    public function retire(User $actor, \DateTimeImmutable $retiredAt): void
    {
        if (!$this->status->allowsRetirement()) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommercialOfferStatus::Retired;
        $this->retiredBy = $actor;
        $this->retiredAt = $retiredAt;
        $this->updatedAt = $retiredAt;
    }

    public function isPurchasableAt(\DateTimeImmutable $now): bool
    {
        if (!$this->status->allowsNewOrder()) {
            return false;
        }
        if (null !== $this->validFrom && $now < $this->validFrom) {
            return false;
        }

        return null === $this->validUntil || $now < $this->validUntil;
    }

    public function getPrice(): Money
    {
        return Money::fromMinor($this->priceAmountMinor, $this->currency);
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

    public function getDescription(): ?string
    {
        return $this->description;
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

    public function getTargetType(): CommercialOfferTargetType
    {
        return $this->targetType;
    }

    public function getBillingType(): CommercialOfferBillingType
    {
        return $this->billingType;
    }

    public function getBillingInterval(): ?CommercialOfferBillingInterval
    {
        return $this->billingInterval;
    }

    public function getPriceAmountMinor(): int
    {
        return $this->priceAmountMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTaxRateBasisPoints(): int
    {
        return $this->taxRateBasisPoints;
    }

    public function getStatus(): CommercialOfferStatus
    {
        return $this->status;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getOfferHash(): string
    {
        return $this->offerHash;
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

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    private static function assertBillingPair(
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
    ): void {
        if ($billingType->requiresBillingInterval()) {
            if (!$billingInterval instanceof CommercialOfferBillingInterval) {
                throw CommerceException::invalidInput('Recurring offers require a billing interval.');
            }

            return;
        }
        if (null !== $billingInterval) {
            throw CommerceException::invalidInput('One-time offers must not have a billing interval.');
        }
    }

    private static function assertValidityWindow(
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
    ): void {
        if (null !== $validFrom && null !== $validUntil && $validUntil <= $validFrom) {
            throw CommerceException::invalidInput('validUntil must be after validFrom.');
        }
    }
}
