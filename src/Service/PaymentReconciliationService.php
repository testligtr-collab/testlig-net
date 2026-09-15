<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\PaymentProviderRegistry;
use App\Commerce\PaymentProviderTransactionSnapshot;
use App\Commerce\PaymentReconciliationLookupResult;
use App\Commerce\PaymentReconciliationQuery;
use App\Commerce\PaymentReconciliationSnapshotHasher;
use App\Commerce\VerifiedPaymentWebhook;
use App\Dto\PaymentReconciliationSummary;
use App\Dto\SecurityAuditContext;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentReconciliationItem;
use App\Entity\PaymentReconciliationRun;
use App\Entity\PaymentWebhookInboxEvent;
use App\Entity\User;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentProviderTransactionStatus;
use App\Enum\PaymentReconciliationItemAction;
use App\Enum\PaymentReconciliationItemOutcome;
use App\Enum\PaymentReconciliationLookupStatus;
use App\Enum\PaymentReconciliationMode;
use App\Enum\PaymentReconciliationRunStatus;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\PaymentAttemptRepository;
use App\Repository\PaymentReconciliationItemRepository;
use App\Repository\PaymentReconciliationRunRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Security\CommerceAuthorization;
use App\Time\UtcInstant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Observes provider vs local payment state and converges only via the webhook inbox pipeline.
 *
 * Provider network calls run outside open DB transactions. Automatic mutation is limited to
 * enqueueing reconciliation-sourced verified capture events for local-behind cases.
 */
