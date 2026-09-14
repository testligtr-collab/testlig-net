<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\NullPaymentWebhookProcessingCheckpoint;
use App\Commerce\PaymentWebhookProcessingCheckpointInterface;
use App\Dto\SecurityAuditContext;
use App\Entity\CommerceOrder;
use App\Entity\Institution;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentEvent;
use App\Entity\PaymentRefund;
use App\Entity\PaymentWebhookInboxEvent;
use App\Entity\User;
use App\Enum\CommerceFailureReason;
use App\Enum\CommerceOrderStatus;
use App\Enum\InstitutionStatus;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentEventType;
use App\Enum\PaymentRefundReasonCode;
use App\Enum\PaymentRefundStatus;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\CommerceFulfillmentRepository;
use App\Repository\PaymentEventRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Deterministic webhook processing with at-least-once delivery semantics.
 *
 * Provider delivery is at-least-once. Application processing is idempotent convergence
 * under UNIQUE(provider, env, event_ref) + fresh DB revalidation + claim lease.
 * Inbox becomes `processed` only after event-type post-conditions are verified.
 * Domain managers own their transactions; this worker never nests provider HTTP calls.
 *
 * Lock order: Institution → actor → Order → Attempt → Inbox → Event/Refund →
 * Fulfillment/License → Audit.
 */
final class PaymentWebhookProcessor
{
    public function __construct(
        private readonly PaymentWebhookInboxEventRepository $inbox,
        private readonly PaymentSettlementManager $settlementManager,
        private readonly PaymentRefundManager $refundManager,
        private readonly CommerceFulfillmentManager $fulfillmentManager,
        private readonly PaymentPlatformSettlementActorResolver $settlementActors,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly PaymentEventRepository $events,
        private readonly PaymentRefundRepository $refunds,
        private readonly CommerceFulfillmentRepository $fulfillments,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ManagerRegistry $doctrine,
        private readonly ClockInterface $clock,
        private readonly PaymentWebhookProcessingCheckpointInterface $checkpoint = new NullPaymentWebhookProcessingCheckpoint(),
        private readonly bool $autoFulfillOnCapture = true,
    ) {
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();
        if (!$em instanceof EntityManagerInterface) {
            throw CommerceException::conflict();
        }
        if (!$em->isOpen()) {
            $em = $this->doctrine->resetManager();
            if (!$em instanceof EntityManagerInterface) {
                throw CommerceException::conflict();
            }
        }

        return $em;
    }

    public function process(Uuid $inboxEventId): PaymentWebhookInboxEvent
    {
        $existing = $this->inbox->findOneById($inboxEventId);
        if ($existing instanceof PaymentWebhookInboxEvent && $existing->getProcessingStatus()->isTerminal()) {
            return $existing;
        }

        try {
            $claimed = $this->inbox->claimForProcessing($inboxEventId);
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }

        if (null === $claimed) {
            $fresh = $this->inbox->findOneById($inboxEventId);
            if ($fresh instanceof PaymentWebhookInboxEvent && $fresh->getProcessingStatus()->isTerminal()) {
                return $fresh;
            }
            throw CommerceException::conflict();
        }

        $claimToken = $claimed['claimToken'];
        $this->checkpoint->before('after_claim');

        try {
            $this->checkpoint->before('before_settlement');
            $this->convergeEffects($inboxEventId);
            $this->assertPostConditions($inboxEventId);
            $this->checkpoint->before('after_fulfillment_before_processed');

            return $this->finalizeProcessed($inboxEventId, $claimToken);
        } catch (CommerceException $e) {
            $this->handleProcessingFailure($inboxEventId, $claimToken, $e);
            $after = $this->inbox->findOneById($inboxEventId);
            if ($after instanceof PaymentWebhookInboxEvent && $after->getProcessingStatus()->isTerminal()) {
                // Permanent reject/dead-letter: ACK to ingress so providers stop retrying bad payloads.
                return $after;
            }
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException $e) {
            $this->scheduleRetrySafe($inboxEventId, $claimToken, CommerceFailureReason::Conflict->value);
            throw CommerceException::conflict();
        }
    }

