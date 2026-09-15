<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderTransactionStatus;
use App\Enum\PaymentReconciliationItemAction;
use App\Enum\PaymentReconciliationItemOutcome;
use App\Exception\CommerceException;
use App\Repository\PaymentReconciliationItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only reconciliation item. INSERT only — never UPDATE/DELETE.
 */
#[ORM\Entity(repositoryClass: PaymentReconciliationItemRepository::class)]
#[ORM\Table(name: 'payment_reconciliation_items')]
#[ORM\UniqueConstraint(name: 'uniq_pri_run_attempt', columns: ['run_id', 'payment_attempt_id'])]
#[ORM\Index(name: 'idx_pri_outcome', columns: ['outcome'])]
#[ORM\Index(name: 'idx_pri_attempt', columns: ['payment_attempt_id'])]
#[ORM\Index(name: 'idx_pri_run_outcome', columns: ['run_id', 'outcome'])]
class PaymentReconciliationItem
{
    public const SCHEMA_VERSION = 1;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PaymentReconciliationRun $run;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'payment_attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PaymentAttempt $paymentAttempt;

    #[ORM\Column(name: 'expected_state', length: 32, enumType: PaymentAttemptStatus::class)]
    private PaymentAttemptStatus $expectedState;

    #[ORM\Column(name: 'provider_state', length: 32, nullable: true, enumType: PaymentProviderTransactionStatus::class)]
    private ?PaymentProviderTransactionStatus $providerState;

    #[ORM\Column(length: 32, enumType: PaymentReconciliationItemOutcome::class)]
    private PaymentReconciliationItemOutcome $outcome;

    #[ORM\Column(length: 32, enumType: PaymentReconciliationItemAction::class)]
    private PaymentReconciliationItemAction $action;

    #[ORM\Column(name: 'safe_snapshot_hash', length: 64, nullable: true)]
    private ?string $safeSnapshotHash;

    #[ORM\Column(name: 'checked_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $checkedAt;

    #[ORM\Column(name: 'reason_code', length: 64, nullable: true)]
    private ?string $reasonCode;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    private function __construct(
        PaymentReconciliationRun $run,
        PaymentAttempt $paymentAttempt,
        PaymentAttemptStatus $expectedState,
        ?PaymentProviderTransactionStatus $providerState,
        PaymentReconciliationItemOutcome $outcome,
        PaymentReconciliationItemAction $action,
        ?string $safeSnapshotHash,
        \DateTimeImmutable $checkedAt,
        ?string $reasonCode,
        ?Uuid $id = null,
    ) {
        if (null !== $safeSnapshotHash && 1 !== preg_match('/^[0-9a-f]{64}$/', $safeSnapshotHash)) {
            throw CommerceException::hashMismatch();
        }
        if (null !== $reasonCode) {
            $reasonCode = PaymentReconciliationRun::normalizeReasonCode($reasonCode);
        }

        $this->id = $id ?? new UuidV7();
        $this->run = $run;
        $this->paymentAttempt = $paymentAttempt;
        $this->expectedState = $expectedState;
        $this->providerState = $providerState;
        $this->outcome = $outcome;
        $this->action = $action;
        $this->safeSnapshotHash = $safeSnapshotHash;
        $this->checkedAt = $checkedAt;
        $this->reasonCode = $reasonCode;
        $this->schemaVersion = self::SCHEMA_VERSION;
    }

    public static function record(
        PaymentReconciliationRun $run,
        PaymentAttempt $paymentAttempt,
        PaymentAttemptStatus $expectedState,
        ?PaymentProviderTransactionStatus $providerState,
        PaymentReconciliationItemOutcome $outcome,
        PaymentReconciliationItemAction $action,
        ?string $safeSnapshotHash,
        \DateTimeImmutable $checkedAt,
        ?string $reasonCode = null,
        ?Uuid $id = null,
    ): self {
        return new self(
            $run,
            $paymentAttempt,
            $expectedState,
            $providerState,
            $outcome,
            $action,
            $safeSnapshotHash,
            $checkedAt,
            $reasonCode,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getRun(): PaymentReconciliationRun
    {
        return $this->run;
    }

    #[Ignore]
    public function getPaymentAttempt(): PaymentAttempt
    {
        return $this->paymentAttempt;
    }

    public function getExpectedState(): PaymentAttemptStatus
    {
        return $this->expectedState;
    }

    public function getProviderState(): ?PaymentProviderTransactionStatus
    {
        return $this->providerState;
    }

    public function getOutcome(): PaymentReconciliationItemOutcome
    {
        return $this->outcome;
    }

    public function getAction(): PaymentReconciliationItemAction
    {
        return $this->action;
    }

    public function getSafeSnapshotHash(): ?string
    {
        return $this->safeSnapshotHash;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