final class PaymentReconciliationService
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentAttemptRepository $attempts,
        private readonly PaymentReconciliationRunRepository $runs,
        private readonly PaymentReconciliationItemRepository $items,
        private readonly PaymentWebhookInboxEventRepository $inbox,
        private readonly PaymentWebhookProcessor $webhookProcessor,
        private readonly PaymentRefundRepository $refunds,
        private readonly FreshUserLoader $freshUsers,
        private readonly CommerceAuthorization $commerceAuthorization,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function reconcile(
        Uuid $actorId,
        string $providerCode,
        PaymentProviderEnvironment $environment,
        string $reasonCode,
        bool $confirm,
        bool $dryRun = false,
        ?Uuid $attemptId = null,
        int $limit = self::DEFAULT_LIMIT,
        PaymentReconciliationMode $mode = PaymentReconciliationMode::Manual,
    ): PaymentReconciliationSummary {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw CommerceException::invalidInput('limit must be between 1 and '.self::MAX_LIMIT.'.');
        }
        if (!$dryRun && !$confirm) {
            throw CommerceException::invalidInput('confirm is required when not dry-run.');
        }

        $actor = $this->em->wrapInTransaction(function () use ($actorId): User {
            $locked = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
            if (!$locked instanceof User) {
                throw CommerceException::unauthorized();
            }
            $this->commerceAuthorization->assertCanOperatePayments($locked);

            return $locked;
        });

        $this->providers->assertEnvironment($providerCode, $environment);
        $reasonCode = PaymentReconciliationRun::normalizeReasonCode($reasonCode);
        $now = UtcInstant::ensure($this->clock->now());

        $targets = $this->resolveAttempts($providerCode, $environment, $attemptId, $limit);
        $activeRunId = null;
        if (!$dryRun) {
            $run = PaymentReconciliationRun::start(
                $providerCode,
                $environment,
                $mode,
                $reasonCode,
                $now,
                $actor,
            );
            $this->runs->save($run, true);
            $activeRunId = $run->getId();
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::PaymentReconciliationStarted,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                metadata: [
                    'source' => 'payment_reconciliation',
                    'reason_code' => $reasonCode,
                    'provider_code' => $providerCode,
                    'environment' => $environment->value,
                    'reconciliation_run_id' => $activeRunId->toRfc4122(),
                    'checked_count' => 0,
                ],
                captureRequestHashes: false,
            ));
        }

        $matched = 0;
        $discrepancy = 0;
        $failed = 0;
        $hardFailure = false;

        foreach ($targets as $attempt) {
            $lookup = $this->queryProviderOutsideTx($providerCode, $environment, $attempt);
            [$outcome, $action, $providerState, $snapshotHash, $itemReason] = $this->decide(
                $attempt,
                $lookup,
            );

            if (null !== $activeRunId && PaymentReconciliationItemAction::WebhookRequeued === $action
                && $lookup->snapshot instanceof PaymentProviderTransactionSnapshot
            ) {
                $persistedRunId = $activeRunId;
                try {
                    $this->enqueueVerifiedCapture($attempt, $lookup->snapshot);
                    $actor = $this->requireManagedActor($actorId);
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::PaymentReconciliationActionApplied,
                        actorType: SecurityAuditActorType::User,
                        outcome: SecurityAuditOutcome::Success,
                        actorUser: $actor,
                        metadata: [
                            'source' => 'payment_reconciliation',
                            'reason_code' => 'webhook_requeued',
                            'provider_code' => $providerCode,
                            'environment' => $environment->value,
                            'reconciliation_run_id' => $persistedRunId->toRfc4122(),
                            'reconciliation_outcome' => $outcome->value,
                            'payment_attempt_id' => $attempt->getId()->toRfc4122(),
                        ],
                        captureRequestHashes: false,
                    ));
                } catch (CommerceException $e) {
                    $outcome = PaymentReconciliationItemOutcome::Failed;
                    $action = PaymentReconciliationItemAction::ManualReviewRequired;
                    $itemReason = $e->getReason()->value;
                    $hardFailure = true;
                } catch (\Throwable) {
                    $outcome = PaymentReconciliationItemOutcome::Failed;
                    $action = PaymentReconciliationItemAction::ManualReviewRequired;
                    $itemReason = 'webhook_requeue_failed';
                    $hardFailure = true;
                }
            }

            if ($outcome->isHardFailure()) {
                ++$failed;
                $hardFailure = true;
            } elseif ($outcome->isDiscrepancy()) {
                ++$discrepancy;
            } else {
                ++$matched;
            }

            if (null !== $activeRunId) {
                $targetAttemptId = $attempt->getId();
                $currentRunId = $activeRunId;
                $expectedState = $attempt->getStatus();
                $this->em->wrapInTransaction(function () use (
                    $currentRunId,
                    $targetAttemptId,
                    $actorId,
                    $expectedState,
                    $providerState,
                    $outcome,
                    $action,
                    $snapshotHash,
                    $itemReason,
                    $providerCode,
                    $environment,
                ): void {
                    $persistedRun = $this->runs->findOneById($currentRunId);
                    $freshAttempt = $this->attempts->findOneById($targetAttemptId);
                    $freshActor = $this->requireManagedActor($actorId);
                    if (!$persistedRun instanceof PaymentReconciliationRun
                        || !$freshAttempt instanceof PaymentAttempt
                    ) {
                        throw CommerceException::conflict();
                    }
                    $item = PaymentReconciliationItem::record(
                        $persistedRun,
                        $freshAttempt,
                        $expectedState,
                        $providerState,
                        $outcome,
                        $action,
                        $snapshotHash,
                        UtcInstant::ensure($this->clock->now()),
                        $itemReason,
                    );
                    $this->items->save($item, true);

                    if ($outcome->isDiscrepancy()) {
                        $this->auditRecorder->record(new SecurityAuditContext(
                            action: SecurityAuditAction::PaymentReconciliationDiscrepancyFound,
                            actorType: SecurityAuditActorType::User,
                            outcome: SecurityAuditOutcome::Failure,
                            actorUser: $freshActor,
                            metadata: [
                                'source' => 'payment_reconciliation',
                                'reason_code' => $itemReason ?? $outcome->value,
                                'provider_code' => $providerCode,
                                'environment' => $environment->value,
                                'reconciliation_run_id' => $persistedRun->getId()->toRfc4122(),
                                'reconciliation_outcome' => $outcome->value,
                                'payment_attempt_id' => $targetAttemptId->toRfc4122(),
                            ],
                            captureRequestHashes: false,
                        ));
                    }
                });
            }
        }

        $checked = $matched + $discrepancy + $failed;
        $runStatus = match (true) {
            $hardFailure && 0 === $matched && 0 === $discrepancy => PaymentReconciliationRunStatus::Failed,
            $hardFailure || $discrepancy > 0 => PaymentReconciliationRunStatus::CompletedWithDiscrepancies,
            default => PaymentReconciliationRunStatus::Completed,
        };

        if (null !== $activeRunId) {
            $finalRun = $this->runs->findOneById($activeRunId);
            $managedActor = $this->requireManagedActor($actorId);
            if (!$finalRun instanceof PaymentReconciliationRun) {
                throw CommerceException::conflict();
            }
            $finalRun->complete(
                $runStatus,
                $checked,
                $matched,
                $discrepancy,
                $failed,
                UtcInstant::ensure($this->clock->now()),
            );
            $this->runs->save($finalRun, true);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::PaymentReconciliationCompleted,
                actorType: SecurityAuditActorType::User,
                outcome: PaymentReconciliationRunStatus::Failed === $runStatus
                    ? SecurityAuditOutcome::Failure
                    : SecurityAuditOutcome::Success,
                actorUser: $managedActor,
                metadata: [
                    'source' => 'payment_reconciliation',
                    'reason_code' => $reasonCode,
                    'provider_code' => $providerCode,
                    'environment' => $environment->value,
                    'reconciliation_run_id' => $finalRun->getId()->toRfc4122(),
                    'checked_count' => $checked,
                    'matched_count' => $matched,
                    'discrepancy_count' => $discrepancy,
                    'failed_count' => $failed,
                    'processing_status' => $runStatus->value,
                ],
                captureRequestHashes: false,
            ));
        }

        return new PaymentReconciliationSummary(
            checked: $checked,
            matched: $matched,
            discrepancy: $discrepancy,
            failed: $failed,
            runStatus: $runStatus->value,
            dryRun: $dryRun,
        );
    }

    private function requireManagedActor(Uuid $actorId): User
    {
        $actor = $this->em->find(User::class, $actorId);
        if (!$actor instanceof User) {
            throw CommerceException::unauthorized();
        }

        return $actor;
    }

    /**
     * @return list<PaymentAttempt>
     */
    private function resolveAttempts(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        ?Uuid $attemptId,
        int $limit,
    ): array {
        if ($attemptId instanceof Uuid) {
            $attempt = $this->attempts->findOneById($attemptId);
            if (!$attempt instanceof PaymentAttempt) {
                throw CommerceException::notFound();
            }
            if ($attempt->getProviderCode() !== $providerCode
                || $attempt->getEnvironment() !== $environment
            ) {
                throw CommerceException::providerMismatch();
            }

            return [$attempt];
        }

        return $this->attempts->findForReconciliation($providerCode, $environment, $limit);
    }

    private function queryProviderOutsideTx(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        PaymentAttempt $attempt,
    ): PaymentReconciliationLookupResult {
        if ($this->em->getConnection()->isTransactionActive()) {
            throw CommerceException::conflict();
        }

        $adapter = $this->providers->findReconciliationAdapter($providerCode);
        if (!$adapter instanceof \App\Commerce\PaymentProviderReconciliationAdapterInterface) {
            return PaymentReconciliationLookupResult::unsupported('reconciliation_adapter_not_registered');
        }

        return $adapter->queryTransaction(new PaymentReconciliationQuery(
            providerCode: $providerCode,
            environment: $environment,
            paymentAttemptId: $attempt->getId(),
            providerPaymentReference: $attempt->getProviderPaymentReference(),
            orderPublicReference: $attempt->getOrder()->getPublicReference(),
        ));
    }

    /**
     * @return array{
     *     0: PaymentReconciliationItemOutcome,
     *     1: PaymentReconciliationItemAction,
     *     2: ?PaymentProviderTransactionStatus,
     *     3: ?string,
     *     4: ?string
     * }
     */
    private function decide(
        PaymentAttempt $attempt,
        PaymentReconciliationLookupResult $lookup,
    ): array {
        return match ($lookup->status) {
            PaymentReconciliationLookupStatus::Unsupported => [
                PaymentReconciliationItemOutcome::Unsupported,
                PaymentReconciliationItemAction::ManualReviewRequired,
                null,
                null,
                $lookup->reasonCode,
            ],
            PaymentReconciliationLookupStatus::Missing => [
                PaymentReconciliationItemOutcome::MissingAtProvider,
                PaymentReconciliationItemAction::None,
                null,
                null,
                $lookup->reasonCode,
            ],
            PaymentReconciliationLookupStatus::Ambiguous,
            PaymentReconciliationLookupStatus::Failed => [
                PaymentReconciliationItemOutcome::Failed,
                PaymentReconciliationItemAction::ManualReviewRequired,
                null,
                null,
                $lookup->reasonCode,
            ],
            PaymentReconciliationLookupStatus::Found => $this->compareFound($attempt, $lookup->snapshot),
        };
    }

    /**
     * @return array{
     *     0: PaymentReconciliationItemOutcome,
     *     1: PaymentReconciliationItemAction,
     *     2: ?PaymentProviderTransactionStatus,
     *     3: ?string,
     *     4: ?string
     * }
     */
    private function compareFound(
        PaymentAttempt $attempt,
        ?PaymentProviderTransactionSnapshot $snapshot,
    ): array {
        if (!$snapshot instanceof PaymentProviderTransactionSnapshot) {
            return [
                PaymentReconciliationItemOutcome::Failed,
                PaymentReconciliationItemAction::ManualReviewRequired,
                null,
                null,
                'snapshot_missing',
            ];
        }

        $hash = PaymentReconciliationSnapshotHasher::hash($snapshot);

        if ($snapshot->currency !== $attempt->getCurrency()) {
            return [
                PaymentReconciliationItemOutcome::CurrencyMismatch,
                PaymentReconciliationItemAction::ManualReviewRequired,
                $snapshot->providerStatus,
                $hash,
                'currency_mismatch',
            ];
        }
        if ($snapshot->amountMinor !== $attempt->getAmountMinor()) {
            return [
                PaymentReconciliationItemOutcome::AmountMismatch,
                PaymentReconciliationItemAction::ManualReviewRequired,
                $snapshot->providerStatus,
                $hash,
                'amount_mismatch',
            ];
        }

        $localRef = $attempt->getProviderPaymentReference();
        if (null !== $localRef && $localRef !== $snapshot->providerPaymentReference) {
            return [
                PaymentReconciliationItemOutcome::ReferenceMismatch,
                PaymentReconciliationItemAction::ManualReviewRequired,
                $snapshot->providerStatus,
                $hash,
                'reference_mismatch',
            ];
        }

        $refunded = $this->refunds->sumSucceededAmountMinorForAttempt($attempt->getId());
        if ($snapshot->refundedAmountMinor !== $refunded
            || ($snapshot->refundCount > 0 && $refunded <= 0)
            || (0 === $snapshot->refundCount && $refunded > 0)
        ) {
            return [
                PaymentReconciliationItemOutcome::ProviderBehind,
                PaymentReconciliationItemAction::ManualReviewRequired,
                $snapshot->providerStatus,
                $hash,
                'refund_mismatch',
            ];
        }

        $local = $attempt->getStatus();
        $provider = $snapshot->providerStatus;

        if ($this->statusEquals($local, $provider)) {
            return [
                PaymentReconciliationItemOutcome::Matched,
                PaymentReconciliationItemAction::None,
                $provider,
                $hash,
                null,
            ];
        }

        if (PaymentProviderTransactionStatus::Captured === $provider
            && \in_array($local, [PaymentAttemptStatus::Initiated, PaymentAttemptStatus::Authorized], true)
        ) {
            return [
                PaymentReconciliationItemOutcome::LocalBehind,
                PaymentReconciliationItemAction::WebhookRequeued,
                $provider,
                $hash,
                'local_behind_capture',
            ];
        }

        if (PaymentAttemptStatus::Captured === $local
            && \in_array($provider, [
                PaymentProviderTransactionStatus::Initiated,
                PaymentProviderTransactionStatus::Authorized,
            ], true)
        ) {
            return [
                PaymentReconciliationItemOutcome::ProviderBehind,
                PaymentReconciliationItemAction::ManualReviewRequired,
                $provider,
                $hash,
                'provider_behind',
            ];
        }

        return [
            PaymentReconciliationItemOutcome::ProviderBehind,
            PaymentReconciliationItemAction::ManualReviewRequired,
            $provider,
            $hash,
            'state_divergence',
        ];
    }

    private function statusEquals(
        PaymentAttemptStatus $local,
        PaymentProviderTransactionStatus $provider,
    ): bool {
        return $local->value === $provider->value;
    }

    private function enqueueVerifiedCapture(
        PaymentAttempt $attempt,
        PaymentProviderTransactionSnapshot $snapshot,
    ): void {
        $now = UtcInstant::ensure($this->clock->now());
        $canonical = json_encode([
            'amount_minor' => $snapshot->amountMinor,
            'currency' => $snapshot->currency,
            'event_source' => 'reconciliation',
            'event_type' => PaymentEventType::Captured->value,
            'payment_attempt_id' => $attempt->getId()->toRfc4122(),
            'provider_code' => $snapshot->providerCode,
            'provider_payment_reference' => $snapshot->providerPaymentReference,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $digest = hash('sha256', $canonical);
        $eventRef = 'recon_'.substr($digest, 0, 16);
        PaymentAttempt::assertProviderReference($eventRef);

        $existing = $this->inbox->findOneByProviderEvent(
            $snapshot->providerCode,
            $snapshot->environment,
            $eventRef,
        );
        if ($existing instanceof PaymentWebhookInboxEvent) {
            if (\in_array($existing->getProcessingStatus(), [
                PaymentWebhookInboxStatus::Received,
                PaymentWebhookInboxStatus::RetryPending,
                PaymentWebhookInboxStatus::Processing,
            ], true)) {
                $this->webhookProcessor->process($existing->getId());
            }

            return;
        }

        $sanitizedMetadata = [
            'provider_code' => $snapshot->providerCode,
            'provider_environment' => $snapshot->environment->value,
            'event_source' => 'reconciliation',
            'amount_minor' => $snapshot->amountMinor,
            'currency' => $snapshot->currency,
            'order_public_reference' => $attempt->getOrder()->getPublicReference(),
            'provider_payment_reference' => $snapshot->providerPaymentReference,
        ];

        $verified = new VerifiedPaymentWebhook(
            providerCode: $snapshot->providerCode,
            environment: $snapshot->environment,
            providerEventReference: $eventRef,
            eventType: PaymentEventType::Captured,
            payloadHash: $digest,
            signatureFingerprint: $digest,
            providerOccurredAt: $snapshot->capturedAt ?? $snapshot->providerUpdatedAt,
            receivedAt: $now,
            paymentAttemptId: $attempt->getId(),
            orderPublicReference: $attempt->getOrder()->getPublicReference(),
            amount: Money::fromMinor($snapshot->amountMinor, $snapshot->currency),
            providerPaymentReference: $snapshot->providerPaymentReference,
            providerAuthorizationReference: $snapshot->providerAuthorizationReference,
            sanitizedMetadata: $sanitizedMetadata,
        );

        $inboxEvent = PaymentWebhookInboxEvent::receiveVerified($verified, $attempt);
        $this->inbox->save($inboxEvent, true);
        $processed = $this->webhookProcessor->process($inboxEvent->getId());
        $freshAttempt = $this->attempts->findOneById($attempt->getId());
        if (PaymentAttemptStatus::Captured === $freshAttempt?->getStatus()) {
            return;
        }
        if (PaymentWebhookInboxStatus::Processed !== $processed->getProcessingStatus()) {
            throw CommerceException::conflict();
        }
    }
}
