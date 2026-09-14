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
 *
 * Recovery fields support at-least-once provider delivery with idempotent convergence:
 * claim lease, retry_pending, and dead_letter after retry exhaustion.
 */
#[ORM\Entity(repositoryClass: PaymentWebhookInboxEventRepository::class)]
#[ORM\Table(name: 'payment_webhook_inbox_events')]
#[ORM\UniqueConstraint(
    name: 'uniq_pwie_provider_env_event_ref',
    columns: ['provider_code', 'environment', 'provider_event_reference'],
)]
#[ORM\Index(name: 'idx_pwie_status_received', columns: ['processing_status', 'received_at'])]
#[ORM\Index(name: 'idx_pwie_status_retry', columns: ['processing_status', 'next_retry_at'])]
#[ORM\Index(name: 'idx_pwie_status_lease', columns: ['processing_status', 'lease_expires_at'])]
#[ORM\Index(name: 'idx_pwie_attempt', columns: ['payment_attempt_id'])]
class PaymentWebhookInboxEvent
{
    public const SCHEMA_VERSION = 2;

    public const MAX_ATTEMPTS = 5;

    public const LEASE_SECONDS = 60;

    public const RETRY_BACKOFF_SECONDS = 5;

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

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'processing_started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processingStartedAt = null;

    #[ORM\Column(name: 'next_retry_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextRetryAt = null;

    #[ORM\Column(name: 'attempt_count', options: ['default' => 0])]
    private int $attemptCount = 0;

    #[ORM\Column(name: 'last_failure_reason_code', length: 64, nullable: true)]
    private ?string $lastFailureReasonCode = null;

    #[ORM\Column(name: 'claim_token', type: UuidType::NAME, nullable: true)]
    private ?Uuid $claimToken = null;

    #[ORM\Column(name: 'lease_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leaseExpiresAt = null;

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

    public function applyClaim(Uuid $claimToken, \DateTimeImmutable $now, int $leaseSeconds = self::LEASE_SECONDS): void
    {
        if (!$this->processingStatus->isClaimable()) {
            throw CommerceException::invalidTransition();
        }
        if (PaymentWebhookInboxStatus::RetryPending === $this->processingStatus
            && $this->nextRetryAt instanceof \DateTimeImmutable
            && $now < $this->nextRetryAt
        ) {
            throw CommerceException::conflict();
        }
        if (PaymentWebhookInboxStatus::Processing === $this->processingStatus
            && $this->leaseExpiresAt instanceof \DateTimeImmutable
            && $now < $this->leaseExpiresAt
        ) {
            throw CommerceException::conflict();
        }

        ++$this->attemptCount;
        $this->processingStatus = PaymentWebhookInboxStatus::Processing;
        $this->claimToken = $claimToken;
        $this->leaseExpiresAt = $now->modify('+'.$leaseSeconds.' seconds');
        $this->processingStartedAt ??= $now;
        $this->nextRetryAt = null;
    }

    public function markProcessed(Uuid $claimToken, \DateTimeImmutable $now): void
    {
        $this->assertActiveClaim($claimToken);
        $this->processingStatus = PaymentWebhookInboxStatus::Processed;
        $this->processedAt = $now;
        $this->closedAt = null;
        $this->failureReasonCode = null;
        $this->lastFailureReasonCode = null;
        $this->claimToken = null;
        $this->leaseExpiresAt = null;
        $this->nextRetryAt = null;
    }

    public function markRejected(string $reasonCode, \DateTimeImmutable $now, ?Uuid $claimToken = null): void
    {
        if (null !== $claimToken) {
            $this->assertActiveClaim($claimToken);
        } elseif (!\in_array($this->processingStatus, [
            PaymentWebhookInboxStatus::Received,
            PaymentWebhookInboxStatus::Processing,
            PaymentWebhookInboxStatus::RetryPending,
        ], true)) {
            throw CommerceException::invalidTransition();
        }

        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $this->processingStatus = PaymentWebhookInboxStatus::Rejected;
        $this->failureReasonCode = $reasonCode;
        $this->lastFailureReasonCode = $reasonCode;
        $this->closedAt = $now;
        $this->processedAt = null;
        $this->claimToken = null;
        $this->leaseExpiresAt = null;
        $this->nextRetryAt = null;
    }

    public function scheduleRetry(string $reasonCode, \DateTimeImmutable $now, Uuid $claimToken): void
    {
        $this->assertActiveClaim($claimToken);
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        if ($this->attemptCount >= self::MAX_ATTEMPTS) {
            $this->markDeadLetter($reasonCode, $now, $claimToken);

            return;
        }

        $delay = self::RETRY_BACKOFF_SECONDS * max(1, $this->attemptCount);
        $this->processingStatus = PaymentWebhookInboxStatus::RetryPending;
        $this->lastFailureReasonCode = $reasonCode;
        $this->nextRetryAt = $now->modify('+'.$delay.' seconds');
        $this->claimToken = null;
        $this->leaseExpiresAt = null;
        $this->processedAt = null;
        $this->closedAt = null;
        $this->failureReasonCode = null;
    }

    public function markDeadLetter(string $reasonCode, \DateTimeImmutable $now, ?Uuid $claimToken = null): void
    {
        if (null !== $claimToken) {
            $this->assertActiveClaim($claimToken);
        } elseif (PaymentWebhookInboxStatus::Processing !== $this->processingStatus
            && PaymentWebhookInboxStatus::RetryPending !== $this->processingStatus
        ) {
            throw CommerceException::invalidTransition();
        }

        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $this->processingStatus = PaymentWebhookInboxStatus::DeadLetter;
        $this->failureReasonCode = $reasonCode;
        $this->lastFailureReasonCode = $reasonCode;
        $this->closedAt = $now;
        $this->processedAt = null;
        $this->claimToken = null;
        $this->leaseExpiresAt = null;
        $this->nextRetryAt = null;
    }

    private function assertActiveClaim(Uuid $claimToken): void
    {
        if (PaymentWebhookInboxStatus::Processing !== $this->processingStatus
            || !$this->claimToken instanceof Uuid
            || !$this->claimToken->equals($claimToken)
        ) {
            throw CommerceException::conflict();
        }
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = strtolower(trim($reasonCode));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw CommerceException::invalidInput('failureReasonCode invalid.');
        }

        return $reasonCode;
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

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getProcessingStartedAt(): ?\DateTimeImmutable
    {
        return $this->processingStartedAt;
    }

    public function getNextRetryAt(): ?\DateTimeImmutable
    {
        return $this->nextRetryAt;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getLastFailureReasonCode(): ?string
    {
        return $this->lastFailureReasonCode;
    }

    public function getClaimToken(): ?Uuid
    {
        return $this->claimToken;
    }

    public function getLeaseExpiresAt(): ?\DateTimeImmutable
    {
        return $this->leaseExpiresAt;
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
