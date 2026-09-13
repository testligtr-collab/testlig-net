<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Commerce\CommerceInputNormalizer;
use App\Dto\SecurityAuditContext;
use App\Entity\CommerceOrder;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Enum\PaymentEventType;
use App\Enum\PaymentRefundReasonCode;
use App\Enum\PaymentRefundStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\PaymentRefundRepository;
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
 * Refund bookkeeping against a captured payment attempt.
 *
 * Refunds are pure money movement. A partial refund leaves the AccessLicense untouched,
 * and even a full refund does **not** revoke access or delete anything: entitlement
 * removal is an explicit {@see CommerceFulfillmentManager::reverse()} call. The total of
 * requested + succeeded refunds can never exceed the captured amount (checked here and
 * again by a DB trigger).
 *
 * Lock order: CommerceOrder → PaymentAttempt → PaymentRefund → PaymentEvent → Audit
 */
final class PaymentRefundManager
{
    public const IDEMPOTENCY_SCOPE = 'payment_refund';

    public function __construct(
        private readonly PaymentRefundRepository $refunds,
        private readonly CommerceAuthorization $authorization,
        private readonly PaymentSettlementManager $settlementManager,
        private readonly CommerceIdempotencyKeyHasher $idempotencyHasher,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function request(
        PaymentAttempt $attempt,
        User $settlementActor,
        Money $amount,
        PaymentRefundReasonCode $refundReasonCode,
        string $idempotencyKey,
        string $reasonCode,
    ): PaymentRefund {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $idempotencyKeyHash = $this->idempotencyHasher->hash(self::IDEMPOTENCY_SCOPE, $idempotencyKey);
        $attemptId = $attempt->getId();
        $orderId = $attempt->getOrder()->getId();
        $actorId = $settlementActor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $attemptId,
                $orderId,
                $actorId,
                $amount,
                $refundReasonCode,
                $idempotencyKeyHash,
                $idempotencyKey,
                $reasonCode,
            ): PaymentRefund {
                $existing = $this->refunds->findOneByIdempotencyKeyHash($idempotencyKeyHash);
                if ($existing instanceof PaymentRefund) {
                    if (!$existing->getPaymentAttempt()->getId()->equals($attemptId)
                        || !$existing->getAmount()->equals($amount)
                        || $existing->getReasonCode() !== $refundReasonCode
                    ) {
                        throw CommerceException::idempotencyConflict();
                    }

                    return $existing;
                }

                $lockedAttempt = $this->lockAttemptScope($orderId, $attemptId);
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanSettlePayments($freshActor);
                $this->settlementManager->requireCaptureEvent($lockedAttempt);

                if ($amount->getCurrency() !== $lockedAttempt->getCurrency()) {
                    throw CommerceException::currencyMismatch();
                }
                $reserved = $this->refunds->reservedAmountMinor($lockedAttempt->getId());
                if ($reserved + $amount->getAmountMinor() > $lockedAttempt->getAmountMinor()) {
                    throw CommerceException::refundExceedsCapture();
                }

                $now = $this->utcNow();
                $refund = PaymentRefund::request(
                    $lockedAttempt,
                    $this->refunds->nextRefundNumber($lockedAttempt->getId()),
                    $amount,
                    $refundReasonCode,
                    $idempotencyKeyHash,
                    $now,
                );
                $this->refunds->save($refund, false);

                $this->settlementManager->recordRefundEvent(
                    $lockedAttempt,
                    $freshActor,
                    PaymentEventType::RefundRequested,
                    $amount,
                    $now,
                    self::deriveEventKey($idempotencyKey, '.rq'.$refund->getRefundNumber()),
                    $reasonCode,
                );

                $this->recordAudit(
                    SecurityAuditAction::PaymentRefundRequested,
                    $freshActor,
                    $refund,
                    $reasonCode,
                );
                $this->entityManager->flush();

                return $refund;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    /**
     * Marks a requested refund as settled by the provider. Entitlements are untouched.
     */
    public function markSucceeded(
        PaymentRefund $refund,
        User $settlementActor,
        string $idempotencyKey,
        string $reasonCode,
        ?string $providerRefundReference = null,
    ): PaymentRefund {
        return $this->settle(
            $refund,
            $settlementActor,
            $idempotencyKey,
            $reasonCode,
            PaymentEventType::RefundSucceeded,
            SecurityAuditAction::PaymentRefundSucceeded,
            static function (PaymentRefund $locked, \DateTimeImmutable $now) use ($providerRefundReference): void {
                $locked->markSucceeded($providerRefundReference, $now);
            },
            null,
        );
    }

    public function markFailed(
        PaymentRefund $refund,
        User $settlementActor,
        string $failureCode,
        string $idempotencyKey,
        string $reasonCode,
    ): PaymentRefund {
        $failureCode = PaymentAttempt::assertFailureCode($failureCode);

        return $this->settle(
            $refund,
            $settlementActor,
            $idempotencyKey,
            $reasonCode,
            PaymentEventType::RefundFailed,
            SecurityAuditAction::PaymentRefundFailed,
            static function (PaymentRefund $locked, \DateTimeImmutable $now) use ($failureCode): void {
                $locked->markFailed($failureCode, $now);
            },
            $failureCode,
        );
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?PaymentRefund
    {
        return $this->refunds->findOneByIdempotencyKeyHash(
            $this->idempotencyHasher->hash(self::IDEMPOTENCY_SCOPE, $idempotencyKey),
        );
    }

    /**
     * Total of requested + succeeded refunds, i.e. the amount already committed.
     */
    public function reservedAmount(PaymentAttempt $attempt): Money
    {
        return Money::fromMinor(
            $this->refunds->reservedAmountMinor($attempt->getId()),
            $attempt->getCurrency(),
        );
    }

    /**
     * @param callable(PaymentRefund, \DateTimeImmutable): void $mutator
     */
    private function settle(
        PaymentRefund $refund,
        User $settlementActor,
        string $idempotencyKey,
        string $reasonCode,
        PaymentEventType $eventType,
        SecurityAuditAction $action,
        callable $mutator,
        ?string $failureCode,
    ): PaymentRefund {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        CommerceIdempotencyKeyHasher::normalizeIdempotencyKey($idempotencyKey);
        $refundId = $refund->getId();
        $attemptId = $refund->getPaymentAttempt()->getId();
        $orderId = $refund->getPaymentAttempt()->getOrder()->getId();
        $actorId = $settlementActor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $refundId,
                $attemptId,
                $orderId,
                $actorId,
                $idempotencyKey,
                $reasonCode,
                $eventType,
                $action,
                $mutator,
                $failureCode,
            ): PaymentRefund {
                $lockedAttempt = $this->lockAttemptScope($orderId, $attemptId);
                $locked = $this->freshCommerce->findFreshRefund($refundId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof PaymentRefund) {
                    throw CommerceException::notFound();
                }
                if (!$locked->getPaymentAttempt()->getId()->equals($lockedAttempt->getId())) {
                    throw CommerceException::scopeMismatch();
                }
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanSettlePayments($freshActor);
                if (PaymentRefundStatus::Requested !== $locked->getStatus()) {
                    throw CommerceException::invalidTransition();
                }

                $now = $this->utcNow();
                $mutator($locked, $now);

                $this->settlementManager->recordRefundEvent(
                    $lockedAttempt,
                    $freshActor,
                    $eventType,
                    PaymentEventType::RefundSucceeded === $eventType ? $locked->getAmount() : null,
                    $now,
                    $idempotencyKey,
                    $reasonCode,
                    null,
                    $failureCode,
                );

                $this->recordAudit($action, $freshActor, $locked, $reasonCode);
                $this->entityManager->flush();

                return $locked;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    /**
     * Locks the order then the attempt, honouring the canonical commerce lock order.
     */
    private function lockAttemptScope(Uuid $orderId, Uuid $attemptId): PaymentAttempt
    {
        $lockedOrder = $this->freshCommerce->findFreshOrder($orderId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedOrder instanceof CommerceOrder) {
            throw CommerceException::notFound();
        }
        $lockedAttempt = $this->freshCommerce->findFreshPaymentAttempt($attemptId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedAttempt instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }
        if (!$lockedAttempt->getOrder()->getId()->equals($lockedOrder->getId())) {
            throw CommerceException::scopeMismatch();
        }

        return $lockedAttempt;
    }

    /**
     * Derives the settlement-event idempotency key for a refund lifecycle transition,
     * keeping it inside the allowed key length.
     */
    private static function deriveEventKey(string $baseKey, string $suffix): string
    {
        $baseKey = CommerceIdempotencyKeyHasher::normalizeIdempotencyKey($baseKey);
        $budget = CommerceIdempotencyKeyHasher::MAX_IDEMPOTENCY_KEY_LENGTH - \strlen($suffix);

        return substr($baseKey, 0, max(1, $budget)).$suffix;
    }

    private function requireFreshActor(Uuid $actorId): User
    {
        $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
        $freshActor = $users[$actorId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User) {
            throw CommerceException::userNotFound();
        }

        return $freshActor;
    }

    private function recordAudit(
        SecurityAuditAction $action,
        User $actor,
        PaymentRefund $refund,
        string $reasonCode,
    ): void {
        $attempt = $refund->getPaymentAttempt();

        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: [
                'source' => 'payment_refund_manager',
                'reason_code' => $reasonCode,
                'refund_id' => $refund->getId()->toRfc4122(),
                'refund_number' => $refund->getRefundNumber(),
                'order_id' => $attempt->getOrder()->getId()->toRfc4122(),
                'payment_attempt_id' => $attempt->getId()->toRfc4122(),
                'provider_code' => $attempt->getProviderCode(),
                'provider_environment' => $attempt->getEnvironment()->value,
                'currency' => $refund->getCurrency(),
                'refund_amount_minor' => $refund->getAmountMinor(),
                'amount_minor' => $attempt->getAmountMinor(),
                'cancellation_reason_code' => $refund->getReasonCode()->value,
                'failure_code' => $refund->getFailureCode(),
                'status' => $refund->getStatus()->value,
            ],
            captureRequestHashes: false,
        ), false);
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
