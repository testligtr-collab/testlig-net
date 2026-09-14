<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommerceInputNormalizer;
use App\Commerce\VerifiedPaymentWebhook;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Exception\CommerceException;
use App\Repository\PaymentWebhookInboxEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only verified webhook inbox. Raw body/signature/secrets are never stored.
 */
#[ORM\Entity(repositoryClass: PaymentWebhookInboxEventRepository::class)]
#[ORM\Table(name: 'payment_webhook_inbox_events')]
#[ORM\UniqueConstraint(
    name: 'uniq_pwie_provider_env_event_ref',
    columns: ['provider_code', 'environment', 'provider_event_reference'],
)]
#[ORM\Index(name: 'idx_pwie_status_received', columns: ['processing_status', 'received_at'])]
#[ORM\Index(name: 'idx_pwie_attempt', columns: ['payment_attempt_id'])]
class PaymentWebhookInboxEvent
{
    public const SCHEMA_VERSION = 1;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'provider_code', length: 64)]
    private string $providerCode;

    #[ORM\Column(length: 16, enumType: PaymentProviderEnvironment::class)]
    private PaymentProviderEnvironment $environment;

    #[ORM\Column(name: 'provider_event_reference', length: 128)]
    private string $providerEventReference;

    #[ORM\Column(name: 'event_type', length: 32, enumType: PaymentEventType::class)]
    private PaymentEventType $eventType;

    #[ORM\Column(name: 'payload_hash', length: 64)]
    private string $payloadHash;

    #[ORM\Column(name: 'signature_fingerprint', length: 64)]
    private string $signatureFingerprint;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'provider_occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $providerOccurredAt;

    #[ORM\Column(name: 'processing_status', length: 32, enumType: PaymentWebhookInboxStatus::class)]
    private PaymentWebhookInboxStatus $processingStatus;

    #[ORM\Column(name: 'processed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'payment_attempt_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PaymentAttempt $paymentAttempt = null;

    #[ORM\Column(name: 'failure_reason_code', length: 64, nullable: true)]
    private ?string $failureReasonCode = null;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    /**
     * @var array<string, bool|int|string|null>
     */
    #[ORM\Column(name: 'sanitized_metadata', type: Types::JSON)]
    private array $sanitizedMetadata;

    /**
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     */
    private function __construct(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        string $providerEventReference,
        PaymentEventType $eventType,
        string $payloadHash,
        string $signatureFingerprint,
        \DateTimeImmutable $receivedAt,
        \DateTimeImmutable $providerOccurredAt,
        array $sanitizedMetadata,
        ?PaymentAttempt $paymentAttempt,
        ?Uuid $id = null,
    ) {
        $providerCode = CommerceInputNormalizer::providerCode($providerCode);
        PaymentAttempt::assertProviderReference($providerEventReference);
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $payloadHash)
            || 1 !== preg_match('/^[0-9a-f]{64}$/', $signatureFingerprint)
        ) {
            throw CommerceException::hashMismatch();
        }

        $this->id = $id ?? new UuidV7();
        $this->providerCode = $providerCode;
        $this->environment = $environment;
        $this->providerEventReference = $providerEventReference;
        $this->eventType = $eventType;
        $this->payloadHash = $payloadHash;
        $this->signatureFingerprint = $signatureFingerprint;
        $this->receivedAt = $receivedAt;
        $this->providerOccurredAt = $providerOccurredAt;
        $this->processingStatus = PaymentWebhookInboxStatus::Received;
        $this->paymentAttempt = $paymentAttempt;
        $this->schemaVersion = self::SCHEMA_VERSION;
        $this->sanitizedMetadata = $sanitizedMetadata;
    }

    public static function receiveVerified(
        VerifiedPaymentWebhook $verified,
        ?PaymentAttempt $paymentAttempt = null,
        ?Uuid $id = null,
    ): self {
        return new self(
            $verified->providerCode,
            $verified->environment,
            $verified->providerEventReference,
            $verified->eventType,
            $verified->payloadHash,
            $verified->signatureFingerprint,
            $verified->receivedAt,
            $verified->providerOccurredAt,
            $verified->sanitizedMetadata,
            $paymentAttempt,
            $id,
        );
    }

    public function markProcessing(\DateTimeImmutable $now): void
    {
        if (!$this->processingStatus->canStartProcessing()) {
            throw CommerceException::invalidTransition();
        }
        $this->processingStatus = PaymentWebhookInboxStatus::Processing;
        unset($now);
    }

    public function markProcessed(\DateTimeImmutable $now): void
    {
        if (PaymentWebhookInboxStatus::Processing !== $this->processingStatus) {
            throw CommerceException::invalidTransition();
        }
        $this->processingStatus = PaymentWebhookInboxStatus::Processed;
        $this->processedAt = $now;
        $this->failureReasonCode = null;
    }

    public function markRejected(string $reasonCode, \DateTimeImmutable $now): void
    {
        if (PaymentWebhookInboxStatus::Processing !== $this->processingStatus
            && PaymentWebhookInboxStatus::Received !== $this->processingStatus
        ) {
            throw CommerceException::invalidTransition();
        }
        $reasonCode = trim($reasonCode);
        if ('' === $reasonCode || \strlen($reasonCode) > 64) {
            throw CommerceException::invalidInput('failureReasonCode invalid.');
        }
        $this->processingStatus = PaymentWebhookInboxStatus::Rejected;
        $this->failureReasonCode = $reasonCode;
        $this->processedAt = $now;
    }

    public function markFailed(string $reasonCode, \DateTimeImmutable $now): void
    {
        if (PaymentWebhookInboxStatus::Processing !== $this->processingStatus) {
            throw CommerceException::invalidTransition();
        }
        $reasonCode = trim($reasonCode);
        if ('' === $reasonCode || \strlen($reasonCode) > 64) {
            throw CommerceException::invalidInput('failureReasonCode invalid.');
        }
        $this->processingStatus = PaymentWebhookInboxStatus::Failed;
        $this->failureReasonCode = $reasonCode;
        $this->processedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }

    public function getEnvironment(): PaymentProviderEnvironment
    {
        return $this->environment;
    }

    public function getProviderEventReference(): string
    {
        return $this->providerEventReference;
    }

    public function getEventType(): PaymentEventType
    {
        return $this->eventType;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    public function getSignatureFingerprint(): string
    {
        return $this->signatureFingerprint;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getProviderOccurredAt(): \DateTimeImmutable
    {
        return $this->providerOccurredAt;
    }

    public function getProcessingStatus(): PaymentWebhookInboxStatus
    {
        return $this->processingStatus;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    #[Ignore]
    public function getPaymentAttempt(): ?PaymentAttempt
    {
        return $this->paymentAttempt;
    }

    public function getFailureReasonCode(): ?string
    {
        return $this->failureReasonCode;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function getSanitizedMetadata(): array
    {
        return $this->sanitizedMetadata;
    }
}
