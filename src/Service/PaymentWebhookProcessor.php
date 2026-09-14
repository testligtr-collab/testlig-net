<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Institution;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentRefund;
use App\Entity\PaymentWebhookInboxEvent;
use App\Entity\User;
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
use App\Repository\PaymentEventRepository;
use App\Repository\PaymentRefundRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Deterministic processing of verified webhook inbox rows into settlement/refund/fulfillment.
 *
 * Never mutates attempt/order via entity setters directly — only domain managers.
 * Out-of-order policy: late authorize after capture is a no-op success; capture after
 * terminal failure is rejected; duplicates are idempotent.
 *
 * Lock order: Institution → purchaser/actor → CommerceOrder → PaymentAttempt →
 * WebhookInboxEvent → PaymentEvent/Refund → Fulfillment/License → Audit.
 */
final class PaymentWebhookProcessor
{
    public function __construct(
        private readonly PaymentSettlementManager $settlementManager,
        private readonly PaymentRefundManager $refundManager,
        private readonly CommerceFulfillmentManager $fulfillmentManager,
        private readonly PaymentPlatformSettlementActorResolver $settlementActors,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly PaymentEventRepository $events,
        private readonly PaymentRefundRepository $refunds,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly bool $autoFulfillOnCapture = true,
    ) {
    }

    public function process(Uuid $inboxEventId): PaymentWebhookInboxEvent
    {
        try {
            $prepared = $this->prepareForProcessing($inboxEventId);
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }

        if ($prepared['done'] instanceof PaymentWebhookInboxEvent) {
            return $prepared['done'];
        }

        if (!isset($prepared['context'], $prepared['inboxId'])) {
            throw CommerceException::conflict();
        }

        $context = $prepared['context'];
        $inboxId = $prepared['inboxId'];

        try {
            // Domain managers open their own transactions — do not nest them under an outer TX
            // (MariaDB savepoint depth breaks under refund → settlement nesting).
            $this->applyValidatedEffects($context);
        } catch (CommerceException $e) {
            $this->finalizeRejected($inboxId, $e);
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }

        try {
            return $this->finalizeProcessed($inboxId);
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    /**
     * @return array{done: ?PaymentWebhookInboxEvent, context?: array{inbox: PaymentWebhookInboxEvent, attempt: PaymentAttempt, actor: User, skipMutation: bool}, inboxId?: Uuid}
     */
    private function prepareForProcessing(Uuid $inboxEventId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($inboxEventId): array {
            $inboxEvent = $this->findFreshInbox($inboxEventId, LockMode::PESSIMISTIC_WRITE);
            if (!$inboxEvent instanceof PaymentWebhookInboxEvent) {
                throw CommerceException::notFound();
            }
            if (PaymentWebhookInboxStatus::Processed === $inboxEvent->getProcessingStatus()
                || $inboxEvent->getProcessingStatus()->isTerminal()
            ) {
                return ['done' => $inboxEvent];
            }

            $now = UtcInstant::ensure($this->clock->now());
            if (PaymentWebhookInboxStatus::Received === $inboxEvent->getProcessingStatus()) {
                $inboxEvent->markProcessing($now);
                $this->entityManager->flush();
            }

            try {
                $context = $this->lockAndValidate($inboxEvent);
            } catch (CommerceException $e) {
                if ($this->isIntegrityFailure($e) || $this->isRejectableBusinessFailure($e)) {
                    $inboxEvent->markRejected($e->getReason()->value, $now);
                    if ($this->isIntegrityFailure($e)) {
                        $this->auditIntegrity($inboxEvent, $e->getReason()->value);
                    } else {
                        $this->auditRejected($inboxEvent, $e->getReason()->value);
                    }
                    $this->entityManager->flush();

                    return ['done' => $inboxEvent];
                }
                throw $e;
            }

            return [
                'done' => null,
                'context' => $context,
                'inboxId' => $inboxEvent->getId(),
            ];
        });
    }

    private function finalizeProcessed(Uuid $inboxEventId): PaymentWebhookInboxEvent
    {
        return $this->entityManager->wrapInTransaction(function () use ($inboxEventId): PaymentWebhookInboxEvent {
            $inboxEvent = $this->findFreshInbox($inboxEventId, LockMode::PESSIMISTIC_WRITE);
            if (!$inboxEvent instanceof PaymentWebhookInboxEvent) {
                throw CommerceException::notFound();
            }
            if (PaymentWebhookInboxStatus::Processed === $inboxEvent->getProcessingStatus()
                || $inboxEvent->getProcessingStatus()->isTerminal()
            ) {
                return $inboxEvent;
            }

            $now = UtcInstant::ensure($this->clock->now());
            $inboxEvent->markProcessed($now);
            $this->auditProcessed($inboxEvent, 'processed');
            $this->entityManager->flush();

            return $inboxEvent;
        });
    }

    private function finalizeRejected(Uuid $inboxEventId, CommerceException $e): void
    {
        if (!$this->isIntegrityFailure($e) && !$this->isRejectableBusinessFailure($e)) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($inboxEventId, $e): void {
            $inboxEvent = $this->findFreshInbox($inboxEventId, LockMode::PESSIMISTIC_WRITE);
            if (!$inboxEvent instanceof PaymentWebhookInboxEvent
                || $inboxEvent->getProcessingStatus()->isTerminal()
            ) {
                return;
            }
            $now = UtcInstant::ensure($this->clock->now());
            $inboxEvent->markRejected($e->getReason()->value, $now);
            if ($this->isIntegrityFailure($e)) {
                $this->auditIntegrity($inboxEvent, $e->getReason()->value);
            } else {
                $this->auditRejected($inboxEvent, $e->getReason()->value);
            }
            $this->entityManager->flush();
        });
    }

