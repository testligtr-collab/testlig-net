<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Commerce\CommerceInputNormalizer;
use App\Commerce\PaymentEventHasher;
use App\Commerce\PaymentEventMetadataSanitizer;
use App\Dto\SecurityAuditContext;
use App\Entity\CommerceOrder;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentEvent;
use App\Entity\User;
use App\Enum\CommerceOrderStatus;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentEventType;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\PaymentEventRepository;
use App\Security\CommerceAuthorization;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Records provider-neutral settlement events (authorize / capture / fail / cancel) on the
 * append-only payment event log and moves the attempt status accordingly.
 *
 * Capture deliberately does **not** grant entitlements: CommerceFulfillmentManager is the
 * only place an AccessLicense is minted, and it re-verifies everything first.
 *
 * Lock order: CommerceOrder → PaymentAttempt → PaymentEvent → Audit
 */
final class PaymentSettlementManager
{
    public const IDEMPOTENCY_SCOPE = 'payment_event';

    public function __construct(
        private readonly PaymentEventRepository $events,
        private readonly CommerceAuthorization $authorization,
        private readonly CommerceIdempotencyKeyHasher $idempotencyHasher,
        private readonly PaymentEventHasher $eventHasher,
        private readonly PaymentEventMetadataSanitizer $metadataSanitizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function recordAuthorized(
        PaymentAttempt $attempt,
        User $settlementActor,
        Money $amount,
        \DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        string $reasonCode,
        ?string $providerPaymentReference = null,
        ?string $providerAuthorizationReference = null,
        ?string $providerEventReference = null,
        array $metadata = [],
    ): PaymentEvent {
        return $this->record(
            $attempt,
            $settlementActor,
            PaymentEventType::Authorized,
            $amount,
            $occurredAt,
            $idempotencyKey,
            $reasonCode,
            $providerPaymentReference,
            $providerAuthorizationReference,
            $providerEventReference,
            null,
            $metadata,
        );
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function recordCaptured(
        PaymentAttempt $attempt,
        User $settlementActor,
        Money $amount,
        \DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        string $reasonCode,
        ?string $providerPaymentReference = null,
        ?string $providerEventReference = null,
        array $metadata = [],
    ): PaymentEvent {
        return $this->record(
            $attempt,
            $settlementActor,
            PaymentEventType::Captured,
            $amount,
            $occurredAt,
            $idempotencyKey,
            $reasonCode,
            $providerPaymentReference,
            null,
            $providerEventReference,
            null,
            $metadata,
        );
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function recordFailed(
        PaymentAttempt $attempt,
        User $settlementActor,
        string $failureCode,
        \DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        string $reasonCode,
        ?string $providerEventReference = null,
        array $metadata = [],
    ): PaymentEvent {
        return $this->record(
            $attempt,
            $settlementActor,
            PaymentEventType::Failed,
            null,
            $occurredAt,
            $idempotencyKey,
            $reasonCode,
            null,
            null,
            $providerEventReference,
            $failureCode,
            $metadata,
        );
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function recordCancelled(
        PaymentAttempt $attempt,
        User $settlementActor,
        string $failureCode,
        \DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        string $reasonCode,
        ?string $providerEventReference = null,
        array $metadata = [],
    ): PaymentEvent {
        return $this->record(
            $attempt,
            $settlementActor,
            PaymentEventType::Cancelled,
            null,
            $occurredAt,
            $idempotencyKey,
            $reasonCode,
            null,
            null,
            $providerEventReference,
            $failureCode,
            $metadata,
        );
    }

    /**
     * Appends a refund lifecycle event to the same attempt chain without touching the
     * attempt status. Called by {@see PaymentRefundManager} inside its transaction.
     *
     * @param array<string, bool|int|string|null> $metadata
     */
    public function recordRefundEvent(
        PaymentAttempt $attempt,
        User $settlementActor,
        PaymentEventType $eventType,
        ?Money $amount,
        \DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        string $reasonCode,
        ?string $providerEventReference = null,
        ?string $failureCode = null,
        array $metadata = [],
    ): PaymentEvent {
        if (!\in_array($eventType, [
            PaymentEventType::RefundRequested,
            PaymentEventType::RefundSucceeded,
            PaymentEventType::RefundFailed,
        ], true)) {
            throw CommerceException::invalidInput('recordRefundEvent accepts refund event types only.');
        }

        return $this->record(
            $attempt,
            $settlementActor,
            $eventType,
            $amount,
            $occurredAt,
            $idempotencyKey,
            $reasonCode,
            null,
            null,
            $providerEventReference,
            $failureCode,
            $metadata,
        );
    }

    /**
     * Verifies the whole hash chain for an attempt (used by fulfillment and integrity tests).
     */
    public function assertEventChainIntegrity(Uuid $attemptId): void
    {
        $previousHash = null;
        $expectedSequence = 0;
        foreach ($this->events->findChainForAttempt($attemptId) as $event) {
            ++$expectedSequence;
            if ($event->getSequenceNumber() !== $expectedSequence) {
                throw CommerceException::hashMismatch();
            }
            $this->eventHasher->verify(
                $event->getEventHash(),
                $event->getAttempt()->getId(),
                $event->getSequenceNumber(),
                $event->getEventType(),
                $event->getProviderEventReference(),
                $event->getOccurredAt(),
                $event->getAmountMinor(),
                $event->getCurrency(),
                $event->getSanitizedMetadata(),
                $event->getPreviousEventHash(),
            );
            if ($event->getPreviousEventHash() !== $previousHash) {
                throw CommerceException::hashMismatch();
            }
            $previousHash = $event->getEventHash();
        }
        if (0 === $expectedSequence) {
            throw CommerceException::notFound();
        }
    }

    /**
     * Requires a verified capture event whose amount equals the attempt amount.
     */
    public function requireCaptureEvent(PaymentAttempt $attempt): PaymentEvent
    {
        $capture = $this->events->findOneForAttemptByType($attempt->getId(), PaymentEventType::Captured);
        if (!$capture instanceof PaymentEvent) {
            throw CommerceException::paymentNotCaptured();
        }
        $amount = $capture->getAmount();
        if (!$amount instanceof Money || !$amount->equals($attempt->getAmount())) {
            throw CommerceException::totalMismatch();
        }
        $this->assertEventChainIntegrity($attempt->getId());

        return $capture;
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    private function record(
        PaymentAttempt $attempt,
        User $settlementActor,
        PaymentEventType $eventType,
        ?Money $amount,
        \DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        string $reasonCode,
        ?string $providerPaymentReference,
        ?string $providerAuthorizationReference,
        ?string $providerEventReference,
        ?string $failureCode,
        array $metadata,
    ): PaymentEvent {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $sanitizedMetadata = $this->metadataSanitizer->sanitize($metadata);
        $idempotencyKeyHash = $this->idempotencyHasher->hash(self::IDEMPOTENCY_SCOPE, $idempotencyKey);
        $occurredAt = UtcInstant::ensure($occurredAt);
        if (null !== $failureCode) {
            $failureCode = PaymentAttempt::assertFailureCode($failureCode);
        }
        $attemptId = $attempt->getId();
        $orderId = $attempt->getOrder()->getId();
        $actorId = $settlementActor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $attemptId,
                $orderId,
                $actorId,
                $eventType,
                $amount,
                $occurredAt,
                $idempotencyKeyHash,
                $reasonCode,
                $providerPaymentReference,
                $providerAuthorizationReference,
                $providerEventReference,
                $failureCode,
                $sanitizedMetadata,
            ): PaymentEvent {
                $existing = $this->events->findOneByIdempotencyKeyHash($idempotencyKeyHash);
                if ($existing instanceof PaymentEvent) {
                    if (!$existing->getAttempt()->getId()->equals($attemptId)
                        || $existing->getEventType() !== $eventType
                    ) {
                        throw CommerceException::idempotencyConflict();
                    }

                    return $existing;
                }

                $lockedOrder = $this->freshCommerce->findFreshOrder($orderId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedOrder instanceof CommerceOrder) {
                    throw CommerceException::notFound();
                }
                $lockedAttempt = $this->freshCommerce->findFreshPaymentAttempt(
                    $attemptId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedAttempt instanceof PaymentAttempt) {
                    throw CommerceException::notFound();
                }
                if (!$lockedAttempt->getOrder()->getId()->equals($lockedOrder->getId())) {
                    throw CommerceException::scopeMismatch();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw CommerceException::userNotFound();
                }
                $this->authorization->assertCanSettlePayments($freshActor);

                if ($amount instanceof Money
                    && $amount->getCurrency() !== $lockedAttempt->getCurrency()
                ) {
                    throw CommerceException::currencyMismatch();
                }
                if (\in_array($eventType, [PaymentEventType::Authorized, PaymentEventType::Captured], true)
                    && (!$amount instanceof Money || !$amount->equals($lockedAttempt->getAmount()))
                ) {
                    throw CommerceException::totalMismatch();
                }

                $now = $this->utcNow();
                if ($now < $occurredAt) {
                    throw CommerceException::invalidInput('occurredAt must not be in the future.');
                }

                $previous = $this->events->findLatestForAttempt($lockedAttempt->getId());
                $sequenceNumber = $lockedAttempt->nextEventSequence();
                $previousEventHash = $previous?->getEventHash();
                $eventHash = $this->eventHasher->hash(
                    $lockedAttempt->getId(),
                    $sequenceNumber,
                    $eventType,
                    $providerEventReference,
                    $occurredAt,
                    $amount?->getAmountMinor(),
                    $amount?->getCurrency(),
                    $sanitizedMetadata,
                    $previousEventHash,
                );

                $this->applyAttemptTransition(
                    $lockedAttempt,
                    $lockedOrder,
                    $eventType,
                    $providerPaymentReference,
                    $providerAuthorizationReference,
                    $failureCode,
                    $occurredAt,
                );

                $event = PaymentEvent::append(
                    $lockedAttempt,
                    $sequenceNumber,
                    $eventType,
                    $providerEventReference,
                    $idempotencyKeyHash,
                    $occurredAt,
                    $now,
                    $amount,
                    $sanitizedMetadata,
                    $eventHash,
                    $previousEventHash,
                );
                $this->events->save($event, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: self::auditActionFor($eventType),
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'payment_settlement_manager',
                        'reason_code' => $reasonCode,
                        'order_id' => $lockedOrder->getId()->toRfc4122(),
                        'payment_attempt_id' => $lockedAttempt->getId()->toRfc4122(),
                        'payment_event_id' => $event->getId()->toRfc4122(),
                        'event_type' => $eventType->value,
                        'event_hash' => $event->getEventHash(),
                        'sequence_number' => $sequenceNumber,
                        'provider_code' => $lockedAttempt->getProviderCode(),
                        'provider_environment' => $lockedAttempt->getEnvironment()->value,
                        'currency' => $event->getCurrency(),
                        'amount_minor' => $event->getAmountMinor(),
                        'failure_code' => $failureCode,
                        'status' => $lockedAttempt->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $event;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    private function applyAttemptTransition(
        PaymentAttempt $attempt,
        CommerceOrder $order,
        PaymentEventType $eventType,
        ?string $providerPaymentReference,
        ?string $providerAuthorizationReference,
        ?string $failureCode,
        \DateTimeImmutable $occurredAt,
    ): void {
        switch ($eventType) {
            case PaymentEventType::Authorized:
                $attempt->markAuthorized($providerPaymentReference, $providerAuthorizationReference, $occurredAt);
                break;
            case PaymentEventType::Captured:
                if (PaymentAttemptStatus::Captured === $attempt->getStatus()) {
                    throw CommerceException::invalidTransition();
                }
                $attempt->markCaptured($providerPaymentReference, $occurredAt);
                break;
            case PaymentEventType::Failed:
                $attempt->markFailed($failureCode ?? 'provider_declined', $occurredAt);
                if (CommerceOrderStatus::AwaitingPayment === $order->getStatus()) {
                    $order->markPaymentFailed($occurredAt);
                }
                break;
            case PaymentEventType::Cancelled:
                $attempt->markCancelled($failureCode ?? 'provider_cancelled', $occurredAt);
                if (CommerceOrderStatus::AwaitingPayment === $order->getStatus()) {
                    $order->markPaymentFailed($occurredAt);
                }
                break;
            case PaymentEventType::RefundRequested:
            case PaymentEventType::RefundSucceeded:
            case PaymentEventType::RefundFailed:
                if (PaymentAttemptStatus::Captured !== $attempt->getStatus()) {
                    throw CommerceException::paymentNotCaptured();
                }
                break;
        }
    }

    private static function auditActionFor(PaymentEventType $eventType): SecurityAuditAction
    {
        return match ($eventType) {
            PaymentEventType::Authorized => SecurityAuditAction::PaymentAuthorized,
            PaymentEventType::Captured => SecurityAuditAction::PaymentCaptured,
            PaymentEventType::Failed => SecurityAuditAction::PaymentFailed,
            PaymentEventType::Cancelled => SecurityAuditAction::PaymentCancelled,
            PaymentEventType::RefundRequested => SecurityAuditAction::PaymentRefundRequested,
            PaymentEventType::RefundSucceeded => SecurityAuditAction::PaymentRefundSucceeded,
            PaymentEventType::RefundFailed => SecurityAuditAction::PaymentRefundFailed,
        };
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
