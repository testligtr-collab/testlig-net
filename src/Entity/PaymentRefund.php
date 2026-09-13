<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentRefundReasonCode;
use App\Enum\PaymentRefundStatus;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\PaymentRefundRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Money-movement record against a captured payment attempt.
 *
 * A refund never touches entitlements by itself: partial refunds leave the license
 * untouched, and even a full refund requires an explicit
 * CommerceFulfillmentManager::reverse() call to revoke access.
 */
#[ORM\Entity(repositoryClass: PaymentRefundRepository::class)]
#[ORM\Table(name: 'payment_refunds')]
#[ORM\UniqueConstraint(name: 'uniq_pr_attempt_refund_number', columns: ['payment_attempt_id', 'refund_number'])]
#[ORM\UniqueConstraint(name: 'uniq_pr_idempotency_key_hash', columns: ['idempotency_key_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_pr_provider_reference', columns: ['payment_attempt_id', 'provider_refund_reference'])]
#[ORM\Index(name: 'idx_pr_attempt_status', columns: ['payment_attempt_id', 'status'])]
class PaymentRefund
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'payment_attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PaymentAttempt $paymentAttempt;

    #[ORM\Column(name: 'refund_number')]
    private int $refundNumber;

    #[ORM\Column(length: 32, enumType: PaymentRefundStatus::class)]
    private PaymentRefundStatus $status;

    #[ORM\Column(name: 'amount_minor', type: Types::BIGINT)]
    private int $amountMinor;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(name: 'reason_code', length: 64, enumType: PaymentRefundReasonCode::class)]
    private PaymentRefundReasonCode $reasonCode;

    #[ORM\Column(name: 'provider_refund_reference', length: 128, nullable: true)]
    private ?string $providerRefundReference = null;

    #[ORM\Column(name: 'idempotency_key_hash', length: 64)]
    private string $idempotencyKeyHash;

    #[ORM\Column(name: 'failure_code', length: 64, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'succeeded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $succeededAt = null;

    #[ORM\Column(name: 'failed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    private function __construct(
        PaymentAttempt $paymentAttempt,
        int $refundNumber,
        Money $amount,
        PaymentRefundReasonCode $reasonCode,
        string $idempotencyKeyHash,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (PaymentAttemptStatus::Captured !== $paymentAttempt->getStatus()) {
            throw CommerceException::paymentNotCaptured();
        }
        if ($refundNumber < 1) {
            throw CommerceException::invalidInput('refundNumber must be >= 1.');
        }
        if ($amount->getCurrency() !== $paymentAttempt->getCurrency()) {
            throw CommerceException::currencyMismatch();
        }
        if ($amount->isZero()) {
            throw CommerceException::invalidInput('Refund amount must be greater than zero.');
        }
        if ($amount->isGreaterThan($paymentAttempt->getAmount())) {
            throw CommerceException::refundExceedsCapture();
        }
        CommerceIdempotencyKeyHasher::assertHash($idempotencyKeyHash);

        $this->id = $id ?? new UuidV7();
        $this->paymentAttempt = $paymentAttempt;
        $this->refundNumber = $refundNumber;
        $this->status = PaymentRefundStatus::Requested;
        $this->amountMinor = $amount->getAmountMinor();
        $this->currency = $amount->getCurrency();
        $this->reasonCode = $reasonCode;
        $this->idempotencyKeyHash = $idempotencyKeyHash;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer PaymentRefundManager
     */
    public static function request(
        PaymentAttempt $paymentAttempt,
        int $refundNumber,
        Money $amount,
        PaymentRefundReasonCode $reasonCode,
        string $idempotencyKeyHash,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $paymentAttempt,
            $refundNumber,
            $amount,
            $reasonCode,
            $idempotencyKeyHash,
            $now,
            $id,
        );
    }

    public function markSucceeded(?string $providerRefundReference, \DateTimeImmutable $succeededAt): void
    {
        if (PaymentRefundStatus::Requested !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        if (null !== $providerRefundReference) {
            $this->providerRefundReference = PaymentAttempt::assertProviderReference($providerRefundReference);
        }
        $this->status = PaymentRefundStatus::Succeeded;
        $this->succeededAt = $succeededAt;
        $this->updatedAt = $succeededAt;
    }

    public function markFailed(string $failureCode, \DateTimeImmutable $failedAt): void
    {
        if (PaymentRefundStatus::Requested !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        $this->status = PaymentRefundStatus::Failed;
        $this->failureCode = PaymentAttempt::assertFailureCode($failureCode);
        $this->failedAt = $failedAt;
        $this->updatedAt = $failedAt;
    }

    public function isFullRefundOf(PaymentAttempt $attempt): bool
    {
        return $this->getAmount()->equals($attempt->getAmount());
    }

    public function getAmount(): Money
    {
        return Money::fromMinor($this->amountMinor, $this->currency);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getPaymentAttempt(): PaymentAttempt
    {
        return $this->paymentAttempt;
    }

    public function getRefundNumber(): int
    {
        return $this->refundNumber;
    }

    public function getStatus(): PaymentRefundStatus
    {
        return $this->status;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getReasonCode(): PaymentRefundReasonCode
    {
        return $this->reasonCode;
    }

    public function getProviderRefundReference(): ?string
    {
        return $this->providerRefundReference;
    }

    public function getIdempotencyKeyHash(): string
    {
        return $this->idempotencyKeyHash;
    }

    public function getFailureCode(): ?string
    {
        return $this->failureCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getSucceededAt(): ?\DateTimeImmutable
    {
        return $this->succeededAt;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }
}