    /**
     * @return array{
     *     inbox: PaymentWebhookInboxEvent,
     *     attempt: PaymentAttempt,
     *     actor: User,
     *     skipMutation: bool
     * }
     */
    private function lockAndValidate(PaymentWebhookInboxEvent $inboxEvent): array
    {
        $attempt = $inboxEvent->getPaymentAttempt();
        if (!$attempt instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }

        $orderId = $attempt->getOrder()->getId();
        $attemptId = $attempt->getId();

        $lockedOrder = $this->freshCommerce->findFreshOrder($orderId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedOrder instanceof \App\Entity\CommerceOrder) {
            throw CommerceException::notFound();
        }
        if ($lockedOrder->getInstitution() instanceof Institution) {
            $institution = $this->freshEntities->findFreshLockedInstitution(
                $lockedOrder->getInstitution()->getId(),
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
                throw CommerceException::notFound();
            }
        }

        $lockedAttempt = $this->freshCommerce->findFreshPaymentAttempt($attemptId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedAttempt instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }
        if (!$lockedAttempt->getOrder()->getId()->equals($lockedOrder->getId())) {
            throw CommerceException::scopeMismatch();
        }

        $this->assertProviderScope($inboxEvent, $lockedAttempt);
        $this->assertOrderReference($inboxEvent, $lockedOrder);
        if ($inboxEvent->getEventType()->requiresAmount()) {
            if (\in_array($inboxEvent->getEventType(), [
                PaymentEventType::RefundSucceeded,
                PaymentEventType::RefundRequested,
                PaymentEventType::RefundFailed,
            ], true)) {
                $this->requireRefundMoney($inboxEvent, $lockedAttempt);
            } else {
                $this->requireMoney($inboxEvent, $lockedAttempt);
            }
        }

        $existingEvent = $this->events->findOneForAttemptByProviderEventReference(
            $lockedAttempt->getId(),
            $inboxEvent->getProviderEventReference(),
        );
        if ($existingEvent instanceof \App\Entity\PaymentEvent) {
            if ($existingEvent->getEventType() !== $inboxEvent->getEventType()) {
                throw CommerceException::webhookIntegrityConflict();
            }

            return [
                'inbox' => $inboxEvent,
                'attempt' => $lockedAttempt,
                'actor' => $this->settlementActors->resolve(),
                'skipMutation' => true,
            ];
        }

        $eventType = $inboxEvent->getEventType();
        if (PaymentEventType::Authorized === $eventType
            && \in_array($lockedAttempt->getStatus(), [
                PaymentAttemptStatus::Authorized,
                PaymentAttemptStatus::Captured,
            ], true)
        ) {
            return [
                'inbox' => $inboxEvent,
                'attempt' => $lockedAttempt,
                'actor' => $this->settlementActors->resolve(),
                'skipMutation' => true,
            ];
        }
        if (PaymentEventType::Captured === $eventType
            && \in_array($lockedAttempt->getStatus(), [
                PaymentAttemptStatus::Failed,
                PaymentAttemptStatus::Cancelled,
                PaymentAttemptStatus::Captured,
            ], true)
        ) {
            throw CommerceException::invalidTransition();
        }
        if (\in_array($eventType, [PaymentEventType::Failed, PaymentEventType::Cancelled], true)
            && $lockedAttempt->getStatus()->isTerminal()
        ) {
            return [
                'inbox' => $inboxEvent,
                'attempt' => $lockedAttempt,
                'actor' => $this->settlementActors->resolve(),
                'skipMutation' => true,
            ];
        }
        if (PaymentEventType::RefundRequested === $eventType) {
            throw CommerceException::invalidInput('refund_requested is not accepted from webhook; use refund manager.');
        }

        return [
            'inbox' => $inboxEvent,
            'attempt' => $lockedAttempt,
            'actor' => $this->settlementActors->resolve(),
            'skipMutation' => false,
        ];
    }

