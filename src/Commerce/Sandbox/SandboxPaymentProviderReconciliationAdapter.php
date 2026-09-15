<?php

declare(strict_types=1);

namespace App\Commerce\Sandbox;

use App\Commerce\PaymentProviderReconciliationAdapterInterface;
use App\Commerce\PaymentProviderTransactionSnapshot;
use App\Commerce\PaymentReconciliationLookupResult;
use App\Commerce\PaymentReconciliationQuery;
use App\Entity\PaymentAttempt;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentProviderTransactionStatus;
use App\Repository\PaymentAttemptRepository;
use App\Time\UtcInstant;
use Psr\Clock\ClockInterface;

/**
 * Deterministic sandbox reconciliation adapter for dev/test.
 *
 * Tests may inject snapshots via {@see setSnapshot()} / {@see setLookupResult()}.
 * Default behaviour derives a safe snapshot from the local attempt without secrets.
 */
final class SandboxPaymentProviderReconciliationAdapter implements PaymentProviderReconciliationAdapterInterface
{
    /**
     * @var array<string, PaymentProviderTransactionSnapshot>
     */
    private array $snapshotsByReference = [];

    /**
     * @var array<string, PaymentReconciliationLookupResult>
     */
    private array $resultsByAttemptId = [];

    private ?PaymentReconciliationLookupResult $defaultResult = null;

    /** @var (callable(PaymentReconciliationQuery): void)|null */
    private $beforeQuery;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ?PaymentAttemptRepository $attempts = null,
    ) {
    }

    public function getProviderCode(): string
    {
        return SandboxPaymentProviderAdapter::PROVIDER_CODE;
    }

    public function setSnapshot(string $providerPaymentReference, PaymentProviderTransactionSnapshot $snapshot): void
    {
        $this->snapshotsByReference[$providerPaymentReference] = $snapshot;
    }

    public function setLookupResultForAttempt(string $attemptIdRfc4122, PaymentReconciliationLookupResult $result): void
    {
        $this->resultsByAttemptId[$attemptIdRfc4122] = $result;
    }

    public function setDefaultResult(?PaymentReconciliationLookupResult $result): void
    {
        $this->defaultResult = $result;
    }

    public function clear(): void
    {
        $this->snapshotsByReference = [];
        $this->resultsByAttemptId = [];
        $this->defaultResult = null;
        $this->beforeQuery = null;
    }

    /**
     * Test hook invoked at the start of {@see queryTransaction()} (provider I/O boundary).
     *
     * @param (callable(PaymentReconciliationQuery): void)|null $callback
     */
    public function setBeforeQuery(?callable $callback): void
    {
        $this->beforeQuery = $callback;
    }

    public function queryTransaction(PaymentReconciliationQuery $query): PaymentReconciliationLookupResult
    {
        if (null !== $this->beforeQuery) {
            ($this->beforeQuery)($query);
        }

        if ($query->providerCode !== $this->getProviderCode()) {
            return PaymentReconciliationLookupResult::unsupported('provider_code_mismatch');
        }
        if (PaymentProviderEnvironment::Sandbox !== $query->environment) {
            return PaymentReconciliationLookupResult::unsupported('environment_mismatch');
        }

        $attemptKey = $query->paymentAttemptId->toRfc4122();
        if (isset($this->resultsByAttemptId[$attemptKey])) {
            return $this->resultsByAttemptId[$attemptKey];
        }

        if (null !== $query->providerPaymentReference
            && isset($this->snapshotsByReference[$query->providerPaymentReference])
        ) {
            return PaymentReconciliationLookupResult::found(
                $this->snapshotsByReference[$query->providerPaymentReference],
            );
        }

        if ($this->defaultResult instanceof PaymentReconciliationLookupResult) {
            return $this->defaultResult;
        }

        if (!$this->attempts instanceof PaymentAttemptRepository) {
            return PaymentReconciliationLookupResult::missing();
        }

        $attempt = $this->attempts->findOneById($query->paymentAttemptId);
        if (!$attempt instanceof PaymentAttempt) {
            return PaymentReconciliationLookupResult::missing('attempt_not_found');
        }

        $reference = $attempt->getProviderPaymentReference();
        if (null === $reference || '' === $reference) {
            return PaymentReconciliationLookupResult::missing('local_reference_missing');
        }

        return PaymentReconciliationLookupResult::found($this->deriveFromAttempt($attempt, $reference));
    }

    private function deriveFromAttempt(PaymentAttempt $attempt, string $reference): PaymentProviderTransactionSnapshot
    {
        $now = UtcInstant::ensure($this->clock->now());
        $status = match ($attempt->getStatus()) {
            PaymentAttemptStatus::Initiated => PaymentProviderTransactionStatus::Initiated,
            PaymentAttemptStatus::Authorized => PaymentProviderTransactionStatus::Authorized,
            PaymentAttemptStatus::Captured => PaymentProviderTransactionStatus::Captured,
            PaymentAttemptStatus::Failed => PaymentProviderTransactionStatus::Failed,
            PaymentAttemptStatus::Cancelled => PaymentProviderTransactionStatus::Cancelled,
        };

        return new PaymentProviderTransactionSnapshot(
            providerCode: $this->getProviderCode(),
            environment: PaymentProviderEnvironment::Sandbox,
            providerPaymentReference: $reference,
            providerStatus: $status,
            amountMinor: $attempt->getAmountMinor(),
            currency: $attempt->getCurrency(),
            providerUpdatedAt: $now,
            authorizedAt: $attempt->getAuthorizedAt(),
            capturedAt: $attempt->getCapturedAt(),
            cancelledAt: $attempt->getCancelledAt(),
            providerAuthorizationReference: $attempt->getProviderAuthorizationReference(),
        );
    }
}
