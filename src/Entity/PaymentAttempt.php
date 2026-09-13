<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderEnvironment;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\PaymentAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One provider-neutral payment attempt against an order.
 *
 * No card data ever reaches this row: only an opaque provider reference, the amount in
 * integer minor units, and the HMAC digest of the caller's idempotency key.
 */
#[ORM\Entity(repositoryClass: PaymentAttemptRepository::class)]
#[ORM\Table(name: 'payment_attempts')]
#[ORM\UniqueConstraint(name: 'uniq_pa_order_attempt_number', columns: ['order_id', 'attempt_number'])]
#[ORM\UniqueConstraint(name: 'uniq_pa_idempotency_key_hash', columns: ['idempotency_key_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_pa_provider_payment_reference', columns: ['provider_code', 'provider_payment_reference'])]
#[ORM\UniqueConstraint(name: 'uniq_pa_id_order', columns: ['id', 'order_id'])]
#[ORM\Index(name: 'idx_pa_order_status', columns: ['order_id', 'status'])]
#[ORM\Index(name: 'idx_pa_provider_env', columns: ['provider_code', 'environment'])]
class PaymentAttempt
{
    public const PROVIDER_CODE_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    public const PROVIDER_REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/';

    public const FAILURE_CODE_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CommerceOrder $order;

    #[ORM\Column(name: 'attempt_number')]
    private int $attemptNumber;

    #[ORM\Column(name: 'provider_code', length: 32)]
    private string $providerCode;

    #[ORM\Column(length: 32, enumType: PaymentProviderEnvironment::class)]
    private PaymentProviderEnvironment $environment;

    #[ORM\Column(length: 32, enumType: PaymentAttemptStatus::class)]
    private PaymentAttemptStatus $status;