    private function convergeEffects(Uuid $inboxEventId): void
    {
        $inboxEvent = $this->requireInbox($inboxEventId);
        $attempt = $this->requireAttempt($inboxEvent);
        $actor = $this->settlementActors->resolve();
        $this->assertProviderAndOrderScope($inboxEvent, $attempt);

        $meta = $inboxEvent->getSanitizedMetadata();
        $settlementKey = $this->settlementIdempotencyKey($inboxEvent);

        match ($inboxEvent->getEventType()) {
            PaymentEventType::Authorized => $this->convergeAuthorized($attempt, $actor, $inboxEvent, $settlementKey, $meta),
            PaymentEventType::Captured => $this->convergeCaptured($attempt, $actor, $inboxEvent, $settlementKey, $meta),
            PaymentEventType::Failed => $this->convergeFailed($attempt, $actor, $inboxEvent, $settlementKey, $meta),
            PaymentEventType::Cancelled => $this->convergeCancelled($attempt, $actor, $inboxEvent, $settlementKey, $meta),
            PaymentEventType::RefundSucceeded => $this->convergeRefundSucceeded($attempt, $actor, $inboxEvent, $settlementKey),
            PaymentEventType::RefundFailed => $this->convergeRefundFailed($attempt, $actor, $inboxEvent, $settlementKey),
            PaymentEventType::RefundRequested => throw CommerceException::invalidInput(
                'refund_requested is not accepted from webhook; use refund manager.',
            ),
        };
    }

    /**
     * @param array<string, bool|int|string|null> $meta
     */
    private function convergeAuthorized(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
        array $meta,
    ): void {
        $fresh = $this->requireFreshAttempt($attempt->getId());
        if ($this->findMatchingEvent($fresh, $inbox) instanceof PaymentEvent) {
            return;
        }
        if (\in_array($fresh->getStatus(), [
            PaymentAttemptStatus::Authorized,
            PaymentAttemptStatus::Captured,
        ], true)) {
            return;
        }
        if ($fresh->getStatus()->isTerminal()) {
            throw CommerceException::invalidTransition();
        }

        $money = $this->requireMoney($inbox, $fresh);
        $this->settlementManager->recordAuthorized(
            $fresh,
            $actor,
            $money,
            $inbox->getProviderOccurredAt(),
            $settlementKey,
            'webhook_authorized',
            $this->metaString($meta, 'provider_payment_reference')
                ?? $this->readVerifiedProviderPaymentReference($inbox),
            $this->readVerifiedAuthorizationReference($inbox),
            $inbox->getProviderEventReference(),
            $this->settlementSafeMetadata($meta),
        );
    }

    /**
     * @param array<string, bool|int|string|null> $meta
     */
    private function convergeCaptured(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
        array $meta,
    ): void {
        $fresh = $this->requireFreshAttempt($attempt->getId());
        $matching = $this->findMatchingEvent($fresh, $inbox);

        if ($matching instanceof PaymentEvent) {
            if (PaymentEventType::Captured !== $matching->getEventType()) {
                throw CommerceException::webhookIntegrityConflict();
            }
        } elseif (PaymentAttemptStatus::Captured === $fresh->getStatus()) {
            // Captured by another event/path without this provider reference.
            throw CommerceException::webhookIntegrityConflict();
        } elseif (\in_array($fresh->getStatus(), [
            PaymentAttemptStatus::Failed,
            PaymentAttemptStatus::Cancelled,
        ], true)) {
            throw CommerceException::invalidTransition();
        } else {
            $money = $this->requireMoney($inbox, $fresh);
            $this->settlementManager->recordCaptured(
                $fresh,
                $actor,
                $money,
                $inbox->getProviderOccurredAt(),
                $settlementKey,
                'webhook_captured',
                $this->readVerifiedProviderPaymentReference($inbox) ?? $fresh->getProviderPaymentReference(),
                $inbox->getProviderEventReference(),
                $this->settlementSafeMetadata($meta),
            );
        }

        $this->checkpoint->before('after_settlement_before_fulfillment');

        if ($this->autoFulfillOnCapture) {
            $this->fulfillCapturedIfNeeded($fresh->getId(), $actor, $inbox);
        }
    }