    /**
     * @param array{
     *     inbox: PaymentWebhookInboxEvent,
     *     attempt: PaymentAttempt,
     *     actor: User,
     *     skipMutation: bool
     * } $context
     */
    private function applyValidatedEffects(array $context): void
    {
        if ($context['skipMutation']) {
            if ($this->autoFulfillOnCapture
                && PaymentEventType::Captured === $context['inbox']->getEventType()
            ) {
                $this->fulfillCapturedIfNeeded(
                    $context['attempt'],
                    $context['actor'],
                    $context['inbox'],
                );
            }

            return;
        }

        $inboxEvent = $context['inbox'];
        $lockedAttempt = $context['attempt'];
        $settlementActor = $context['actor'];
        $eventType = $inboxEvent->getEventType();
        $meta = $inboxEvent->getSanitizedMetadata();
        $settlementKey = $this->settlementIdempotencyKey($inboxEvent);

        match ($eventType) {
            PaymentEventType::Authorized => $this->applyAuthorized(
                $lockedAttempt,
                $settlementActor,
                $inboxEvent,
                $settlementKey,
                $meta,
            ),
            PaymentEventType::Captured => $this->applyCaptured(
                $lockedAttempt,
                $settlementActor,
                $inboxEvent,
                $settlementKey,
                $meta,
            ),
            PaymentEventType::Failed => $this->applyFailed(
                $lockedAttempt,
                $settlementActor,
                $inboxEvent,
                $settlementKey,
                $meta,
            ),
            PaymentEventType::Cancelled => $this->applyCancelled(
                $lockedAttempt,
                $settlementActor,
                $inboxEvent,
                $settlementKey,
                $meta,
            ),
            PaymentEventType::RefundSucceeded => $this->applyRefundSucceeded(
                $lockedAttempt,
                $settlementActor,
                $inboxEvent,
                $settlementKey,
            ),
            PaymentEventType::RefundFailed => $this->applyRefundFailed(
                $lockedAttempt,
                $settlementActor,
                $inboxEvent,
                $settlementKey,
            ),
            PaymentEventType::RefundRequested => throw CommerceException::invalidInput(
                'refund_requested is not accepted from webhook; use refund manager.',
            ),
        };
    }

