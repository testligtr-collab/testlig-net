<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Commerce\CommerceInputNormalizer;
use App\Dto\SecurityAuditContext;
use App\Entity\CommerceOrder;
use App\Entity\Institution;
use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\CommerceOrderStatus;
use App\Enum\InstitutionStatus;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Repository\PaymentAttemptRepository;
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
 * Starts provider-neutral payment attempts for an order.
 *
 * The raw idempotency key never leaves this call: only its HMAC digest is persisted, and
 * replaying the same key returns the existing attempt instead of charging twice.
 *
 * Lock order: Institution → purchaser/membership → CommerceOrder → CommerceOrderItem
 * → PaymentAttempt → Audit
 */
final class PaymentAttemptManager
{
    public const IDEMPOTENCY_SCOPE = 'payment_attempt';

    public function __construct(
        private readonly PaymentAttemptRepository $attempts,
        private readonly CommerceAuthorization $authorization,
        private readonly CommerceOrderManager $orderManager,
        private readonly CommerceIdempotencyKeyHasher $idempotencyHasher,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function start(
        CommerceOrder $order,
        User $actor,
        string $providerCode,
        PaymentProviderEnvironment $environment,
        string $idempotencyKey,
        string $reasonCode,
    ): PaymentAttempt {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $providerCode = CommerceInputNormalizer::providerCode($providerCode);
        $idempotencyKeyHash = $this->idempotencyHasher->hash(self::IDEMPOTENCY_SCOPE, $idempotencyKey);
        $orderId = $order->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $orderId,
                $actorId,
                $providerCode,
                $environment,
                $idempotencyKeyHash,
                $reasonCode,
            ): PaymentAttempt {
                $existing = $this->attempts->findOneByIdempotencyKeyHash($idempotencyKeyHash);
                if ($existing instanceof PaymentAttempt) {
                    if (!$existing->getOrder()->getId()->equals($orderId)) {
                        throw CommerceException::idempotencyConflict();
                    }
                    if ($existing->getProviderCode() !== $providerCode
                        || $existing->getEnvironment() !== $environment
                    ) {
                        throw CommerceException::idempotencyConflict();
                    }

                    return $existing;
                }

                $locked = $this->freshCommerce->findFreshOrder($orderId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof CommerceOrder) {
                    throw CommerceException::notFound();
                }
                if ($locked->getInstitution() instanceof Institution) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $locked->getInstitution()->getId(),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution
                        || InstitutionStatus::Active !== $lockedInstitution->getStatus()
                    ) {
                        throw CommerceException::notFound();
                    }
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw CommerceException::userNotFound();
                }
                $this->authorization->assertCanManageOrder($freshActor, $locked);

                $now = $this->utcNow();
                if ($locked->isExpiredAt($now)) {
                    if ($locked->getStatus()->allowsCancellation()) {
                        $locked->markExpired($now);
                        $this->entityManager->flush();
                    }
                    throw CommerceException::invalidTransition();
                }
                if (CommerceOrderStatus::AwaitingPayment !== $locked->getStatus()
                    && CommerceOrderStatus::Failed !== $locked->getStatus()
                ) {
                    throw CommerceException::invalidTransition();
                }

                $items = $this->freshCommerce->findFreshOrderItems($locked->getId());
                $this->orderManager->assertOrderIntegrity($locked, $items);

                $attempt = PaymentAttempt::initiate(
                    $locked,
                    $this->attempts->nextAttemptNumber($locked->getId()),
                    $providerCode,
                    $environment,
                    $locked->getGrandTotal(),
                    $idempotencyKeyHash,
                    $now,
                );
                $this->attempts->save($attempt, false);
                $locked->markPaymentStarted($now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::PaymentAttemptStarted,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'payment_attempt_manager',
                        'reason_code' => $reasonCode,
                        'order_id' => $locked->getId()->toRfc4122(),
                        'payment_attempt_id' => $attempt->getId()->toRfc4122(),
                        'attempt_number' => $attempt->getAttemptNumber(),
                        'provider_code' => $attempt->getProviderCode(),
                        'provider_environment' => $attempt->getEnvironment()->value,
                        'currency' => $attempt->getCurrency(),
                        'amount_minor' => $attempt->getAmountMinor(),
                        'idempotency_key_hash' => $attempt->getIdempotencyKeyHash(),
                        'status' => $attempt->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $attempt;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?PaymentAttempt
    {
        return $this->attempts->findOneByIdempotencyKeyHash(
            $this->idempotencyHasher->hash(self::IDEMPOTENCY_SCOPE, $idempotencyKey),
        );
    }

    public function requireAttempt(Uuid $attemptId): PaymentAttempt
    {
        $attempt = $this->attempts->findOneById($attemptId);
        if (!$attempt instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }

        return $attempt;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
