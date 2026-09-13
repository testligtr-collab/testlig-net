<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\CommerceCanonicalInstant;
use App\Commerce\CommerceInputNormalizer;
use App\Commerce\CommerceSubscriptionHasher;
use App\Dto\SecurityAuditContext;
use App\Entity\CommerceOrder;
use App\Entity\CommerceOrderItem;
use App\Entity\CommerceSubscription;
use App\Entity\CommercialOffer;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\CommerceCancellationReasonCode;
use App\Enum\CommerceSubscriberType;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Repository\CommerceSubscriptionRepository;
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
 * Recurring agreement lifecycle: first period creation, renewal, cancellation, expiry.
 *
 * The subscription owns the billing window; each paid period is turned into its own
 * period-scoped AccessLicense by {@see CommerceFulfillmentManager}.
 *
 * Lock order: Institution → purchaser → CommerceOrder → CommerceOrderItem
 * → CommerceSubscription → Audit
 */
final class CommerceSubscriptionManager
{
    public function __construct(
        private readonly CommerceSubscriptionRepository $subscriptions,
        private readonly CommerceAuthorization $authorization,
        private readonly CommerceSubscriptionHasher $subscriptionHasher,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Returns the subscription backing a recurring order item, creating and activating it
     * on the first captured period.
     *
     * Called from inside {@see CommerceFulfillmentManager::fulfill()}'s transaction with the
     * order already locked — it never opens a transaction of its own.
     *
     * @internal
     */
    public function ensureForCapturedItem(
        CommerceOrder $order,
        CommerceOrderItem $item,
        \DateTimeImmutable $capturedAt,
        \DateTimeImmutable $now,
    ): CommerceSubscription {
        if (CommercialOfferBillingType::Recurring !== $item->getBillingType()) {
            throw CommerceException::scopeMismatch('Subscriptions require a recurring order item.');
        }
        $offer = $item->getOffer();
        $existing = $this->subscriptions->findOneForOrderOffer($order->getId(), $offer->getId());
        if ($existing instanceof CommerceSubscription) {
            $locked = $this->freshCommerce->findFreshSubscription(
                $existing->getId(),
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$locked instanceof CommerceSubscription) {
                throw CommerceException::notFound();
            }
            $this->assertSubscriptionIntegrity($locked);

            return $locked;
        }

        $interval = $offer->getBillingInterval();
        if (!$interval instanceof CommercialOfferBillingInterval) {
            throw CommerceException::invalidInput('Recurring offers require a billing interval.');
        }
        $subscriberType = $order->getPurchaserType()->toSubscriberType();
        $subscriptionId = Uuid::v7();
        $periodStart = UtcInstant::ensure($capturedAt);
        $periodEnd = $periodStart->add($interval->toDateInterval());
        $subscription = CommerceSubscription::createPending(
            $subscriberType,
            $order->getUser(),
            $order->getInstitution(),
            $order,
            $offer,
            $periodStart,
            $periodEnd,
            null,
            null,
            $this->hashFor($subscriptionId, $subscriberType, $order, $offer, $interval, $periodStart, $periodEnd),
            $now,
            CommerceSubscriptionHasher::SCHEMA_VERSION,
            $subscriptionId,
        );
        $this->subscriptions->save($subscription, false);
        $this->recordAudit(SecurityAuditAction::CommerceSubscriptionCreated, null, $subscription, 'commerce_capture');

        $subscription->activate($now);
        $this->recordAudit(SecurityAuditAction::CommerceSubscriptionActivated, null, $subscription, 'commerce_capture');

        return $subscription;
    }

    /**
     * Rolls a subscription into its next billing period. The platform settlement operator
     * drives this; the new period's license is minted by the fulfillment manager.
     */
    public function renew(
        CommerceSubscription $subscription,
        User $settlementActor,
        \DateTimeImmutable $nextPeriodStart,
        string $reasonCode,
    ): CommerceSubscription {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $subscriptionId = $subscription->getId();
        $actorId = $settlementActor->getId();
        $nextPeriodStart = UtcInstant::ensure($nextPeriodStart);

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $subscriptionId,
                $actorId,
                $nextPeriodStart,
                $reasonCode,
            ): CommerceSubscription {
                $locked = $this->requireLockedSubscription($subscriptionId);
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanSettlePayments($freshActor);
                $this->assertSubscriptionIntegrity($locked);

                $periodEnd = $nextPeriodStart->add($locked->getBillingInterval()->toDateInterval());
                $now = $this->utcNow();
                $locked->advancePeriod(
                    $nextPeriodStart,
                    $periodEnd,
                    $this->hashFor(
                        $locked->getId(),
                        $locked->getSubscriberType(),
                        $locked->getOrder(),
                        $locked->getOffer(),
                        $locked->getBillingInterval(),
                        $nextPeriodStart,
                        $periodEnd,
                    ),
                    $now,
                );

                $this->recordAudit(
                    SecurityAuditAction::CommerceSubscriptionRenewed,
                    $freshActor,
                    $locked,
                    $reasonCode,
                );
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
     * Purchaser-facing cancellation: access stays until the current period ends.
     */
    public function scheduleCancellation(
        CommerceSubscription $subscription,
        User $actor,
        CommerceCancellationReasonCode $cancellationReasonCode,
        string $reasonCode,
    ): CommerceSubscription {
        return $this->mutate(
            $subscription,
            $actor,
            $reasonCode,
            SecurityAuditAction::CommerceSubscriptionCancellationScheduled,
            static function (
                CommerceSubscription $locked,
                \DateTimeImmutable $now,
            ) use ($cancellationReasonCode): void {
                $locked->scheduleCancellation($cancellationReasonCode, $now);
            },
        );
    }

    /**
     * Immediate cancellation. Existing period licenses are **not** revoked here — that is
     * an explicit CommerceFulfillmentManager::reverse() decision.
     */
    public function cancelNow(
        CommerceSubscription $subscription,
        User $actor,
        CommerceCancellationReasonCode $cancellationReasonCode,
        string $reasonCode,
    ): CommerceSubscription {
        return $this->mutate(
            $subscription,
            $actor,
            $reasonCode,
            SecurityAuditAction::CommerceSubscriptionCancelled,
            static function (
                CommerceSubscription $locked,
                \DateTimeImmutable $now,
            ) use ($cancellationReasonCode): void {
                $locked->cancelNow($cancellationReasonCode, $now);
            },
        );
    }

    /**
     * Lazy expiry for a subscription whose period elapsed without a renewal, or which was
     * scheduled for cancellation at period end. Recorded as a System audit.
     */
    public function evaluateAndExpireIfNeeded(CommerceSubscription $subscription): CommerceSubscription
    {
        $subscriptionId = $subscription->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use ($subscriptionId): CommerceSubscription {
                $locked = $this->requireLockedSubscription($subscriptionId);
                $now = $this->utcNow();
                if (!$locked->getStatus()->isTerminal() && $now >= $locked->getCurrentPeriodEnd()) {
                    if ($locked->isCancelAtPeriodEnd()) {
                        $locked->cancelNow(
                            $locked->getCancellationReasonCode() ?? CommerceCancellationReasonCode::PurchaserRequested,
                            $now,
                        );
                        $this->recordAudit(
                            SecurityAuditAction::CommerceSubscriptionCancelled,
                            null,
                            $locked,
                            'lazy_expire',
                            SecurityAuditActorType::System,
                        );
                    } else {
                        $locked->markExpired($now);
                        $this->recordAudit(
                            SecurityAuditAction::CommerceSubscriptionExpired,
                            null,
                            $locked,
                            'lazy_expire',
                            SecurityAuditActorType::System,
                        );
                    }
                    $this->entityManager->flush();
                }

                return $locked;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    public function assertSubscriptionIntegrity(CommerceSubscription $subscription): void
    {
        $this->subscriptionHasher->verify(
            $subscription->getSubscriptionHash(),
            $subscription->getId(),
            $subscription->getSubscriberType(),
            $subscription->getUser()?->getId(),
            $subscription->getInstitution()?->getId(),
            $subscription->getOffer()->getId(),
            $subscription->getPackage()->getId(),
            $subscription->getPackageVersion()->getId(),
            $subscription->getBillingInterval(),
            $subscription->getCurrentPeriodStart(),
            $subscription->getCurrentPeriodEnd(),
            $subscription->getSchemaVersion(),
        );
    }

    /**
     * Period key used to scope fulfillments and license external references.
     */
    public static function periodKeyFor(CommerceSubscription $subscription): string
    {
        return CommerceCanonicalInstant::periodKey($subscription->getCurrentPeriodStart());
    }

    /**
     * @param callable(CommerceSubscription, \DateTimeImmutable): void $mutator
     */
    private function mutate(
        CommerceSubscription $subscription,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        callable $mutator,
    ): CommerceSubscription {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $subscriptionId = $subscription->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $subscriptionId,
                $actorId,
                $reasonCode,
                $action,
                $mutator,
            ): CommerceSubscription {
                $locked = $this->requireLockedSubscription($subscriptionId);
                if ($locked->getInstitution() instanceof Institution) {
                    $this->freshEntities->findFreshLockedInstitution(
                        $locked->getInstitution()->getId(),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                }
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanManageOrder($freshActor, $locked->getOrder());

                $mutator($locked, $this->utcNow());

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

    private function hashFor(
        Uuid $subscriptionId,
        CommerceSubscriberType $subscriberType,
        CommerceOrder $order,
        CommercialOffer $offer,
        CommercialOfferBillingInterval $interval,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): string {
        return $this->subscriptionHasher->hash(
            $subscriptionId,
            $subscriberType,
            $order->getUser()?->getId(),
            $order->getInstitution()?->getId(),
            $offer->getId(),
            $offer->getPackage()->getId(),
            $offer->getPackageVersion()->getId(),
            $interval,
            $periodStart,
            $periodEnd,
        );
    }

    private function requireLockedSubscription(Uuid $subscriptionId): CommerceSubscription
    {
        $locked = $this->freshCommerce->findFreshSubscription($subscriptionId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof CommerceSubscription) {
            throw CommerceException::notFound();
        }

        return $locked;
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
        ?User $actor,
        CommerceSubscription $subscription,
        string $reasonCode,
        SecurityAuditActorType $actorType = SecurityAuditActorType::User,
    ): void {
        if (!$actor instanceof User && SecurityAuditActorType::User === $actorType) {
            $actorType = SecurityAuditActorType::System;
        }

        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: $actorType,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: [
                'source' => 'commerce_subscription_manager',
                'reason_code' => $reasonCode,
                'subscription_id' => $subscription->getId()->toRfc4122(),
                'subscription_hash' => $subscription->getSubscriptionHash(),
                'order_id' => $subscription->getOrder()->getId()->toRfc4122(),
                'offer_id' => $subscription->getOffer()->getId()->toRfc4122(),
                'package_id' => $subscription->getPackage()->getId()->toRfc4122(),
                'package_version_id' => $subscription->getPackageVersion()->getId()->toRfc4122(),
                'subscriber_type' => $subscription->getSubscriberType()->value,
                'institution_id' => $subscription->getInstitution()?->getId()->toRfc4122(),
                'billing_interval' => $subscription->getBillingInterval()->value,
                'period_number' => $subscription->getPeriodNumber(),
                'period_key' => self::periodKeyFor($subscription),
                'period_start' => CommerceCanonicalInstant::format($subscription->getCurrentPeriodStart()),
                'period_end' => CommerceCanonicalInstant::format($subscription->getCurrentPeriodEnd()),
                'status' => $subscription->getStatus()->value,
                'cancellation_reason_code' => $subscription->getCancellationReasonCode()?->value,
            ],
            captureRequestHashes: false,
        ), false);
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
