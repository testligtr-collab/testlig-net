<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Commerce\PaymentEventHasher;
use App\Enum\PaymentEventType;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\PaymentEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only settlement event for a payment attempt.
 *
 * UPDATE and DELETE are denied at the trigger layer. Each row chains to the previous
 * event of the same attempt via `previousEventHash`, and `sanitizedMetadata` only ever
 * holds allowlisted non-PII scalars (see PaymentEventMetadataSanitizer).
 */
#[ORM\Entity(repositoryClass: PaymentEventRepository::class)]
#[ORM\Table(name: 'payment_events')]
#[ORM\UniqueConstraint(name: 'uniq_pe_attempt_sequence', columns: ['attempt_id', 'sequence_number'])]
#[ORM\UniqueConstraint(name: 'uniq_pe_idempotency_key_hash', columns: ['idempotency_key_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_pe_event_hash', columns: ['event_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_pe_provider_event_reference', columns: ['attempt_id', 'provider_event_reference'])]
#[ORM\Index(name: 'idx_pe_attempt_type', columns: ['attempt_id', 'event_type'])]
#[ORM\Index(name: 'idx_pe_occurred_at', columns: ['occurred_at'])]
class PaymentEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PaymentAttempt $attempt;

    #[ORM\Column(name: 'sequence_number')]
    private int $sequenceNumber;

    #[ORM\Column(name: 'event_type', length: 32, enumType: PaymentEventType::class)]
    private PaymentEventType $eventType;

    #[ORM\Column(name: 'provider_event_reference', length: 128, nullable: true)]
    private ?string $providerEventReference;

    #[ORM\Column(name: 'idempotency_key_hash', length: 64)]
    private string $idempotencyKeyHash;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'amount_minor', type: Types::BIGINT, nullable: true)]
    private ?int $amountMinor;

    #[ORM\Column(length: 3, nullable: true, options: ['fixed' => true])]
    private ?string $currency;

    /**
     * @var array<string, bool|int|string|null>
     */
    #[ORM\Column(name: 'sanitized_metadata', type: Types::JSON)]
    private array $sanitizedMetadata;

    #[ORM\Column(name: 'event_hash', length: 64)]
    private string $eventHash;

    #[ORM\Column(name: 'previous_event_hash', length: 64, nullable: true)]
    private ?string $previousEventHash;

    /**
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     */
    private function __construct(
        PaymentAttempt $attempt,
        int $sequenceNumber,
        PaymentEventType $eventType,
        ?string $providerEventReference,
        string $idempotencyKeyHash,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $receivedAt,
        ?Money $amount,
        array $sanitizedMetadata,
        string $eventHash,
        ?string $previousEventHash,
        ?Uuid $id = null,
    ) {
        if ($sequenceNumber < 1) {
            throw CommerceException::invalidInput('sequenceNumber must be >= 1.');
        }
        if ($eventType->requiresAmount() && !$amount instanceof Money) {
            throw CommerceException::invalidInput(\sprintf('%s events require an amount.', $eventType->value));
        }
        if ($amount instanceof Money && $amount->getCurrency() !== $attempt->getCurrency()) {
            throw CommerceException::currencyMismatch();
        }
        if (null !== $providerEventReference) {
            PaymentAttempt::assertProviderReference($providerEventReference);
        }
        CommerceIdempotencyKeyHasher::assertHash($idempotencyKeyHash);
        PaymentEventHasher::assertHash($eventHash);
        if (null !== $previousEventHash) {
            PaymentEventHasher::assertHash($previousEventHash);
        }
        if ($receivedAt < $occurredAt) {
            throw CommerceException::invalidInput('receivedAt must not precede occurredAt.');
        }

        $this->id = $id ?? new UuidV7();
        $this->attempt = $attempt;
        $this->sequenceNumber = $sequenceNumber;
        $this->eventType = $eventType;
        $this->providerEventReference = $providerEventReference;
        $this->idempotencyKeyHash = $idempotencyKeyHash;
        $this->occurredAt = $occurredAt;
        $this->receivedAt = $receivedAt;
        $this->amountMinor = $amount?->getAmountMinor();
        $this->currency = $amount?->getCurrency();
        $this->sanitizedMetadata = $sanitizedMetadata;
        $this->eventHash = $eventHash;
        $this->previousEventHash = $previousEventHash;
    }

    /**
     * @internal prefer PaymentSettlementManager
     *
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     */
    public static function append(
        PaymentAttempt $attempt,
        int $sequenceNumber,
        PaymentEventType $eventType,
        ?string $providerEventReference,
        string $idempotencyKeyHash,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $receivedAt,
        ?Money $amount,
        array $sanitizedMetadata,
        string $eventHash,
        ?string $previousEventHash,
        ?Uuid $id = null,
    ): self {
        return new self(
            $attempt,
            $sequenceNumber,
            $eventType,
            $providerEventReference,
            $idempotencyKeyHash,
            $occurredAt,
            $receivedAt,
            $amount,
            $sanitizedMetadata,
            $eventHash,
            $previousEventHash,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getAttempt(): PaymentAttempt
    {
        return $this->attempt;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    public function getEventType(): PaymentEventType
    {
        return $this->eventType;
    }

    public function getProviderEventReference(): ?string
    {
        return $this->providerEventReference;
    }

    public function getIdempotencyKeyHash(): string
    {
        return $this->idempotencyKeyHash;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getAmountMinor(): ?int
    {
        return $this->amountMinor;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getAmount(): ?Money
    {
        if (null === $this->amountMinor || null === $this->currency) {
            return null;
        }

        return Money::fromMinor($this->amountMinor, $this->currency);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function getSanitizedMetadata(): array
    {
        return $this->sanitizedMetadata;
    }

    public function getEventHash(): string
    {
        return $this->eventHash;
    }

    public function getPreviousEventHash(): ?string
    {
        return $this->previousEventHash;
    }
}
