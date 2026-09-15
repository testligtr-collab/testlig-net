<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommerceInputNormalizer;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentReconciliationMode;
use App\Enum\PaymentReconciliationRunStatus;
use App\Exception\CommerceException;
use App\Repository\PaymentReconciliationRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only reconciliation run. Only one finalize UPDATE is allowed (running → terminal).
 */
#[ORM\Entity(repositoryClass: PaymentReconciliationRunRepository::class)]
#[ORM\Table(name: 'payment_reconciliation_runs')]
#[ORM\Index(name: 'idx_prr_provider_env_started', columns: ['provider_code', 'environment', 'started_at'])]
#[ORM\Index(name: 'idx_prr_status_started', columns: ['status', 'started_at'])]
class PaymentReconciliationRun
{
    public const SCHEMA_VERSION = 1;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'provider_code', length: 64)]
    private string $providerCode;

    #[ORM\Column(length: 16, enumType: PaymentProviderEnvironment::class)]
    private PaymentProviderEnvironment $environment;

    #[ORM\Column(length: 16, enumType: PaymentReconciliationMode::class)]
    private PaymentReconciliationMode $mode;

    #[ORM\Column(length: 32, enumType: PaymentReconciliationRunStatus::class)]
    private PaymentReconciliationRunStatus $status;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'reason_code', length: 64)]
    private string $reasonCode;

    #[ORM\Column(name: 'checked_count', options: ['default' => 0])]
    private int $checkedCount = 0;

    #[ORM\Column(name: 'matched_count', options: ['default' => 0])]
    private int $matchedCount = 0;

    #[ORM\Column(name: 'discrepancy_count', options: ['default' => 0])]
    private int $discrepancyCount = 0;

    #[ORM\Column(name: 'failed_count', options: ['default' => 0])]
    private int $failedCount = 0;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    private function __construct(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        PaymentReconciliationMode $mode,
        string $reasonCode,
        \DateTimeImmutable $startedAt,
        ?User $createdBy,
        ?Uuid $id = null,
    ) {
        $providerCode = CommerceInputNormalizer::providerCode($providerCode);
        $reasonCode = self::normalizeReasonCode($reasonCode);

        $this->id = $id ?? new UuidV7();
        $this->providerCode = $providerCode;
        $this->environment = $environment;
        $this->mode = $mode;
        $this->status = PaymentReconciliationRunStatus::Running;
        $this->startedAt = $startedAt;
        $this->createdBy = $createdBy;
        $this->reasonCode = $reasonCode;
        $this->schemaVersion = self::SCHEMA_VERSION;
    }

    public static function start(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        PaymentReconciliationMode $mode,
        string $reasonCode,
        \DateTimeImmutable $startedAt,
        ?User $createdBy = null,
        ?Uuid $id = null,
    ): self {
        return new self($providerCode, $environment, $mode, $reasonCode, $startedAt, $createdBy, $id);
    }

    public function complete(
        PaymentReconciliationRunStatus $status,
        int $checkedCount,
        int $matchedCount,
        int $discrepancyCount,
        int $failedCount,
        \DateTimeImmutable $completedAt,
    ): void {
        if (PaymentReconciliationRunStatus::Running !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        if (!$status->isTerminal()) {
            throw CommerceException::invalidTransition();
        }
        if ($checkedCount < 0 || $matchedCount < 0 || $discrepancyCount < 0 || $failedCount < 0) {
            throw CommerceException::invalidInput('Counters must be non-negative.');
        }
        if ($checkedCount !== $matchedCount + $discrepancyCount + $failedCount) {
            throw CommerceException::invalidInput('Counter totals must be consistent.');
        }

        $this->status = $status;
        $this->checkedCount = $checkedCount;
        $this->matchedCount = $matchedCount;
        $this->discrepancyCount = $discrepancyCount;
        $this->failedCount = $failedCount;
        $this->completedAt = $completedAt;
    }

    public static function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = strtolower(trim($reasonCode));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw CommerceException::invalidInput('reasonCode invalid.');
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

    public function getMode(): PaymentReconciliationMode
    {
        return $this->mode;
    }

    public function getStatus(): PaymentReconciliationRunStatus
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    #[Ignore]
    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }

    public function getCheckedCount(): int
    {
        return $this->checkedCount;
    }

    public function getMatchedCount(): int
    {
        return $this->matchedCount;
    }

    public function getDiscrepancyCount(): int
    {
        return $this->discrepancyCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
