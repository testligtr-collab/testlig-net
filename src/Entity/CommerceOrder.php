<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommerceOrderHasher;
use App\Enum\CommerceCancellationReasonCode;
use App\Enum\CommerceOrderStatus;
use App\Enum\CommercePurchaserType;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\CommerceOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Purchase intent for one purchaser (user XOR institution).
 *
 * `publicReference` is a random, unguessable receipt identifier — deliberately **not**
 * sequential and never used as a security decision input. All totals are integer minor
 * units in a single currency: grandTotal = subtotal - discount + tax (tax exclusive).
 */
#[ORM\Entity(repositoryClass: CommerceOrderRepository::class)]
#[ORM\Table(name: 'commerce_orders')]
#[ORM\UniqueConstraint(name: 'uniq_cord_public_reference', columns: ['public_reference'])]
#[ORM\UniqueConstraint(name: 'uniq_cord_id_user', columns: ['id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cord_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cord_id_currency', columns: ['id', 'currency'])]
#[ORM\Index(name: 'idx_cord_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_cord_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_cord_status_expires', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'idx_cord_created_by', columns: ['created_by_id'])]
class CommerceOrder
{
    public const PUBLIC_REFERENCE_PREFIX = 'ORD-';

    public const PUBLIC_REFERENCE_RANDOM_BYTES = 12;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'public_reference', length: 40)]
    private string $publicReference;

    #[ORM\Column(name: 'purchaser_type', length: 32, enumType: CommercePurchaserType::class)]
    private CommercePurchaserType $purchaserType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Institution $institution;

    #[ORM\Column(length: 32, enumType: CommerceOrderStatus::class)]
    private CommerceOrderStatus $status;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(name: 'subtotal_amount_minor', type: Types::BIGINT)]
    private int $subtotalAmountMinor;

    #[ORM\Column(name: 'discount_amount_minor', type: Types::BIGINT)]
    private int $discountAmountMinor;

    #[ORM\Column(name: 'tax_amount_minor', type: Types::BIGINT)]
    private int $taxAmountMinor;

    #[ORM\Column(name: 'grand_total_amount_minor', type: Types::BIGINT)]
    private int $grandTotalAmountMinor;

    #[ORM\Column(name: 'order_hash', length: 64)]
    private string $orderHash;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'payment_started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paymentStartedAt = null;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'expired_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    #[ORM\Column(name: 'failed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(
        name: 'cancellation_reason_code',
        length: 64,
        nullable: true,
        enumType: CommerceCancellationReasonCode::class,
    )]
    private ?CommerceCancellationReasonCode $cancellationReasonCode = null;

    private function __construct(
        string $publicReference,
        CommercePurchaserType $purchaserType,
        ?User $user,
        ?Institution $institution,
        string $currency,
        User $createdBy,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        int $schemaVersion,
        ?Uuid $id = null,
    ) {
        self::assertPurchaserPair($purchaserType, $user, $institution);
        self::assertPublicReference($publicReference);
        if ($expiresAt <= $now) {
            throw CommerceException::invalidInput('expiresAt must be after the creation instant.');
        }

        $this->id = $id ?? new UuidV7();
        $this->publicReference = $publicReference;
        $this->purchaserType = $purchaserType;
        $this->user = $user;
        $this->institution = $institution;
        $this->status = CommerceOrderStatus::Draft;
        $this->currency = Money::zero($currency)->getCurrency();
        $this->subtotalAmountMinor = 0;
        $this->discountAmountMinor = 0;
        $this->taxAmountMinor = 0;
        $this->grandTotalAmountMinor = 0;
        $this->orderHash = str_repeat('0', 64);
        $this->schemaVersion = $schemaVersion;
        $this->createdBy = $createdBy;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CommerceOrderManager
     */
    public static function createDraft(
        string $publicReference,
        CommercePurchaserType $purchaserType,
        ?User $user,
        ?Institution $institution,
        string $currency,
        User $createdBy,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        int $schemaVersion = CommerceOrderHasher::SCHEMA_VERSION,
        ?Uuid $id = null,
    ): self {
        return new self(
            $publicReference,
            $purchaserType,
            $user,
            $institution,
            $currency,
            $createdBy,
            $expiresAt,
            $now,
            $schemaVersion,
            $id,
        );
    }

    /**
     * Generates an unguessable public receipt reference (96 bits of randomness).
     */
    public static function generatePublicReference(): string
    {
        return self::PUBLIC_REFERENCE_PREFIX
            .strtoupper(bin2hex(random_bytes(self::PUBLIC_REFERENCE_RANDOM_BYTES)));
    }

    /**
     * Freezes the recomputed totals and canonical order hash.
     *
     * The order stays draft so its line items can still be inserted in the same flush;
     * {@see self::markPaymentStarted()} is what moves it to awaiting_payment.
     */
    public function sealTotals(
        Money $subtotal,
        Money $discount,
        Money $tax,
        Money $grandTotal,
        string $orderHash,
        \DateTimeImmutable $now,
    ): void {
        if (CommerceOrderStatus::Draft !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        foreach ([$subtotal, $discount, $tax, $grandTotal] as $amount) {
            if ($amount->getCurrency() !== $this->currency) {
                throw CommerceException::currencyMismatch();
            }
        }
        if (!$subtotal->subtract($discount)->add($tax)->equals($grandTotal)) {
            throw CommerceException::totalMismatch();
        }
        if ($grandTotal->isZero()) {
            throw CommerceException::invalidInput('Order grand total must be greater than zero.');
        }
        CommerceOrderHasher::assertHash($orderHash);

        $this->subtotalAmountMinor = $subtotal->getAmountMinor();
        $this->discountAmountMinor = $discount->getAmountMinor();
        $this->taxAmountMinor = $tax->getAmountMinor();
        $this->grandTotalAmountMinor = $grandTotal->getAmountMinor();
        $this->orderHash = $orderHash;
        $this->updatedAt = $now;
    }

    public function markPaymentStarted(\DateTimeImmutable $startedAt): void
    {
        if (CommerceOrderStatus::Draft !== $this->status
            && CommerceOrderStatus::AwaitingPayment !== $this->status
            && CommerceOrderStatus::Failed !== $this->status
        ) {
            throw CommerceException::invalidTransition();
        }
        if (0 === $this->grandTotalAmountMinor) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceOrderStatus::AwaitingPayment;
        $this->paymentStartedAt ??= $startedAt;
        $this->failedAt = null;
        $this->updatedAt = $startedAt;
    }

    public function markPaid(\DateTimeImmutable $paidAt): void
    {
        if (CommerceOrderStatus::AwaitingPayment !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceOrderStatus::Paid;
        $this->paidAt = $paidAt;
        $this->updatedAt = $paidAt;
    }

    public function markPaymentFailed(\DateTimeImmutable $failedAt): void
    {
        if (CommerceOrderStatus::AwaitingPayment !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceOrderStatus::Failed;
        $this->failedAt = $failedAt;
        $this->updatedAt = $failedAt;
    }

    public function cancel(CommerceCancellationReasonCode $reasonCode, \DateTimeImmutable $cancelledAt): void
    {
        if (!$this->status->allowsCancellation()) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceOrderStatus::Cancelled;
        $this->cancellationReasonCode = $reasonCode;
        $this->cancelledAt = $cancelledAt;
        $this->updatedAt = $cancelledAt;
    }

    public function markExpired(\DateTimeImmutable $expiredAt): void
    {
        if (!$this->status->allowsCancellation()) {
            throw CommerceException::invalidTransition();
        }
        if ($expiredAt < $this->expiresAt) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceOrderStatus::Expired;
        $this->cancellationReasonCode = CommerceCancellationReasonCode::PaymentExpired;
        $this->expiredAt = $expiredAt;
        $this->updatedAt = $expiredAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function getSubtotal(): Money
    {
        return Money::fromMinor($this->subtotalAmountMinor, $this->currency);
    }

    public function getDiscount(): Money
    {
        return Money::fromMinor($this->discountAmountMinor, $this->currency);
    }

    public function getTax(): Money
    {
        return Money::fromMinor($this->taxAmountMinor, $this->currency);
    }

    public function getGrandTotal(): Money
    {
        return Money::fromMinor($this->grandTotalAmountMinor, $this->currency);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicReference(): string
    {
        return $this->publicReference;
    }

    public function getPurchaserType(): CommercePurchaserType
    {
        return $this->purchaserType;
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

    public function getStatus(): CommerceOrderStatus
    {
        return $this->status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSubtotalAmountMinor(): int
    {
        return $this->subtotalAmountMinor;
    }

    public function getDiscountAmountMinor(): int
    {
        return $this->discountAmountMinor;
    }

    public function getTaxAmountMinor(): int
    {
        return $this->taxAmountMinor;
    }

    public function getGrandTotalAmountMinor(): int
    {
        return $this->grandTotalAmountMinor;
    }

    public function getOrderHash(): string
    {
        return $this->orderHash;
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

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPaymentStartedAt(): ?\DateTimeImmutable
    {
        return $this->paymentStartedAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getExpiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function getCancellationReasonCode(): ?CommerceCancellationReasonCode
    {
        return $this->cancellationReasonCode;
    }

    public static function assertPublicReference(string $publicReference): string
    {
        $expected = '/^'.self::PUBLIC_REFERENCE_PREFIX.'[0-9A-F]{'
            .(self::PUBLIC_REFERENCE_RANDOM_BYTES * 2).'}$/';
        if (1 !== preg_match($expected, $publicReference)) {
            throw CommerceException::invalidInput('publicReference must be a random ORD- reference.');
        }

        return $publicReference;
    }

    private static function assertPurchaserPair(
        CommercePurchaserType $purchaserType,
        ?User $user,
        ?Institution $institution,
    ): void {
        if (CommercePurchaserType::User === $purchaserType) {
            if (!$user instanceof User || null !== $institution) {
                throw CommerceException::invalidInput('User orders require user and null institution.');
            }

            return;
        }
        if (!$institution instanceof Institution || null !== $user) {
            throw CommerceException::invalidInput('Institution orders require institution and null user.');
        }
    }
}