    #[ORM\Column(name: 'amount_minor', type: Types::BIGINT)]
    private int $amountMinor;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(name: 'idempotency_key_hash', length: 64)]
    private string $idempotencyKeyHash;

    #[ORM\Column(name: 'provider_payment_reference', length: 128, nullable: true)]
    private ?string $providerPaymentReference = null;

    #[ORM\Column(name: 'provider_authorization_reference', length: 128, nullable: true)]
    private ?string $providerAuthorizationReference = null;

    #[ORM\Column(name: 'failure_code', length: 64, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(name: 'event_sequence')]
    private int $eventSequence;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'authorized_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $authorizedAt = null;

    #[ORM\Column(name: 'captured_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $capturedAt = null;

    #[ORM\Column(name: 'failed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    private function __construct(
        CommerceOrder $order,
        int $attemptNumber,
        string $providerCode,
        PaymentProviderEnvironment $environment,
        Money $amount,
        string $idempotencyKeyHash,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($attemptNumber < 1) {
            throw CommerceException::invalidInput('attemptNumber must be >= 1.');
        }
        if (1 !== preg_match(self::PROVIDER_CODE_PATTERN, $providerCode)) {
            throw CommerceException::invalidInput('providerCode must be snake_case (2-32 characters).');
        }
        if ($amount->getCurrency() !== $order->getCurrency()) {
            throw CommerceException::currencyMismatch();
        }
        if (!$amount->equals($order->getGrandTotal())) {
            throw CommerceException::totalMismatch();
        }
        CommerceIdempotencyKeyHasher::assertHash($idempotencyKeyHash);

        $this->id = $id ?? new UuidV7();
        $this->order = $order;
        $this->attemptNumber = $attemptNumber;
        $this->providerCode = $providerCode;
        $this->environment = $environment;
        $this->status = PaymentAttemptStatus::Initiated;
        $this->amountMinor = $amount->getAmountMinor();
        $this->currency = $amount->getCurrency();
        $this->idempotencyKeyHash = $idempotencyKeyHash;
        $this->eventSequence = 0;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer PaymentAttemptManager
     */
    public static function initiate(
        CommerceOrder $order,
        int $attemptNumber,
        string $providerCode,
        PaymentProviderEnvironment $environment,
        Money $amount,
        string $idempotencyKeyHash,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $order,
            $attemptNumber,
            $providerCode,
            $environment,
            $amount,
            $idempotencyKeyHash,
            $now,
            $id,
        );
    }

    public function markAuthorized(
        ?string $providerPaymentReference,
        ?string $providerAuthorizationReference,
        \DateTimeImmutable $authorizedAt,
    ): void {
        if (PaymentAttemptStatus::Initiated !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        $this->applyProviderReferences($providerPaymentReference, $providerAuthorizationReference);
        $this->status = PaymentAttemptStatus::Authorized;
        $this->authorizedAt = $authorizedAt;
        $this->updatedAt = $authorizedAt;
    }

    public function markCaptured(?string $providerPaymentReference, \DateTimeImmutable $capturedAt): void
    {
        if (!$this->status->allowsCapture()) {
            throw CommerceException::invalidTransition();
        }
        $this->applyProviderReferences($providerPaymentReference, null);
        $this->status = PaymentAttemptStatus::Captured;
        $this->capturedAt = $capturedAt;
        $this->updatedAt = $capturedAt;
    }

    public function markFailed(string $failureCode, \DateTimeImmutable $failedAt): void
    {
        if ($this->status->isTerminal()) {
            throw CommerceException::invalidTransition();
        }
        $this->failureCode = self::assertFailureCode($failureCode);
        $this->status = PaymentAttemptStatus::Failed;
        $this->failedAt = $failedAt;
        $this->updatedAt = $failedAt;
    }

    public function markCancelled(string $failureCode, \DateTimeImmutable $cancelledAt): void
    {
        if ($this->status->isTerminal()) {
            throw CommerceException::invalidTransition();
        }
        $this->failureCode = self::assertFailureCode($failureCode);
        $this->status = PaymentAttemptStatus::Cancelled;
        $this->cancelledAt = $cancelledAt;
        $this->updatedAt = $cancelledAt;
    }

    /**
     * Reserves the next append-only event sequence number for this attempt.
     */
    public function nextEventSequence(): int
    {
        return ++$this->eventSequence;
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
    public function getOrder(): CommerceOrder
    {
        return $this->order;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }

    public function getEnvironment(): PaymentProviderEnvironment
    {
        return $this->environment;
    }

    public function getStatus(): PaymentAttemptStatus
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

    public function getIdempotencyKeyHash(): string
    {
        return $this->idempotencyKeyHash;
    }

    public function getProviderPaymentReference(): ?string
    {
        return $this->providerPaymentReference;
    }

    public function getProviderAuthorizationReference(): ?string
    {
        return $this->providerAuthorizationReference;
    }

    public function getFailureCode(): ?string
    {
        return $this->failureCode;
    }

    public function getEventSequence(): int
    {
        return $this->eventSequence;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getAuthorizedAt(): ?\DateTimeImmutable
    {
        return $this->authorizedAt;
    }

    public function getCapturedAt(): ?\DateTimeImmutable
    {
        return $this->capturedAt;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public static function assertProviderReference(string $reference): string
    {
        if (1 !== preg_match(self::PROVIDER_REFERENCE_PATTERN, $reference)) {
            throw CommerceException::invalidInput('Provider reference must be an opaque non-PII token.');
        }

        return $reference;
    }

    public static function assertFailureCode(string $failureCode): string
    {
        $failureCode = strtolower(trim($failureCode));
        if (1 !== preg_match(self::FAILURE_CODE_PATTERN, $failureCode)) {
            throw CommerceException::invalidInput('failureCode must be snake_case.');
        }

        return $failureCode;
    }

    private function applyProviderReferences(
        ?string $providerPaymentReference,
        ?string $providerAuthorizationReference,
    ): void {
        if (null !== $providerPaymentReference) {
            $reference = self::assertProviderReference($providerPaymentReference);
            if (null !== $this->providerPaymentReference && $this->providerPaymentReference !== $reference) {
                throw CommerceException::immutable();
            }
            $this->providerPaymentReference = $reference;
        }
        if (null !== $providerAuthorizationReference) {
            $reference = self::assertProviderReference($providerAuthorizationReference);
            if (null !== $this->providerAuthorizationReference
                && $this->providerAuthorizationReference !== $reference
            ) {
                throw CommerceException::immutable();
            }
            $this->providerAuthorizationReference = $reference;
        }
    }
}
