<?php

declare(strict_types=1);

namespace App\Entity;

use App\Access\AccessPackagePolicyHasher;
use App\Commerce\CommerceMoneyPolicy;
use App\Commerce\CommercialOfferHasher;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\CommerceOrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable priced line inside an order.
 *
 * Freezes the offer terms (`offerSnapshotHash`), the package-version policy graph
 * (`packagePolicySnapshotHash`), and the tax-exclusive money breakdown, so fulfillment
 * can prove nothing drifted between checkout and settlement.
 */
#[ORM\Entity(repositoryClass: CommerceOrderItemRepository::class)]
#[ORM\Table(name: 'commerce_order_items')]
#[ORM\UniqueConstraint(name: 'uniq_coi_order_offer', columns: ['order_id', 'offer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_coi_id_order', columns: ['id', 'order_id'])]
#[ORM\Index(name: 'idx_coi_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_coi_offer', columns: ['offer_id'])]
#[ORM\Index(name: 'idx_coi_package_version', columns: ['package_version_id'])]
class CommerceOrderItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CommerceOrder $order;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'offer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialOffer $offer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AccessPackage $package;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AccessPackageVersion $packageVersion;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(name: 'billing_type', length: 32, enumType: CommercialOfferBillingType::class)]
    private CommercialOfferBillingType $billingType;

    #[ORM\Column(name: 'billing_interval', length: 32, nullable: true, enumType: CommercialOfferBillingInterval::class)]
    private ?CommercialOfferBillingInterval $billingInterval;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(name: 'unit_price_amount_minor', type: Types::BIGINT)]
    private int $unitPriceAmountMinor;

    #[ORM\Column(name: 'unit_tax_amount_minor', type: Types::BIGINT)]
    private int $unitTaxAmountMinor;

    #[ORM\Column(name: 'tax_rate_basis_points')]
    private int $taxRateBasisPoints;

    #[ORM\Column(name: 'line_subtotal_amount_minor', type: Types::BIGINT)]
    private int $lineSubtotalAmountMinor;

    #[ORM\Column(name: 'line_tax_amount_minor', type: Types::BIGINT)]
    private int $lineTaxAmountMinor;

    #[ORM\Column(name: 'line_total_amount_minor', type: Types::BIGINT)]
    private int $lineTotalAmountMinor;

    #[ORM\Column(name: 'offer_snapshot_hash', length: 64)]
    private string $offerSnapshotHash;

    #[ORM\Column(name: 'package_policy_snapshot_hash', length: 64)]
    private string $packagePolicySnapshotHash;

    #[ORM\Column(name: 'description_snapshot', length: 200)]
    private string $descriptionSnapshot;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        CommerceOrder $order,
        CommercialOffer $offer,
        int $quantity,
        Money $unitPrice,
        Money $unitTax,
        int $taxRateBasisPoints,
        Money $lineSubtotal,
        Money $lineTax,
        Money $lineTotal,
        string $descriptionSnapshot,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($quantity < 1 || $quantity > CommerceMoneyPolicy::MAX_QUANTITY) {
            throw CommerceException::invalidInput(\sprintf(
                'quantity must be between 1 and %d.',
                CommerceMoneyPolicy::MAX_QUANTITY,
            ));
        }
        if ($offer->getCurrency() !== $order->getCurrency()) {
            throw CommerceException::currencyMismatch();
        }
        foreach ([$unitPrice, $unitTax, $lineSubtotal, $lineTax, $lineTotal] as $amount) {
            if ($amount->getCurrency() !== $order->getCurrency()) {
                throw CommerceException::currencyMismatch();
            }
        }
        if (!$unitPrice->equals($offer->getPrice())) {
            throw CommerceException::totalMismatch();
        }
        if (!$unitPrice->multiply($quantity)->equals($lineSubtotal)) {
            throw CommerceException::totalMismatch();
        }
        if (!$lineSubtotal->add($lineTax)->equals($lineTotal)) {
            throw CommerceException::totalMismatch();
        }
        if ($taxRateBasisPoints !== $offer->getTaxRateBasisPoints()) {
            throw CommerceException::totalMismatch();
        }
        if (!$lineSubtotal->percentageOfBasisPoints($taxRateBasisPoints)->equals($lineTax)) {
            throw CommerceException::totalMismatch();
        }
        CommercialOfferHasher::assertHash($offer->getOfferHash());
        $descriptionSnapshot = trim($descriptionSnapshot);
        if ('' === $descriptionSnapshot || mb_strlen($descriptionSnapshot) > 200) {
            throw CommerceException::invalidInput('descriptionSnapshot must be 1-200 characters.');
        }

        $this->id = $id ?? new UuidV7();
        $this->order = $order;
        $this->offer = $offer;
        $this->package = $offer->getPackage();
        $this->packageVersion = $offer->getPackageVersion();
        $this->quantity = $quantity;
        $this->billingType = $offer->getBillingType();
        $this->billingInterval = $offer->getBillingInterval();
        $this->currency = $order->getCurrency();
        $this->unitPriceAmountMinor = $unitPrice->getAmountMinor();
        $this->unitTaxAmountMinor = $unitTax->getAmountMinor();
        $this->taxRateBasisPoints = $taxRateBasisPoints;
        $this->lineSubtotalAmountMinor = $lineSubtotal->getAmountMinor();
        $this->lineTaxAmountMinor = $lineTax->getAmountMinor();
        $this->lineTotalAmountMinor = $lineTotal->getAmountMinor();
        $this->offerSnapshotHash = $offer->getOfferHash();
        $this->packagePolicySnapshotHash = self::assertPolicyHash($offer->getPackageVersion()->getPolicyHash());
        $this->descriptionSnapshot = $descriptionSnapshot;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer CommerceOrderManager
     */
    public static function create(
        CommerceOrder $order,
        CommercialOffer $offer,
        int $quantity,
        Money $unitPrice,
        Money $unitTax,
        int $taxRateBasisPoints,
        Money $lineSubtotal,
        Money $lineTax,
        Money $lineTotal,
        string $descriptionSnapshot,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $order,
            $offer,
            $quantity,
            $unitPrice,
            $unitTax,
            $taxRateBasisPoints,
            $lineSubtotal,
            $lineTax,
            $lineTotal,
            $descriptionSnapshot,
            $now,
            $id,
        );
    }

    public function getUnitPrice(): Money
    {
        return Money::fromMinor($this->unitPriceAmountMinor, $this->currency);
    }

    public function getLineSubtotal(): Money
    {
        return Money::fromMinor($this->lineSubtotalAmountMinor, $this->currency);
    }

    public function getLineTax(): Money
    {
        return Money::fromMinor($this->lineTaxAmountMinor, $this->currency);
    }

    public function getLineTotal(): Money
    {
        return Money::fromMinor($this->lineTotalAmountMinor, $this->currency);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getOrder(): CommerceOrder
    {
        return $this->order;
    }

    #[Ignore]
    public function getOffer(): CommercialOffer
    {
        return $this->offer;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getBillingType(): CommercialOfferBillingType
    {
        return $this->billingType;
    }

    public function getBillingInterval(): ?CommercialOfferBillingInterval
    {
        return $this->billingInterval;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getUnitPriceAmountMinor(): int
    {
        return $this->unitPriceAmountMinor;
    }

    public function getUnitTaxAmountMinor(): int
    {
        return $this->unitTaxAmountMinor;
    }

    public function getTaxRateBasisPoints(): int
    {
        return $this->taxRateBasisPoints;
    }

    public function getLineSubtotalAmountMinor(): int
    {
        return $this->lineSubtotalAmountMinor;
    }

    public function getLineTaxAmountMinor(): int
    {
        return $this->lineTaxAmountMinor;
    }

    public function getLineTotalAmountMinor(): int
    {
        return $this->lineTotalAmountMinor;
    }

    public function getOfferSnapshotHash(): string
    {
        return $this->offerSnapshotHash;
    }

    public function getPackagePolicySnapshotHash(): string
    {
        return $this->packagePolicySnapshotHash;
    }

    public function getDescriptionSnapshot(): string
    {
        return $this->descriptionSnapshot;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array{
     *     offerId: string,
     *     packageId: string,
     *     packageVersionId: string,
     *     quantity: int,
     *     unitPriceAmountMinor: int,
     *     taxRateBasisPoints: int,
     *     lineSubtotalAmountMinor: int,
     *     lineTaxAmountMinor: int,
     *     lineTotalAmountMinor: int,
     *     offerSnapshotHash: string,
     *     packagePolicySnapshotHash: string
     * }
     */
    public function toHashPayload(): array
    {
        return [
            'offerId' => $this->offer->getId()->toRfc4122(),
            'packageId' => $this->package->getId()->toRfc4122(),
            'packageVersionId' => $this->packageVersion->getId()->toRfc4122(),
            'quantity' => $this->quantity,
            'unitPriceAmountMinor' => $this->unitPriceAmountMinor,
            'taxRateBasisPoints' => $this->taxRateBasisPoints,
            'lineSubtotalAmountMinor' => $this->lineSubtotalAmountMinor,
            'lineTaxAmountMinor' => $this->lineTaxAmountMinor,
            'lineTotalAmountMinor' => $this->lineTotalAmountMinor,
            'offerSnapshotHash' => $this->offerSnapshotHash,
            'packagePolicySnapshotHash' => $this->packagePolicySnapshotHash,
        ];
    }

    private static function assertPolicyHash(string $policyHash): string
    {
        if (1 !== preg_match('/^[0-9a-f]{'.AccessPackagePolicyHasher::HASH_HEX_LENGTH.'}$/', $policyHash)) {
            throw CommerceException::invalidInput('packagePolicySnapshotHash must be 64 lowercase hex characters.');
        }

        return $policyHash;
    }
}
