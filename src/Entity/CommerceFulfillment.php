<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Enum\CommerceFulfillmentStatus;
use App\Enum\PaymentAttemptStatus;
use App\Exception\CommerceException;
use App\Repository\CommerceFulfillmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Bridge row proving "this captured payment produced exactly this AccessLicense".
 *
 * One-time items: at most one `completed` fulfillment per order item (DB generated-column
 * unique). Recurring items: one `completed` fulfillment per (subscription, periodKey), so a
 * replayed renewal cannot mint a second license for the same billing period.
 */
#[ORM\Entity(repositoryClass: CommerceFulfillmentRepository::class)]
#[ORM\Table(name: 'commerce_fulfillments')]
#[ORM\UniqueConstraint(name: 'uniq_cf_order_number', columns: ['order_id', 'fulfillment_number'])]
#[ORM\UniqueConstraint(name: 'uniq_cf_idempotency_key_hash', columns: ['idempotency_key_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_cf_license', columns: ['access_license_id'])]
#[ORM\Index(name: 'idx_cf_order_status', columns: ['order_id', 'status'])]
#[ORM\Index(name: 'idx_cf_order_item', columns: ['order_item_id'])]
#[ORM\Index(name: 'idx_cf_attempt', columns: ['payment_attempt_id'])]
#[ORM\Index(name: 'idx_cf_subscription_period', columns: ['subscription_id', 'period_key'])]
class CommerceFulfillment
{
    public const PERIOD_KEY_PATTERN = '/^[0-9]{8}$/';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CommerceOrder $order;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'order_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CommerceOrderItem $orderItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'payment_attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PaymentAttempt $paymentAttempt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subscription_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?CommerceSubscription $subscription;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'access_license_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?AccessLicense $accessLicense = null;

    #[ORM\Column(name: 'fulfillment_number')]
    private int $fulfillmentNumber;

    #[ORM\Column(length: 32, enumType: CommerceFulfillmentStatus::class)]
    private CommerceFulfillmentStatus $status;

    #[ORM\Column(name: 'idempotency_key_hash', length: 64)]
    private string $idempotencyKeyHash;

    #[ORM\Column(name: 'period_key', length: 8, nullable: true)]
    private ?string $periodKey;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'fulfilled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fulfilledAt = null;

    #[ORM\Column(name: 'reversed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reversedAt = null;

    #[ORM\Column(name: 'failure_code', length: 64, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(name: 'reversal_reason_code', length: 64, nullable: true)]
    private ?string $reversalReasonCode = null;

    private function __construct(
        CommerceOrder $order,
        CommerceOrderItem $orderItem,
        PaymentAttempt $paymentAttempt,
        ?CommerceSubscription $subscription,
        int $fulfillmentNumber,
        string $idempotencyKeyHash,
        ?string $periodKey,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (!$orderItem->getOrder()->getId()->equals($order->getId())) {
            throw CommerceException::scopeMismatch('Fulfillment order item must belong to the order.');
        }
        if (!$paymentAttempt->getOrder()->getId()->equals($order->getId())) {
            throw CommerceException::scopeMismatch('Fulfillment payment attempt must belong to the order.');
        }
        if (PaymentAttemptStatus::Captured !== $paymentAttempt->getStatus()) {
            throw CommerceException::paymentNotCaptured();
        }
        if ($subscription instanceof CommerceSubscription) {
            if (!$subscription->getOrder()->getId()->equals($order->getId())) {
                throw CommerceException::scopeMismatch('Fulfillment subscription must belong to the order.');
            }
            if (null === $periodKey) {
                throw CommerceException::invalidInput('Recurring fulfillment requires a period key.');
            }
        } elseif (null !== $periodKey) {
            throw CommerceException::invalidInput('One-time fulfillment must not have a period key.');
        }
        if (null !== $periodKey && 1 !== preg_match(self::PERIOD_KEY_PATTERN, $periodKey)) {
            throw CommerceException::invalidInput('periodKey must be an 8 digit UTC day key.');
        }
        if ($fulfillmentNumber < 1) {
            throw CommerceException::invalidInput('fulfillmentNumber must be >= 1.');
        }
        CommerceIdempotencyKeyHasher::assertHash($idempotencyKeyHash);

        $this->id = $id ?? new UuidV7();
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->paymentAttempt = $paymentAttempt;
        $this->subscription = $subscription;
        $this->fulfillmentNumber = $fulfillmentNumber;
        $this->status = CommerceFulfillmentStatus::Pending;
        $this->idempotencyKeyHash = $idempotencyKeyHash;
        $this->periodKey = $periodKey;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CommerceFulfillmentManager
     */
    public static function createPending(
        CommerceOrder $order,
        CommerceOrderItem $orderItem,
        PaymentAttempt $paymentAttempt,
        ?CommerceSubscription $subscription,
        int $fulfillmentNumber,
        string $idempotencyKeyHash,
        ?string $periodKey,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $order,
            $orderItem,
            $paymentAttempt,
            $subscription,
            $fulfillmentNumber,
            $idempotencyKeyHash,
            $periodKey,
            $now,
            $id,
        );
    }

    public function complete(AccessLicense $license, \DateTimeImmutable $fulfilledAt): void
    {
        if (CommerceFulfillmentStatus::Pending !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        $this->accessLicense = $license;
        $this->status = CommerceFulfillmentStatus::Completed;
        $this->fulfilledAt = $fulfilledAt;
        $this->updatedAt = $fulfilledAt;
    }

    public function fail(string $failureCode, \DateTimeImmutable $failedAt): void
    {
        if (CommerceFulfillmentStatus::Pending !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceFulfillmentStatus::Failed;
        $this->failureCode = PaymentAttempt::assertFailureCode($failureCode);
        $this->updatedAt = $failedAt;
    }

    /**
     * Explicit operator action — the license revoke happens in the same transaction
     * (see CommerceFulfillmentManager::reverse). Refunds never reverse implicitly.
     */
    public function reverse(string $reversalReasonCode, \DateTimeImmutable $reversedAt): void
    {
        if (!$this->status->allowsReversal()) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceFulfillmentStatus::Reversed;
        $this->reversalReasonCode = PaymentAttempt::assertFailureCode($reversalReasonCode);
        $this->reversedAt = $reversedAt;
        $this->updatedAt = $reversedAt;
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
    public function getOrderItem(): CommerceOrderItem
    {
        return $this->orderItem;
    }

    #[Ignore]
    public function getPaymentAttempt(): PaymentAttempt
    {
        return $this->paymentAttempt;
    }

    #[Ignore]
    public function getSubscription(): ?CommerceSubscription
    {
        return $this->subscription;
    }

    #[Ignore]
    public function getAccessLicense(): ?AccessLicense
    {
        return $this->accessLicense;
    }

    public function getFulfillmentNumber(): int
    {
        return $this->fulfillmentNumber;
    }

    public function getStatus(): CommerceFulfillmentStatus
    {
        return $this->status;
    }

    public function getIdempotencyKeyHash(): string
    {
        return $this->idempotencyKeyHash;
    }

    public function getPeriodKey(): ?string
    {
        return $this->periodKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFulfilledAt(): ?\DateTimeImmutable
    {
        return $this->fulfilledAt;
    }

    public function getReversedAt(): ?\DateTimeImmutable
    {
        return $this->reversedAt;
    }

    public function getFailureCode(): ?string
    {
        return $this->failureCode;
    }

    public function getReversalReasonCode(): ?string
    {
        return $this->reversalReasonCode;
    }
}
