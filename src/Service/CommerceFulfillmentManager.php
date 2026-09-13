<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessPackagePolicyHasher;
use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Commerce\CommerceInputNormalizer;
use App\Dto\SecurityAuditContext;
use App\Entity\AccessLicense;
use App\Entity\AccessPackage;
use App\Entity\AccessPackageVersion;
use App\Entity\CommerceFulfillment;
use App\Entity\CommerceOrder;
use App\Entity\CommerceOrderItem;
use App\Entity\Institution;
use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessLicenseStatus;
use App\Enum\CommerceFulfillmentStatus;
use App\Enum\CommerceOrderStatus;
use App\Enum\CommercePurchaserType;
use App\Enum\CommercialOfferBillingType;
use App\Enum\InstitutionStatus;
use App\Enum\PaymentAttemptStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AccessEntitlementException;
use App\Exception\CommerceException;
use App\Repository\CommerceFulfillmentRepository;
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
 * The single place a captured payment turns into an AccessLicense.
 *
 * `fulfill()` runs one transaction that re-proves every invariant before granting access:
 *
 * 1. lock Institution (institution orders) → CommerceOrder → PaymentAttempt
 * 2. attempt belongs to the order, is `captured`, and its amount equals the grand total
 * 3. a verified `captured` PaymentEvent exists and the whole event hash chain is intact
 * 4. persisted order items reproduce the stored totals and the order hash
 * 5. every line's offer snapshot hash and package policy snapshot hash still match
 * 6. purchaser type matches the offer target type and the package target type
 * 7. per item: AccessLicenseManager creates + activates a `purchase` license
 * 8. CommerceFulfillment completed, order paid, audit, commit
 *
 * Idempotency: the caller's key is fanned out per order item via
 * {@see CommerceIdempotencyKeyHasher::hashScoped()}. Replaying the same key returns the
 * same fulfillments and the same licenses instead of granting access twice.
 *
 * Lock order: Institution → purchaser → Offer → Package → Version → Order → OrderItem
 * → Subscription → PaymentAttempt → PaymentEvent → Fulfillment → AccessLicense → Audit
 */
final class CommerceFulfillmentManager
{
    public const IDEMPOTENCY_SCOPE = 'commerce_fulfillment';

    public function __construct(
        private readonly CommerceFulfillmentRepository $fulfillments,
        private readonly CommerceAuthorization $authorization,
        private readonly CommerceOrderManager $orderManager,
        private readonly CommercialOfferManager $offerManager,
        private readonly CommerceSubscriptionManager $subscriptionManager,
        private readonly PaymentSettlementManager $settlementManager,
        private readonly AccessLicenseManager $licenseManager,
        private readonly CommerceIdempotencyKeyHasher $idempotencyHasher,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param non-empty-string $idempotencyKey
     *
     * @return non-empty-list<CommerceFulfillment> one fulfillment per order item
     */
    public function fulfill(
        CommerceOrder $order,
        PaymentAttempt $attempt,
        User $settlementActor,
        string $idempotencyKey,
        string $reasonCode,
    ): array {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        CommerceIdempotencyKeyHasher::normalizeIdempotencyKey($idempotencyKey);
        $orderId = $order->getId();
        $attemptId = $attempt->getId();
        $actorId = $settlementActor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $orderId,
                $attemptId,
                $actorId,
                $idempotencyKey,
                $reasonCode,
            ): array {
                $lockedOrder = $this->freshCommerce->findFreshOrder($orderId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedOrder instanceof CommerceOrder) {
                    throw CommerceException::notFound();
                }
                $lockedInstitution = null;
                if ($lockedOrder->getInstitution() instanceof Institution) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $lockedOrder->getInstitution()->getId(),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution
                        || InstitutionStatus::Active !== $lockedInstitution->getStatus()
                    ) {
                        throw CommerceException::notFound();
                    }
                }

                $lockedAttempt = $this->freshCommerce->findFreshPaymentAttempt(
                    $attemptId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedAttempt instanceof PaymentAttempt) {
                    throw CommerceException::notFound();
                }
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanSettlePayments($freshActor);

                $capturedAt = $this->assertCapturedAttempt($lockedOrder, $lockedAttempt);

                $items = $this->freshCommerce->findFreshOrderItems($lockedOrder->getId());
                $this->orderManager->assertOrderIntegrity($lockedOrder, $items);