    /**
     * @param array<string, bool|int|string|null> $meta
     */
    private function convergeFailed(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
        array $meta,
    ): void {
        $fresh = $this->requireFreshAttempt($attempt->getId());
        if ($this->findMatchingEvent($fresh, $inbox) instanceof PaymentEvent) {
            return;
        }
        if ($fresh->getStatus()->isTerminal()) {
            return;
        }
        $this->settlementManager->recordFailed(
            $fresh,
            $actor,
            $this->readFailureCode($inbox),
            $inbox->getProviderOccurredAt(),
            $settlementKey,
            'webhook_failed',
            $inbox->getProviderEventReference(),
            $this->settlementSafeMetadata($meta),
        );
    }

    /**
     * @param array<string, bool|int|string|null> $meta
     */
    private function convergeCancelled(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
        array $meta,
    ): void {
        $fresh = $this->requireFreshAttempt($attempt->getId());
        if ($this->findMatchingEvent($fresh, $inbox) instanceof PaymentEvent) {
            return;
        }
        if ($fresh->getStatus()->isTerminal()) {
            return;
        }
        $this->settlementManager->recordCancelled(
            $fresh,
            $actor,
            $this->readFailureCode($inbox),
            $inbox->getProviderOccurredAt(),
            $settlementKey,
            'webhook_cancelled',
            $inbox->getProviderEventReference(),
            $this->settlementSafeMetadata($meta),
        );
    }

    private function convergeRefundSucceeded(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
    ): void {
        $fresh = $this->requireFreshAttempt($attempt->getId());
        $money = $this->requireRefundMoney($inbox, $fresh);
        $refund = $this->findOrRequestRefund($fresh, $actor, $money, $settlementKey.'.req');
        if (PaymentRefundStatus::Succeeded === $refund->getStatus()) {
            return;
        }
        $this->refundManager->markSucceeded(
            $refund,
            $actor,
            $settlementKey.'.ok',
            'webhook_refund_succeeded',
            $this->readVerifiedRefundReference($inbox),
        );
    }

    private function convergeRefundFailed(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
    ): void {
        $fresh = $this->requireFreshAttempt($attempt->getId());
        $open = $this->refunds->findOpenForAttempt($fresh->getId());
        if (!$open instanceof PaymentRefund) {
            throw CommerceException::notFound();
        }
        if (PaymentRefundStatus::Failed === $open->getStatus()) {
            return;
        }
        $this->refundManager->markFailed(
            $open,
            $actor,
            $this->readFailureCode($inbox),
            $settlementKey.'.fail',
            'webhook_refund_failed',
        );
    }

    private function fulfillCapturedIfNeeded(Uuid $attemptId, User $actor, PaymentWebhookInboxEvent $inbox): void
    {
        $freshAttempt = $this->requireFreshAttempt($attemptId);
        $freshOrder = $this->freshCommerce->findFreshOrder(
            $freshAttempt->getOrder()->getId(),
            LockMode::NONE,
        );
        if (!$freshOrder instanceof CommerceOrder
            || PaymentAttemptStatus::Captured !== $freshAttempt->getStatus()
        ) {
            return;
        }
        if ($this->fulfillments->countCompletedForPaymentAttempt($attemptId) >= 1
            && CommerceOrderStatus::Paid === $freshOrder->getStatus()
        ) {
            return;
        }

        $this->fulfillmentManager->fulfill(
            $freshOrder,
            $freshAttempt,
            $actor,
            'webhook:fulfill:'.$inbox->getId()->toRfc4122(),
            'webhook_fulfill',
        );
    }