    /**
     * @param array<string, bool|int|string|null> $meta
     */
    private function applyAuthorized(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
        array $meta,
    ): void {
        if (\in_array($attempt->getStatus(), [
            PaymentAttemptStatus::Authorized,
            PaymentAttemptStatus::Captured,
        ], true)) {
            // Late authorize after capture/authorize: do not move state backwards.
            return;
        }
        if ($attempt->getStatus()->isTerminal()) {
            throw CommerceException::invalidTransition();
        }

        $amount = $inbox->getSanitizedMetadata()['amount_minor'] ?? null;
        unset($amount);
        $money = $this->requireMoney($inbox, $attempt);

        $this->settlementManager->recordAuthorized(
            $attempt,
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
    private function applyCaptured(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
        array $meta,
    ): void {
        if (PaymentAttemptStatus::Captured === $attempt->getStatus()) {
            throw CommerceException::invalidTransition();
        }
        if (\in_array($attempt->getStatus(), [
            PaymentAttemptStatus::Failed,
            PaymentAttemptStatus::Cancelled,
        ], true)) {
            throw CommerceException::invalidTransition();
        }

        $money = $this->requireMoney($inbox, $attempt);
        $this->settlementManager->recordCaptured(
            $attempt,
            $actor,
            $money,
            $inbox->getProviderOccurredAt(),
            $settlementKey,
            'webhook_captured',
            $this->readVerifiedProviderPaymentReference($inbox) ?? $attempt->getProviderPaymentReference(),
            $inbox->getProviderEventReference(),
            $this->settlementSafeMetadata($meta),
        );

        if ($this->autoFulfillOnCapture) {
            $this->fulfillCapturedIfNeeded($attempt, $actor, $inbox);
        }
    }

    private function fulfillCapturedIfNeeded(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
    ): void {
        $freshAttempt = $this->freshCommerce->findFreshPaymentAttempt(
            $attempt->getId(),
            LockMode::NONE,
        );
        $freshOrder = $this->freshCommerce->findFreshOrder(
            $attempt->getOrder()->getId(),
            LockMode::NONE,
        );
        if ($freshAttempt instanceof PaymentAttempt
            && $freshOrder instanceof \App\Entity\CommerceOrder
            && PaymentAttemptStatus::Captured === $freshAttempt->getStatus()
        ) {
            $this->fulfillmentManager->fulfill(
                $freshOrder,
                $freshAttempt,
                $actor,
                'webhook:fulfill:'.$inbox->getId()->toRfc4122(),
                'webhook_fulfill',
            );
        }
    }

    /**
     * @param array<string, bool|int|string|null> $meta
     */
    private function applyFailed(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
        array $meta,
    ): void {
        if ($attempt->getStatus()->isTerminal()) {
            return;
        }
        $this->settlementManager->recordFailed(
            $attempt,
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
    private function applyCancelled(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
        array $meta,
    ): void {
        if ($attempt->getStatus()->isTerminal()) {
            return;
        }
        $this->settlementManager->recordCancelled(
            $attempt,
            $actor,
            $this->readFailureCode($inbox),
            $inbox->getProviderOccurredAt(),
            $settlementKey,
            'webhook_cancelled',
            $inbox->getProviderEventReference(),
            $this->settlementSafeMetadata($meta),
        );
    }

    private function applyRefundSucceeded(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
    ): void {
        $money = $this->requireRefundMoney($inbox, $attempt);
        $refund = $this->findOrRequestRefund($attempt, $actor, $money, $settlementKey.'.req');
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

    private function applyRefundFailed(
        PaymentAttempt $attempt,
        User $actor,
        PaymentWebhookInboxEvent $inbox,
        string $settlementKey,
    ): void {
        $money = $inbox->getSanitizedMetadata();
        unset($money);
        // Prefer matching open refund; if none, reject — do not invent refund rows from failures alone.
        $open = $this->refunds->findOpenForAttempt($attempt->getId());
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

    private function assertProviderScope(PaymentWebhookInboxEvent $inbox, PaymentAttempt $attempt): void
    {
        if ($inbox->getProviderCode() !== $attempt->getProviderCode()
            || $inbox->getEnvironment() !== $attempt->getEnvironment()
        ) {
            throw CommerceException::providerMismatch();
        }
    }

    private function assertOrderReference(PaymentWebhookInboxEvent $inbox, \App\Entity\CommerceOrder $order): void
    {
        $ref = $inbox->getSanitizedMetadata()['order_public_reference'] ?? null;
        if (\is_string($ref) && '' !== $ref && $ref !== $order->getPublicReference()) {
            throw CommerceException::webhookIntegrityConflict();
        }
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

    private function requireMoney(PaymentWebhookInboxEvent $inbox, PaymentAttempt $attempt): Money
    {
        // Prefer server-side attempt amount; webhook amount must match when provided via
        // a parallel lookup of the verified parse is not re-stored — use attempt money and
        // rely on settlement manager equality checks. Integrity for mismatched webhook amounts
        // is enforced by storing amount in sanitized metadata only when parser put scalars.
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

    private function findFreshInbox(Uuid $id, LockMode $lockMode): ?PaymentWebhookInboxEvent
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(PaymentWebhookInboxEvent::class, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode);

        $result = $query->getOneOrNullResult();

        return $result instanceof PaymentWebhookInboxEvent ? $result : null;
    }

    private function isIntegrityFailure(CommerceException $e): bool
    {
        return \in_array($e->getReason(), [
            \App\Enum\CommerceFailureReason::WebhookIntegrityConflict,
            \App\Enum\CommerceFailureReason::HashMismatch,
            \App\Enum\CommerceFailureReason::TotalMismatch,
            \App\Enum\CommerceFailureReason::CurrencyMismatch,
            \App\Enum\CommerceFailureReason::ProviderMismatch,
            \App\Enum\CommerceFailureReason::ScopeMismatch,
        ], true);
    }

    private function isRejectableBusinessFailure(CommerceException $e): bool
    {
        return \in_array($e->getReason(), [
            \App\Enum\CommerceFailureReason::NotFound,
            \App\Enum\CommerceFailureReason::InvalidTransition,
            \App\Enum\CommerceFailureReason::InvalidInput,
            \App\Enum\CommerceFailureReason::PaymentNotCaptured,
            \App\Enum\CommerceFailureReason::RefundExceedsCapture,
        ], true);
    }

    private function auditProcessed(PaymentWebhookInboxEvent $event, string $reasonCode): void
    {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::PaymentWebhookProcessed,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Success,
            metadata: [
                'source' => 'payment_webhook_processor',
                'reason_code' => $reasonCode,
                'provider_code' => $event->getProviderCode(),
                'provider_environment' => $event->getEnvironment()->value,
                'event_type' => $event->getEventType()->value,
                'inbox_event_id' => $event->getId()->toRfc4122(),
                'payment_attempt_id' => $event->getPaymentAttempt()?->getId()->toRfc4122(),
                'processing_status' => PaymentWebhookInboxStatus::Processed->value,
            ],
            captureRequestHashes: false,
        ), false);
    }

    private function auditRejected(PaymentWebhookInboxEvent $event, string $reasonCode): void
    {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::PaymentWebhookRejected,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Failure,
            metadata: [
                'source' => 'payment_webhook_processor',
                'reason_code' => $reasonCode,
                'provider_code' => $event->getProviderCode(),
                'provider_environment' => $event->getEnvironment()->value,
                'event_type' => $event->getEventType()->value,
                'inbox_event_id' => $event->getId()->toRfc4122(),
                'payment_attempt_id' => $event->getPaymentAttempt()?->getId()->toRfc4122(),
                'processing_status' => PaymentWebhookInboxStatus::Rejected->value,
            ],
            captureRequestHashes: false,
        ), false);
    }

    private function auditIntegrity(PaymentWebhookInboxEvent $event, string $reasonCode): void
    {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::PaymentWebhookIntegrityFailed,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Failure,
            metadata: [
                'source' => 'payment_webhook_processor',
                'reason_code' => $reasonCode,
                'provider_code' => $event->getProviderCode(),
                'provider_environment' => $event->getEnvironment()->value,
                'event_type' => $event->getEventType()->value,
                'inbox_event_id' => $event->getId()->toRfc4122(),
                'payment_attempt_id' => $event->getPaymentAttempt()?->getId()->toRfc4122(),
                'processing_status' => PaymentWebhookInboxStatus::Rejected->value,
            ],
            captureRequestHashes: false,
        ), false);
    }
}