                $now = $this->utcNow();
                $fulfillments = [];
                $created = 0;
                foreach ($items as $item) {
                    $itemHash = $this->idempotencyHasher->hashScoped(
                        self::IDEMPOTENCY_SCOPE,
                        $idempotencyKey,
                        'oi:'.$item->getId()->toRfc4122(),
                    );
                    $existing = $this->fulfillments->findOneByIdempotencyKeyHash($itemHash);
                    if ($existing instanceof CommerceFulfillment) {
                        $this->assertReplayable($existing, $lockedOrder, $item, $lockedAttempt);
                        $fulfillments[] = $existing;
                        continue;
                    }

                    $fulfillments[] = $this->createFulfillment(
                        $lockedOrder,
                        $item,
                        $lockedAttempt,
                        $lockedInstitution,
                        $freshActor,
                        $itemHash,
                        $capturedAt,
                        $now,
                        $reasonCode,
                    );
                    ++$created;
                }

                if ([] === $fulfillments) {
                    throw CommerceException::invalidInput('Order has no items.');
                }

                if ($created > 0 && CommerceOrderStatus::AwaitingPayment === $lockedOrder->getStatus()) {
                    $lockedOrder->markPaid($capturedAt);
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::CommerceOrderPaid,
                        actorType: SecurityAuditActorType::User,
                        outcome: SecurityAuditOutcome::Success,
                        actorUser: $freshActor,
                        metadata: [
                            'source' => 'commerce_fulfillment_manager',
                            'reason_code' => $reasonCode,
                            'order_id' => $lockedOrder->getId()->toRfc4122(),
                            'order_hash' => $lockedOrder->getOrderHash(),
                            'payment_attempt_id' => $lockedAttempt->getId()->toRfc4122(),
                            'currency' => $lockedOrder->getCurrency(),
                            'grand_total_amount_minor' => $lockedOrder->getGrandTotalAmountMinor(),
                            'order_item_count' => \count($fulfillments),
                            'status' => $lockedOrder->getStatus()->value,
                        ],
                        captureRequestHashes: false,
                    ), false);
                }

                $this->entityManager->flush();

                return $fulfillments;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (AccessEntitlementException $e) {
            throw CommerceException::invalidInput('License grant refused: '.$e->getReason()->value);
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    /**
     * Explicit operator reversal: fulfillment → reversed and its license revoked in the
     * same transaction. Refunds never trigger this implicitly, not even full refunds.
     */
    public function reverse(
        CommerceFulfillment $fulfillment,
        User $settlementActor,
        string $reversalReasonCode,
        string $reasonCode,
    ): CommerceFulfillment {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $reversalReasonCode = PaymentAttempt::assertFailureCode($reversalReasonCode);
        $fulfillmentId = $fulfillment->getId();
        $actorId = $settlementActor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $fulfillmentId,
                $actorId,
                $reversalReasonCode,
                $reasonCode,
            ): CommerceFulfillment {
                $locked = $this->freshCommerce->findFreshFulfillment($fulfillmentId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof CommerceFulfillment) {
                    throw CommerceException::notFound();
                }
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanSettlePayments($freshActor);
                if (CommerceFulfillmentStatus::Completed !== $locked->getStatus()) {
                    throw CommerceException::invalidTransition();
                }
                $license = $locked->getAccessLicense();
                if (!$license instanceof AccessLicense) {
                    throw CommerceException::notFound();
                }

                $now = $this->utcNow();
                $locked->reverse($reversalReasonCode, $now);
                if (AccessLicenseStatus::Revoked !== $license->getStatus()) {
                    $this->licenseManager->revoke($license, $freshActor, $reversalReasonCode);
                }

                $this->recordFulfillmentAudit(
                    SecurityAuditAction::CommerceFulfillmentReversed,
                    $freshActor,
                    $locked,
                    $reasonCode,
                );
                $this->entityManager->flush();

                return $locked;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (AccessEntitlementException $e) {
            throw CommerceException::invalidInput('License revoke refused: '.$e->getReason()->value);
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    public function findByIdempotencyKeyForItem(string $idempotencyKey, Uuid $orderItemId): ?CommerceFulfillment
    {
        return $this->fulfillments->findOneByIdempotencyKeyHash($this->idempotencyHasher->hashScoped(
            self::IDEMPOTENCY_SCOPE,
            $idempotencyKey,
            'oi:'.$orderItemId->toRfc4122(),
        ));
    }

    private function createFulfillment(
        CommerceOrder $order,
        CommerceOrderItem $item,
        PaymentAttempt $attempt,
        ?Institution $institution,
        User $settlementActor,
        string $idempotencyKeyHash,
        \DateTimeImmutable $capturedAt,
        \DateTimeImmutable $now,
        string $reasonCode,
    ): CommerceFulfillment {
        $version = $this->assertLineIntegrity($order, $item);

        $subscription = null;
        $periodKey = null;
        $validFrom = $capturedAt;
        if (CommercialOfferBillingType::Recurring === $item->getBillingType()) {
            $subscription = $this->subscriptionManager->ensureForCapturedItem($order, $item, $capturedAt, $now);
            $this->subscriptionManager->assertSubscriptionIntegrity($subscription);
            $periodKey = CommerceSubscriptionManager::periodKeyFor($subscription);
            $validFrom = $subscription->getCurrentPeriodStart();
            $validUntil = $subscription->getCurrentPeriodEnd();
            $alreadyFulfilled = $this->fulfillments->findCompletedForSubscriptionPeriod(
                $subscription->getId(),
                $periodKey,
            );
            if ($alreadyFulfilled instanceof CommerceFulfillment) {
                throw CommerceException::alreadyFulfilled();
            }
        } else {
            $validUntil = $validFrom->add(new \DateInterval('P'.$this->resolveValidityDays($item).'D'));
            $alreadyFulfilled = $this->fulfillments->findCompletedForOrderItem($item->getId());
            if ($alreadyFulfilled instanceof CommerceFulfillment) {
                throw CommerceException::alreadyFulfilled();
            }
        }

        $fulfillment = CommerceFulfillment::createPending(
            $order,
            $item,
            $attempt,
            $subscription,
            $this->fulfillments->nextFulfillmentNumber($order->getId()),
            $idempotencyKeyHash,
            $periodKey,
            $now,
        );
        $this->fulfillments->save($fulfillment, false);

        $license = $this->grantLicense(
            $order,
            $item,
            $version,
            $institution,
            $settlementActor,
            $validFrom,
            $validUntil,
            $periodKey,
            $reasonCode,
        );
        $fulfillment->complete($license, $now);

        $this->recordFulfillmentAudit(
            SecurityAuditAction::CommerceFulfillmentCompleted,
            $settlementActor,
            $fulfillment,
            $reasonCode,
        );

        return $fulfillment;
    }

    private function grantLicense(
        CommerceOrder $order,
        CommerceOrderItem $item,
        AccessPackageVersion $version,
        ?Institution $institution,
        User $settlementActor,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validUntil,
        ?string $periodKey,
        string $reasonCode,
    ): AccessLicense {
        $externalReference = $order->getPublicReference().':oi:'.str_replace(
            '-',
            '',
            $item->getId()->toRfc4122(),
        );
        if (null !== $periodKey) {
            $externalReference .= ':p:'.$periodKey;
        }

        if (CommercePurchaserType::User === $order->getPurchaserType()) {
            $purchaser = $order->getUser();
            if (!$purchaser instanceof User) {
                throw CommerceException::invalidInput('User order requires a purchaser user.');
            }
            $license = $this->licenseManager->createUserLicense(
                $version,
                $purchaser,
                $settlementActor,
                AccessLicenseSourceType::Purchase,
                $validFrom,
                $validUntil,
                $reasonCode,
                $externalReference,
            );
        } else {
            if (!$institution instanceof Institution) {
                throw CommerceException::invalidInput('Institution order requires a purchaser institution.');
            }
            $license = $this->licenseManager->createInstitutionLicense(
                $version,
                $institution,
                $settlementActor,
                AccessLicenseSourceType::Purchase,
                $validFrom,
                $validUntil,
                null,
                $reasonCode,
                $externalReference,
            );
        }

        return $this->licenseManager->activate($license, $settlementActor, $reasonCode);
    }

    /**
     * Verifies the captured attempt matches the order and returns the capture instant.
     */
    private function assertCapturedAttempt(CommerceOrder $order, PaymentAttempt $attempt): \DateTimeImmutable
    {
        if (!$attempt->getOrder()->getId()->equals($order->getId())) {
            throw CommerceException::scopeMismatch('Payment attempt does not belong to the order.');
        }
        if (PaymentAttemptStatus::Captured !== $attempt->getStatus()) {
            throw CommerceException::paymentNotCaptured();
        }
        if ($attempt->getCurrency() !== $order->getCurrency()) {
            throw CommerceException::currencyMismatch();
        }
        if (!$attempt->getAmount()->equals($order->getGrandTotal())) {
            throw CommerceException::totalMismatch();
        }
        if (CommerceOrderStatus::AwaitingPayment !== $order->getStatus()
            && CommerceOrderStatus::Paid !== $order->getStatus()
        ) {
            throw CommerceException::invalidTransition();
        }

        $capture = $this->settlementManager->requireCaptureEvent($attempt);
        $capturedAt = $attempt->getCapturedAt();
        if (!$capturedAt instanceof \DateTimeImmutable) {
            throw CommerceException::paymentNotCaptured();
        }
        if ($capture->getOccurredAt() > $capturedAt) {
            throw CommerceException::hashMismatch();
        }

        return UtcInstant::ensure($capturedAt);
    }

    /**
     * Re-verifies one line's frozen snapshots against the live catalog rows and returns the
     * locked package version the license will be minted from.
     */
    private function assertLineIntegrity(CommerceOrder $order, CommerceOrderItem $item): AccessPackageVersion
    {
        if (!$item->getOrder()->getId()->equals($order->getId())) {
            throw CommerceException::scopeMismatch('Order item does not belong to the order.');
        }

        $offer = $this->freshCommerce->findFreshOffer($item->getOffer()->getId(), LockMode::PESSIMISTIC_READ);
        if (null === $offer) {
            throw CommerceException::notFound();
        }
        $this->offerManager->assertOfferIntegrity($offer);
        if (!hash_equals($item->getOfferSnapshotHash(), $offer->getOfferHash())) {
            throw CommerceException::hashMismatch();
        }
        if ($offer->getTargetType()->toPurchaserType() !== $order->getPurchaserType()) {
            throw CommerceException::scopeMismatch('Offer target type does not match the purchaser type.');
        }

        $package = $this->freshCommerce->findFreshPackage($offer->getPackage()->getId(), LockMode::PESSIMISTIC_READ);
        if (!$package instanceof AccessPackage) {
            throw CommerceException::notFound();
        }
        if (!$offer->getTargetType()->matchesPackageTarget($package->getTargetType())) {
            throw CommerceException::scopeMismatch('Package target type does not match the offer target type.');
        }

        $version = $this->freshCommerce->findFreshPackageVersion(
            $offer->getPackageVersion()->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$version instanceof AccessPackageVersion) {
            throw CommerceException::notFound();
        }
        if (1 !== preg_match(
            '/^[0-9a-f]{'.AccessPackagePolicyHasher::HASH_HEX_LENGTH.'}$/',
            $version->getPolicyHash(),
        )) {
            throw CommerceException::hashMismatch();
        }
        if (!hash_equals($item->getPackagePolicySnapshotHash(), $version->getPolicyHash())) {
            throw CommerceException::hashMismatch();
        }

        return $version;
    }

    /**
     * A replayed idempotency key must resolve to the very same fulfillment scope.
     */
    private function assertReplayable(
        CommerceFulfillment $existing,
        CommerceOrder $order,
        CommerceOrderItem $item,
        PaymentAttempt $attempt,
    ): void {
        if (!$existing->getOrder()->getId()->equals($order->getId())
            || !$existing->getOrderItem()->getId()->equals($item->getId())
            || !$existing->getPaymentAttempt()->getId()->equals($attempt->getId())
        ) {
            throw CommerceException::idempotencyConflict();
        }
        if (CommerceFulfillmentStatus::Completed !== $existing->getStatus()) {
            throw CommerceException::conflict();
        }
        if (!$existing->getAccessLicense() instanceof AccessLicense) {
            throw CommerceException::notFound();
        }
    }

    /**
     * One-time purchases need a bounded window. CommercialOfferManager already refuses to
     * publish a one-time offer without it, so a missing value here means the catalog drifted.
     */
    private function resolveValidityDays(CommerceOrderItem $item): int
    {
        $days = $item->getPackageVersion()->getValidityDays()
            ?? $item->getPackage()->getDefaultValidityDays();
        if (null === $days || $days < 1) {
            throw CommerceException::validityPolicyMissing();
        }

        return $days;
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

    private function recordFulfillmentAudit(
        SecurityAuditAction $action,
        User $actor,
        CommerceFulfillment $fulfillment,
        string $reasonCode,
    ): void {
        $subscription = $fulfillment->getSubscription();

        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: [
                'source' => 'commerce_fulfillment_manager',
                'reason_code' => $reasonCode,
                'fulfillment_id' => $fulfillment->getId()->toRfc4122(),
                'fulfillment_number' => $fulfillment->getFulfillmentNumber(),
                'order_id' => $fulfillment->getOrder()->getId()->toRfc4122(),
                'order_item_id' => $fulfillment->getOrderItem()->getId()->toRfc4122(),
                'payment_attempt_id' => $fulfillment->getPaymentAttempt()->getId()->toRfc4122(),
                'subscription_id' => $subscription?->getId()->toRfc4122(),
                'period_key' => $fulfillment->getPeriodKey(),
                'license_id' => $fulfillment->getAccessLicense()?->getId()->toRfc4122(),
                'package_id' => $fulfillment->getOrderItem()->getPackage()->getId()->toRfc4122(),
                'package_version_id' => $fulfillment->getOrderItem()->getPackageVersion()->getId()->toRfc4122(),
                'idempotency_key_hash' => $fulfillment->getIdempotencyKeyHash(),
                'status' => $fulfillment->getStatus()->value,
                'reversal_reason_code' => $fulfillment->getReversalReasonCode(),
            ],
            captureRequestHashes: false,
        ), false);
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