    private function assertPostConditions(Uuid $inboxEventId): void
    {
        $inbox = $this->requireInbox($inboxEventId);
        $attempt = $this->requireFreshAttempt($this->requireAttempt($inbox)->getId());
        $order = $this->freshCommerce->findFreshOrder($attempt->getOrder()->getId(), LockMode::NONE);
        if (!$order instanceof CommerceOrder) {
            throw CommerceException::notFound();
        }

        match ($inbox->getEventType()) {
            PaymentEventType::Authorized => $this->assertAuthorizedDone($attempt, $inbox),
            PaymentEventType::Captured => $this->assertCapturedDone($attempt, $order, $inbox),
            PaymentEventType::Failed => $this->assertFailedDone($attempt, $inbox),
            PaymentEventType::Cancelled => $this->assertCancelledDone($attempt, $inbox),
            PaymentEventType::RefundSucceeded => $this->assertRefundSucceededDone($attempt),
            PaymentEventType::RefundFailed => $this->assertRefundFailedDone($attempt),
            PaymentEventType::RefundRequested => throw CommerceException::invalidInput(
                'refund_requested is not accepted from webhook; use refund manager.',
            ),
        };
    }

    private function assertAuthorizedDone(PaymentAttempt $attempt, PaymentWebhookInboxEvent $inbox): void
    {
        if (!\in_array($attempt->getStatus(), [
            PaymentAttemptStatus::Authorized,
            PaymentAttemptStatus::Captured,
        ], true)) {
            throw CommerceException::invalidTransition();
        }
        $event = $this->findMatchingEvent($attempt, $inbox);
        if (!$event instanceof PaymentEvent && PaymentAttemptStatus::Authorized === $attempt->getStatus()) {
            throw CommerceException::notFound();
        }
        if ($event instanceof PaymentEvent && PaymentEventType::Authorized !== $event->getEventType()
            && PaymentAttemptStatus::Captured !== $attempt->getStatus()
        ) {
            throw CommerceException::webhookIntegrityConflict();
        }
    }

    private function assertCapturedDone(
        PaymentAttempt $attempt,
        CommerceOrder $order,
        PaymentWebhookInboxEvent $inbox,
    ): void {
        if (PaymentAttemptStatus::Captured !== $attempt->getStatus()) {
            throw CommerceException::paymentNotCaptured();
        }
        $event = $this->findMatchingEvent($attempt, $inbox);
        if (!$event instanceof PaymentEvent || PaymentEventType::Captured !== $event->getEventType()) {
            throw CommerceException::webhookIntegrityConflict();
        }
        if ($this->autoFulfillOnCapture) {
            if ($this->fulfillments->countCompletedForPaymentAttempt($attempt->getId()) < 1) {
                throw CommerceException::notFound();
            }
            if (CommerceOrderStatus::Paid !== $order->getStatus()) {
                throw CommerceException::invalidTransition();
            }
        }
    }

    private function assertFailedDone(PaymentAttempt $attempt, PaymentWebhookInboxEvent $inbox): void
    {
        if (PaymentAttemptStatus::Failed !== $attempt->getStatus()
            && PaymentAttemptStatus::Cancelled !== $attempt->getStatus()
            && PaymentAttemptStatus::Captured !== $attempt->getStatus()
        ) {
            throw CommerceException::invalidTransition();
        }
        if (PaymentAttemptStatus::Captured === $attempt->getStatus()) {
            return;
        }
        $event = $this->findMatchingEvent($attempt, $inbox);
        if (!$event instanceof PaymentEvent && PaymentAttemptStatus::Failed === $attempt->getStatus()) {
            // Terminal failure without this event is acceptable no-op convergence.
            return;
        }
    }

    private function assertCancelledDone(PaymentAttempt $attempt, PaymentWebhookInboxEvent $inbox): void
    {
        if (PaymentAttemptStatus::Cancelled !== $attempt->getStatus()
            && PaymentAttemptStatus::Failed !== $attempt->getStatus()
            && PaymentAttemptStatus::Captured !== $attempt->getStatus()
        ) {
            throw CommerceException::invalidTransition();
        }
        unset($inbox);
    }

    private function assertRefundSucceededDone(PaymentAttempt $attempt): void
    {
        $reserved = $this->refunds->reservedAmountMinor($attempt->getId());
        if ($reserved < 1) {
            throw CommerceException::notFound();
        }
        if ($reserved > $attempt->getAmountMinor()) {
            throw CommerceException::refundExceedsCapture();
        }
    }

    private function assertRefundFailedDone(PaymentAttempt $attempt): void
    {
        $openOrFailed = $this->refunds->findOpenForAttempt($attempt->getId());
        if ($openOrFailed instanceof PaymentRefund && PaymentRefundStatus::Requested === $openOrFailed->getStatus()) {
            throw CommerceException::invalidTransition();
        }
    }

    private function finalizeProcessed(Uuid $inboxEventId, Uuid $claimToken): PaymentWebhookInboxEvent
    {
        return $this->em()->wrapInTransaction(function () use ($inboxEventId, $claimToken): PaymentWebhookInboxEvent {
            $inboxEvent = $this->inbox->findFreshForUpdate($inboxEventId);
            if (!$inboxEvent instanceof PaymentWebhookInboxEvent) {
                throw CommerceException::notFound();
            }
            if (PaymentWebhookInboxStatus::Processed === $inboxEvent->getProcessingStatus()) {
                return $inboxEvent;
            }

            $now = UtcInstant::ensure($this->clock->now());
            $this->checkpoint->before('before_processed_audit');
            $inboxEvent->markProcessed($claimToken, $now);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::PaymentWebhookProcessed,
                actorType: SecurityAuditActorType::System,
                outcome: SecurityAuditOutcome::Success,
                metadata: [
                    'source' => 'payment_webhook_processor',
                    'reason_code' => 'processed',
                    'provider_code' => $inboxEvent->getProviderCode(),
                    'provider_environment' => $inboxEvent->getEnvironment()->value,
                    'event_type' => $inboxEvent->getEventType()->value,
                    'inbox_event_id' => $inboxEvent->getId()->toRfc4122(),
                    'payment_attempt_id' => $inboxEvent->getPaymentAttempt()?->getId()->toRfc4122(),
                    'processing_status' => PaymentWebhookInboxStatus::Processed->value,
                    'attempt_count' => $inboxEvent->getAttemptCount(),
                ],
                captureRequestHashes: false,
            ), false);
            $this->em()->flush();

            return $inboxEvent;
        });
    }

    private function handleProcessingFailure(Uuid $inboxEventId, Uuid $claimToken, CommerceException $e): void
    {
        // Domain managers use wrapInTransaction, which closes the EM on failure.
        $this->em();

        if ($this->isIntegrityFailure($e) && !$this->hasRecoverableCaptureProgress($inboxEventId)) {
            $this->rejectSafe($inboxEventId, $claimToken, $e->getReason()->value, true);

            return;
        }
        if ($this->isPermanentBusinessReject($e) && !$this->hasRecoverableCaptureProgress($inboxEventId)) {
            $this->rejectSafe($inboxEventId, $claimToken, $e->getReason()->value, false);

            return;
        }

        $this->scheduleRetrySafe($inboxEventId, $claimToken, $e->getReason()->value);
    }

    private function hasRecoverableCaptureProgress(Uuid $inboxEventId): bool
    {
        $inbox = $this->inbox->findOneById($inboxEventId);
        if (!$inbox instanceof PaymentWebhookInboxEvent
            || PaymentEventType::Captured !== $inbox->getEventType()
            || !$inbox->getPaymentAttempt() instanceof PaymentAttempt
        ) {
            return false;
        }

        $attemptId = $inbox->getPaymentAttempt()->getId();
        if ($this->events->findOneForAttemptByProviderEventReference(
            $attemptId,
            $inbox->getProviderEventReference(),
        ) instanceof PaymentEvent) {
            return true;
        }

        $status = $this->em()->getConnection()->fetchOne(
            'SELECT status FROM payment_attempts WHERE id = ?',
            [$attemptId->toBinary()],
        );

        return PaymentAttemptStatus::Captured->value === $status;
    }

    private function rejectSafe(Uuid $inboxEventId, Uuid $claimToken, string $reasonCode, bool $integrity): void
    {
        try {
            $this->em()->wrapInTransaction(function () use ($inboxEventId, $claimToken, $reasonCode, $integrity): void {
                $inboxEvent = $this->inbox->findFreshForUpdate($inboxEventId);
                if (!$inboxEvent instanceof PaymentWebhookInboxEvent
                    || $inboxEvent->getProcessingStatus()->isTerminal()
                ) {
                    return;
                }
                $now = UtcInstant::ensure($this->clock->now());
                $inboxEvent->markRejected($reasonCode, $now, $claimToken);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $integrity
                        ? SecurityAuditAction::PaymentWebhookIntegrityFailed
                        : SecurityAuditAction::PaymentWebhookRejected,
                    actorType: SecurityAuditActorType::System,
                    outcome: SecurityAuditOutcome::Failure,
                    metadata: [
                        'source' => 'payment_webhook_processor',
                        'reason_code' => $reasonCode,
                        'provider_code' => $inboxEvent->getProviderCode(),
                        'provider_environment' => $inboxEvent->getEnvironment()->value,
                        'event_type' => $inboxEvent->getEventType()->value,
                        'inbox_event_id' => $inboxEvent->getId()->toRfc4122(),
                        'payment_attempt_id' => $inboxEvent->getPaymentAttempt()?->getId()->toRfc4122(),
                        'processing_status' => PaymentWebhookInboxStatus::Rejected->value,
                        'attempt_count' => $inboxEvent->getAttemptCount(),
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->em()->flush();
            });
        } catch (\Throwable) {
            // Failure handling must not mask the original exception.
        }
    }

    private function scheduleRetrySafe(Uuid $inboxEventId, Uuid $claimToken, string $reasonCode): void
    {
        try {
            $this->em()->wrapInTransaction(function () use ($inboxEventId, $claimToken, $reasonCode): void {
                $inboxEvent = $this->inbox->findFreshForUpdate($inboxEventId);
                if (!$inboxEvent instanceof PaymentWebhookInboxEvent
                    || $inboxEvent->getProcessingStatus()->isTerminal()
                ) {
                    return;
                }
                $now = UtcInstant::ensure($this->clock->now());
                $inboxEvent->scheduleRetry($reasonCode, $now, $claimToken);
                $action = PaymentWebhookInboxStatus::DeadLetter === $inboxEvent->getProcessingStatus()
                    ? SecurityAuditAction::PaymentWebhookRejected
                    : SecurityAuditAction::PaymentWebhookReceived;
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::System,
                    outcome: SecurityAuditOutcome::Failure,
                    metadata: [
                        'source' => 'payment_webhook_processor',
                        'reason_code' => PaymentWebhookInboxStatus::DeadLetter === $inboxEvent->getProcessingStatus()
                            ? CommerceFailureReason::WebhookRetryExhausted->value
                            : $reasonCode,
                        'provider_code' => $inboxEvent->getProviderCode(),
                        'provider_environment' => $inboxEvent->getEnvironment()->value,
                        'event_type' => $inboxEvent->getEventType()->value,
                        'inbox_event_id' => $inboxEvent->getId()->toRfc4122(),
                        'payment_attempt_id' => $inboxEvent->getPaymentAttempt()?->getId()->toRfc4122(),
                        'processing_status' => $inboxEvent->getProcessingStatus()->value,
                        'attempt_count' => $inboxEvent->getAttemptCount(),
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->em()->flush();
            });
        } catch (\Throwable) {
            // Failure handling must not mask the original exception.
        }
    }

    private function findOrRequestRefund(
        PaymentAttempt $attempt,
        User $actor,
        Money $amount,
        string $requestKey,
    ): PaymentRefund {
        $existing = $this->refundManager->findByIdempotencyKey($requestKey);
        if ($existing instanceof PaymentRefund) {
            return $existing;
        }

        return $this->refundManager->request(
            $attempt,
            $actor,
            $amount,
            PaymentRefundReasonCode::PurchaserRequested,
            $requestKey,
            'webhook_refund_request',
        );
    }

    private function assertProviderAndOrderScope(PaymentWebhookInboxEvent $inbox, PaymentAttempt $attempt): void
    {
        // No wrapInTransaction here: Doctrine closes the EM on transactional failure, but we still
        // need the EM to mark inbox rejected/retry_pending. Domain managers own locking TXs.
        $orderId = $attempt->getOrder()->getId();
        $lockedOrder = $this->freshCommerce->findFreshOrder($orderId, LockMode::NONE);
        if (!$lockedOrder instanceof CommerceOrder) {
            throw CommerceException::notFound();
        }
        if ($lockedOrder->getInstitution() instanceof Institution) {
            $institution = $this->freshEntities->findFreshLockedInstitution(
                $lockedOrder->getInstitution()->getId(),
                LockMode::NONE,
            );
            if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
                throw CommerceException::notFound();
            }
        }
        $lockedAttempt = $this->requireFreshAttempt($attempt->getId(), LockMode::NONE);
        if (!$lockedAttempt->getOrder()->getId()->equals($lockedOrder->getId())) {
            throw CommerceException::scopeMismatch();
        }
        if ($inbox->getProviderCode() !== $lockedAttempt->getProviderCode()
            || $inbox->getEnvironment() !== $lockedAttempt->getEnvironment()
        ) {
            throw CommerceException::providerMismatch();
        }
        $ref = $inbox->getSanitizedMetadata()['order_public_reference'] ?? null;
        if (\is_string($ref) && '' !== $ref && $ref !== $lockedOrder->getPublicReference()) {
            throw CommerceException::webhookIntegrityConflict();
        }
        if ($inbox->getEventType()->requiresAmount()) {
            if (\in_array($inbox->getEventType(), [
                PaymentEventType::RefundSucceeded,
                PaymentEventType::RefundRequested,
                PaymentEventType::RefundFailed,
            ], true)) {
                $this->requireRefundMoney($inbox, $lockedAttempt);
            } else {
                $this->requireMoney($inbox, $lockedAttempt);
            }
        }
    }

    private function findMatchingEvent(PaymentAttempt $attempt, PaymentWebhookInboxEvent $inbox): ?PaymentEvent
    {
        return $this->events->findOneForAttemptByProviderEventReference(
            $attempt->getId(),
            $inbox->getProviderEventReference(),
        );
    }

    private function requireInbox(Uuid $id): PaymentWebhookInboxEvent
    {
        $inbox = $this->inbox->findOneById($id);
        if (!$inbox instanceof PaymentWebhookInboxEvent) {
            throw CommerceException::notFound();
        }

        return $inbox;
    }

    private function requireAttempt(PaymentWebhookInboxEvent $inbox): PaymentAttempt
    {
        $attempt = $inbox->getPaymentAttempt();
        if (!$attempt instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }

        return $attempt;
    }

    private function requireFreshAttempt(Uuid $id, LockMode $lockMode = LockMode::NONE): PaymentAttempt
    {
        $attempt = $this->freshCommerce->findFreshPaymentAttempt($id, $lockMode);
        if (!$attempt instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }

        return $attempt;
    }

    private function requireMoney(PaymentWebhookInboxEvent $inbox, PaymentAttempt $attempt): Money
    {
        $meta = $inbox->getSanitizedMetadata();
        if (isset($meta['amount_minor'], $meta['currency'])
            && \is_int($meta['amount_minor'])
            && \is_string($meta['currency'])
        ) {
            $money = Money::fromMinor($meta['amount_minor'], strtoupper($meta['currency']));
            if ($money->getCurrency() !== $attempt->getCurrency()) {
                throw CommerceException::currencyMismatch();
            }
            if (!$money->equals($attempt->getAmount())) {
                throw CommerceException::totalMismatch();
            }

            return $money;
        }

        return $attempt->getAmount();
    }

    private function requireRefundMoney(PaymentWebhookInboxEvent $inbox, PaymentAttempt $attempt): Money
    {
        $meta = $inbox->getSanitizedMetadata();
        if (!isset($meta['amount_minor'], $meta['currency'])
            || !\is_int($meta['amount_minor'])
            || !\is_string($meta['currency'])
        ) {
            throw CommerceException::invalidInput('Refund webhook amount_minor/currency required.');
        }
        $money = Money::fromMinor($meta['amount_minor'], strtoupper($meta['currency']));
        if ($money->getCurrency() !== $attempt->getCurrency()) {
            throw CommerceException::currencyMismatch();
        }
        if ($money->getAmountMinor() < 1 || $money->getAmountMinor() > $attempt->getAmountMinor()) {
            throw CommerceException::refundExceedsCapture();
        }

        return $money;
    }

    private function readVerifiedProviderPaymentReference(PaymentWebhookInboxEvent $inbox): ?string
    {
        $value = $inbox->getSanitizedMetadata()['provider_payment_reference'] ?? null;

        return \is_string($value) ? $value : null;
    }

    private function readVerifiedAuthorizationReference(PaymentWebhookInboxEvent $inbox): ?string
    {
        $value = $inbox->getSanitizedMetadata()['provider_authorization_reference'] ?? null;

        return \is_string($value) ? $value : null;
    }

    private function readVerifiedRefundReference(PaymentWebhookInboxEvent $inbox): ?string
    {
        $value = $inbox->getSanitizedMetadata()['provider_refund_reference'] ?? null;

        return \is_string($value) ? $value : null;
    }

    private function readFailureCode(PaymentWebhookInboxEvent $inbox): string
    {
        $value = $inbox->getSanitizedMetadata()['failure_code'] ?? null;

        return \is_string($value) && '' !== $value ? $value : 'provider_declined';
    }

    /**
     * @param array<string, bool|int|string|null> $meta
     */
    private function metaString(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, bool|int|string|null> $meta
     *
     * @return array<string, bool|int|string|null>
     */
    private function settlementSafeMetadata(array $meta): array
    {
        $allowed = [
            'provider_code',
            'provider_environment',
            'provider_status_code',
            'provider_error_code',
            'settlement_currency',
            'settlement_amount_minor',
            'installment_count',
            'is_three_d_secure',
            'retry_count',
            'sequence_number',
            'event_source',
        ];
        $clean = [];
        foreach ($allowed as $key) {
            if (\array_key_exists($key, $meta)) {
                $clean[$key] = $meta[$key];
            }
        }

        return $clean;
    }

    private function settlementIdempotencyKey(PaymentWebhookInboxEvent $inbox): string
    {
        return 'wh:'.$inbox->getProviderCode().':'.$inbox->getEnvironment()->value.':'.$inbox->getProviderEventReference();
    }

    private function isIntegrityFailure(CommerceException $e): bool
    {
        return \in_array($e->getReason(), [
            CommerceFailureReason::WebhookIntegrityConflict,
            CommerceFailureReason::HashMismatch,
            CommerceFailureReason::TotalMismatch,
            CommerceFailureReason::CurrencyMismatch,
            CommerceFailureReason::ProviderMismatch,
            CommerceFailureReason::ScopeMismatch,
        ], true);
    }

    private function isPermanentBusinessReject(CommerceException $e): bool
    {
        return \in_array($e->getReason(), [
            CommerceFailureReason::InvalidTransition,
            CommerceFailureReason::InvalidInput,
            CommerceFailureReason::NotFound,
            CommerceFailureReason::PaymentNotCaptured,
            CommerceFailureReason::RefundExceedsCapture,
        ], true);
    }
}
