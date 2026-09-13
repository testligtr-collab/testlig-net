<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccessLicense;
use App\Entity\CommerceFulfillment;
use App\Entity\CommerceOrder;
use App\Entity\CommerceSubscription;
use App\Entity\CommercialOffer;
use App\Entity\Institution;
use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\AccessLicenseLicenseeType;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessLicenseStatus;
use App\Enum\AccessPackageTargetType;
use App\Enum\CommerceCancellationReasonCode;
use App\Enum\CommerceFailureReason;
use App\Enum\CommerceFulfillmentStatus;
use App\Enum\CommerceOrderStatus;
use App\Enum\CommerceSubscriberType;
use App\Enum\CommerceSubscriptionStatus;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentRefundReasonCode;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Money\Money;
use App\Service\CommerceFulfillmentManager;
use App\Service\CommerceOrderManager;
use App\Service\CommerceSubscriptionManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentRefundManager;
use App\Service\PaymentSettlementManager;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Capture → AccessLicense fulfillment, subscription periods and explicit reversal.
 */
final class CommerceFulfillmentTest extends KernelTestCase
{
    use CommerceTestFixtures;

    protected function setUp(): void
    {
        $this->bootCommerce();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        try {
            $this->cleanupCommerce();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testCaptureGrantsExactlyOneActivePurchaseLicense(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('ful1', 10000, 2000);

        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful1-fulfil-0000000000000',
            'fulfil_order',
        );

        self::assertCount(1, $fulfillments);
        $fulfillment = $fulfillments[0];
        self::assertSame(CommerceFulfillmentStatus::Completed, $fulfillment->getStatus());
        self::assertSame(1, $fulfillment->getFulfillmentNumber());
        self::assertNull($fulfillment->getSubscription());
        self::assertNull($fulfillment->getPeriodKey());
        self::assertInstanceOf(\DateTimeImmutable::class, $fulfillment->getFulfilledAt());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fulfillment->getIdempotencyKeyHash());

        $license = $fulfillment->getAccessLicense();
        self::assertInstanceOf(AccessLicense::class, $license);
        self::assertSame(AccessLicenseStatus::Active, $license->getStatus());
        self::assertSame(AccessLicenseSourceType::Purchase, $license->getSourceType());
        self::assertSame(AccessLicenseLicenseeType::User, $license->getLicenseeType());
        self::assertInstanceOf(User::class, $license->getUser());
        self::assertNull($license->getInstitution());
        self::assertSame(
            $fulfillment->getOrderItem()->getPackagePolicySnapshotHash(),
            $license->getPolicySnapshotHash(),
        );

        // One-time window: validFrom is the capture instant, validUntil adds validityDays.
        $capturedAt = $attempt->getCapturedAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $capturedAt);
        self::assertSame($capturedAt->format('Y-m-d H:i:s'), $license->getValidFrom()->format('Y-m-d H:i:s'));
        self::assertSame(
            $capturedAt->add(new \DateInterval('P30D'))->format('Y-m-d H:i:s'),
            $license->getValidUntil()->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            $order->getPublicReference().':oi:'.str_replace('-', '', $fulfillment->getOrderItem()->getId()->toRfc4122()),
            $license->getExternalReference(),
        );

        $paidOrder = $this->scenario->refresh(CommerceOrder::class, $order->getId());
        self::assertSame(CommerceOrderStatus::Paid, $paidOrder->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $paidOrder->getPaidAt());

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceFulfillmentCompleted->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceOrderPaid->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::AccessLicenseCreated->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::AccessLicenseActivated->value));
    }

    public function testReplayingTheSameKeyReturnsTheSameFulfillmentAndLicense(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('ful2');
        $key = 'ful2-fulfil-0000000000000';

        $first = $this->fulfillments()->fulfill($order, $attempt, $operator, $key, 'fulfil_order');
        $second = $this->fulfillments()->fulfill(
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $this->reloadAttempt($attempt),
            $this->scenario->refresh(User::class, $operator->getId()),
            $key,
            'fulfil_order',
        );

        self::assertTrue($first[0]->getId()->equals($second[0]->getId()));
        $firstLicense = $first[0]->getAccessLicense();
        $secondLicense = $second[0]->getAccessLicense();
        self::assertInstanceOf(AccessLicense::class, $firstLicense);
        self::assertInstanceOf(AccessLicense::class, $secondLicense);
        self::assertTrue($firstLicense->getId()->equals($secondLicense->getId()));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM commerce_fulfillments'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));

        $found = $this->fulfillments()->findByIdempotencyKeyForItem($key, $first[0]->getOrderItem()->getId());
        self::assertInstanceOf(CommerceFulfillment::class, $found);
        self::assertTrue($first[0]->getId()->equals($found->getId()));
    }

    public function testASecondFulfillmentWithAFreshKeyIsRefusedForOneTimeItems(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('ful3');
        $this->fulfillments()->fulfill($order, $attempt, $operator, 'ful3-fulfil-a000000000000', 'fulfil_order');

        $this->expectCommerceFailure(
            CommerceFailureReason::AlreadyFulfilled,
            function () use ($order, $attempt, $operator): void {
                $this->fulfillments()->fulfill(
                    $this->scenario->refresh(CommerceOrder::class, $order->getId()),
                    $this->reloadAttempt($attempt),
                    $this->scenario->refresh(User::class, $operator->getId()),
                    'ful3-fulfil-b000000000000',
                    'fulfil_order',
                );
            },
        );
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
    }

    public function testFulfillmentRequiresACapturedAttemptThatBelongsToTheOrder(): void
    {
        $sa = $this->scenario->superAdmin('ful4-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ful4');
        $offer = $this->scenario->activeOffer($sa, $version, 'ful4_code', 10000, 2000);
        $buyer = $this->scenario->activeUser('ful4-buyer@example.com');
        $order = $this->createUserOrder($buyer, $offer);
        $attempt = $this->startAttempt($order, $buyer, 'ful4-key-a00000000000000');

        $this->expectCommerceFailure(
            CommerceFailureReason::PaymentNotCaptured,
            function () use ($order, $attempt, $sa): void {
                $this->fulfillments()->fulfill(
                    $order,
                    $attempt,
                    $sa,
                    'ful4-fulfil-a000000000000',
                    'fulfil_order',
                );
            },
        );

        $otherOrder = $this->createUserOrder(
            $this->scenario->refresh(User::class, $buyer->getId()),
            $this->scenario->refresh(CommercialOffer::class, $offer->getId()),
        );
        $otherAttempt = $this->startAttempt(
            $otherOrder,
            $this->scenario->refresh(User::class, $buyer->getId()),
            'ful4-key-b00000000000000',
        );
        $captured = $this->captureAttempt($otherAttempt, $sa, 'ful4b');
        $this->expectCommerceFailure(
            CommerceFailureReason::ScopeMismatch,
            function () use ($order, $captured, $sa): void {
                $this->fulfillments()->fulfill(
                    $this->scenario->refresh(CommerceOrder::class, $order->getId()),
                    $captured,
                    $this->scenario->refresh(User::class, $sa->getId()),
                    'ful4-fulfil-b000000000000',
                    'fulfil_order',
                );
            },
        );
    }

    public function testOnlyASuperAdminOperatorCanFulfil(): void
    {
        [$order, $attempt, , $buyer] = $this->capturedOneTimeOrderWithBuyer('ful5');
        $admin = $this->scenario->activeUser('ful5-admin@example.com', UserRole::Admin);

        foreach ([$buyer, $admin] as $index => $actor) {
            $this->expectCommerceFailure(
                CommerceFailureReason::Unauthorized,
                function () use ($order, $attempt, $actor, $index): void {
                    $this->fulfillments()->fulfill(
                        $this->scenario->refresh(CommerceOrder::class, $order->getId()),
                        $this->reloadAttempt($attempt),
                        $actor,
                        'ful5-fulfil-'.$index.'-00000000000',
                        'fulfil_order',
                    );
                },
            );
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
    }

    public function testMultiLineOrderGrantsOneLicensePerItem(): void
    {
        $sa = $this->scenario->superAdmin('ful6-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ful6');
        $first = $this->scenario->activeOffer($sa, $version, 'ful6_a', 10000, 2000);
        $second = $this->scenario->activeOffer($sa, $version, 'ful6_b', 15000, 2000);
        $buyer = $this->scenario->activeUser('ful6-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [
                ['offer' => $first, 'quantity' => 1],
                ['offer' => $second, 'quantity' => 1],
            ],
            'create_order',
        );
        $attempt = $this->captureAttempt(
            $this->startAttempt($order, $buyer, 'ful6-key-000000000000000'),
            $sa,
            'ful6',
        );

        $fulfillments = $this->fulfillments()->fulfill(
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $attempt,
            $this->scenario->refresh(User::class, $sa->getId()),
            'ful6-fulfil-000000000000',
            'fulfil_order',
        );
        self::assertCount(2, $fulfillments);
        self::assertSame([1, 2], array_map(
            static fn (CommerceFulfillment $f): int => $f->getFulfillmentNumber(),
            $fulfillments,
        ));
        $licenseIds = [];
        foreach ($fulfillments as $fulfillment) {
            $license = $fulfillment->getAccessLicense();
            self::assertInstanceOf(AccessLicense::class, $license);
            self::assertSame(AccessLicenseStatus::Active, $license->getStatus());
            $licenseIds[] = $license->getId()->toRfc4122();
        }
        self::assertCount(2, array_unique($licenseIds));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
    }

    public function testInstitutionPurchaseGrantsAnInstitutionLicense(): void
    {
        $sa = $this->scenario->superAdmin('ful7-sa@example.com');
        [$institution, $owner] = $this->scenario->activeInstitution('ful7', $sa);
        $sa = $this->scenario->refresh(User::class, $sa->getId());
        $version = $this->scenario->activeVersion(
            $sa,
            'ful7',
            AccessPackageTargetType::Institution,
            365,
            5,
            365,
        );
        $offer = $this->scenario->activeOffer($sa, $version, 'ful7_code', 50000, 2000);
        $order = $this->orders()->createInstitutionOrder(
            $owner,
            $institution,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $attempt = $this->captureAttempt(
            $this->startAttempt($order, $owner, 'ful7-key-000000000000000'),
            $sa,
            'ful7',
        );

        $fulfillments = $this->fulfillments()->fulfill(
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $attempt,
            $this->scenario->refresh(User::class, $sa->getId()),
            'ful7-fulfil-000000000000',
            'fulfil_order',
        );
        $license = $fulfillments[0]->getAccessLicense();
        self::assertInstanceOf(AccessLicense::class, $license);
        self::assertSame(AccessLicenseLicenseeType::Institution, $license->getLicenseeType());
        self::assertInstanceOf(Institution::class, $license->getInstitution());
        self::assertNull($license->getUser());
        self::assertSame(5, $license->getSeatLimit());
        self::assertSame(AccessLicenseSourceType::Purchase, $license->getSourceType());
        self::assertSame(
            $license->getValidFrom()->add(new \DateInterval('P365D'))->format('Y-m-d H:i:s'),
            $license->getValidUntil()->format('Y-m-d H:i:s'),
        );
    }

    public function testRecurringCaptureCreatesAPeriodScopedSubscriptionAndLicense(): void
    {
        [$order, $attempt, $operator] = $this->capturedRecurringOrder('ful8');

        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful8-fulfil-000000000000',
            'fulfil_order',
        );
        $fulfillment = $fulfillments[0];
        $subscription = $fulfillment->getSubscription();
        self::assertInstanceOf(CommerceSubscription::class, $subscription);
        self::assertSame(CommerceSubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame(CommerceSubscriberType::User, $subscription->getSubscriberType());
        self::assertSame(CommercialOfferBillingInterval::Monthly, $subscription->getBillingInterval());
        self::assertSame(1, $subscription->getPeriodNumber());
        self::assertSame('2026-09-13 12:00:00', $subscription->getCurrentPeriodStart()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-13 12:00:00', $subscription->getCurrentPeriodEnd()->format('Y-m-d H:i:s'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $subscription->getSubscriptionHash());
        self::assertFalse($subscription->isCancelAtPeriodEnd());
        self::assertSame('20260913', $fulfillment->getPeriodKey());

        $license = $fulfillment->getAccessLicense();
        self::assertInstanceOf(AccessLicense::class, $license);
        self::assertSame(
            $subscription->getCurrentPeriodStart()->format('Y-m-d H:i:s'),
            $license->getValidFrom()->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            $subscription->getCurrentPeriodEnd()->format('Y-m-d H:i:s'),
            $license->getValidUntil()->format('Y-m-d H:i:s'),
        );
        self::assertStringEndsWith(':p:20260913', (string) $license->getExternalReference());

        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceSubscriptionCreated->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceSubscriptionActivated->value));
    }

    public function testRenewalGrantsANewPeriodLicenseAndTheSamePeriodIsGuarded(): void
    {
        [$order, $attempt, $operator] = $this->capturedRecurringOrder('ful9');
        $first = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful9-fulfil-a00000000000',
            'fulfil_order',
        );
        $subscription = $first[0]->getSubscription();
        self::assertInstanceOf(CommerceSubscription::class, $subscription);

        // A different key for the same period must not mint a second license.
        $this->expectCommerceFailure(
            CommerceFailureReason::AlreadyFulfilled,
            function () use ($order, $attempt, $operator): void {
                $this->fulfillments()->fulfill(
                    $this->scenario->refresh(CommerceOrder::class, $order->getId()),
                    $this->reloadAttempt($attempt),
                    $this->scenario->refresh(User::class, $operator->getId()),
                    'ful9-fulfil-b00000000000',
                    'fulfil_order',
                );
            },
        );

        $this->clock->modify('2026-10-13 12:00:00');
        $renewed = $this->subscriptions()->renew(
            $this->scenario->refresh(CommerceSubscription::class, $subscription->getId()),
            $this->scenario->refresh(User::class, $operator->getId()),
            new \DateTimeImmutable('2026-10-13 12:00:00', new \DateTimeZone('UTC')),
            'renew_subscription',
        );
        self::assertSame(2, $renewed->getPeriodNumber());
        self::assertSame('2026-11-13 12:00:00', $renewed->getCurrentPeriodEnd()->format('Y-m-d H:i:s'));

        $second = $this->fulfillments()->fulfill(
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $this->reloadAttempt($attempt),
            $this->scenario->refresh(User::class, $operator->getId()),
            'ful9-fulfil-c00000000000',
            'fulfil_order',
        );
        self::assertFalse($first[0]->getId()->equals($second[0]->getId()));
        self::assertSame('20261013', $second[0]->getPeriodKey());
        $secondLicense = $second[0]->getAccessLicense();
        $firstLicense = $first[0]->getAccessLicense();
        self::assertInstanceOf(AccessLicense::class, $secondLicense);
        self::assertInstanceOf(AccessLicense::class, $firstLicense);
        self::assertFalse($secondLicense->getId()->equals($firstLicense->getId()));
        self::assertSame('2026-10-13 12:00:00', $secondLicense->getValidFrom()->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-13 12:00:00', $secondLicense->getValidUntil()->format('Y-m-d H:i:s'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceSubscriptionRenewed->value));
    }

    public function testSubscriptionCancellationKeepsTheCurrentPeriodLicense(): void
    {
        [$order, $attempt, $operator, $buyer] = $this->capturedRecurringOrderWithBuyer('ful10');
        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful10-fulfil-00000000000',
            'fulfil_order',
        );
        $subscription = $fulfillments[0]->getSubscription();
        self::assertInstanceOf(CommerceSubscription::class, $subscription);
        $license = $fulfillments[0]->getAccessLicense();
        self::assertInstanceOf(AccessLicense::class, $license);

        $scheduled = $this->subscriptions()->scheduleCancellation(
            $this->scenario->refresh(CommerceSubscription::class, $subscription->getId()),
            $this->scenario->refresh(User::class, $buyer->getId()),
            CommerceCancellationReasonCode::PurchaserRequested,
            'cancel_at_period_end',
        );
        self::assertTrue($scheduled->isCancelAtPeriodEnd());
        self::assertSame(CommerceSubscriptionStatus::Active, $scheduled->getStatus());
        self::assertSame(
            AccessLicenseStatus::Active,
            $this->scenario->refresh(AccessLicense::class, $license->getId())->getStatus(),
        );

        // Lazy expiry after the period end turns the scheduled cancellation into a real one.
        $this->clock->modify('2026-10-13 12:00:01');
        $cancelled = $this->subscriptions()->evaluateAndExpireIfNeeded(
            $this->scenario->refresh(CommerceSubscription::class, $subscription->getId()),
        );
        self::assertSame(CommerceSubscriptionStatus::Cancelled, $cancelled->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $cancelled->getCancelledAt());
        // The period license is untouched: only an explicit reversal revokes access.
        self::assertSame(
            AccessLicenseStatus::Active,
            $this->scenario->refresh(AccessLicense::class, $license->getId())->getStatus(),
        );
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceSubscriptionCancelled->value));
    }

    public function testSubscriptionExpiresWhenAPeriodElapsesWithoutRenewal(): void
    {
        [$order, $attempt, $operator] = $this->capturedRecurringOrder('ful11');
        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful11-fulfil-00000000000',
            'fulfil_order',
        );
        $subscription = $fulfillments[0]->getSubscription();
        self::assertInstanceOf(CommerceSubscription::class, $subscription);

        $stillActive = $this->subscriptions()->evaluateAndExpireIfNeeded(
            $this->scenario->refresh(CommerceSubscription::class, $subscription->getId()),
        );
        self::assertSame(CommerceSubscriptionStatus::Active, $stillActive->getStatus());

        $this->clock->modify('2026-10-13 12:00:00');
        $expired = $this->subscriptions()->evaluateAndExpireIfNeeded(
            $this->scenario->refresh(CommerceSubscription::class, $subscription->getId()),
        );
        self::assertSame(CommerceSubscriptionStatus::Expired, $expired->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $expired->getExpiredAt());
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceSubscriptionExpired->value));
    }

    public function testOnlyThePurchaserCanCancelASubscription(): void
    {
        [$order, $attempt, $operator] = $this->capturedRecurringOrder('ful12');
        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful12-fulfil-00000000000',
            'fulfil_order',
        );
        $subscription = $fulfillments[0]->getSubscription();
        self::assertInstanceOf(CommerceSubscription::class, $subscription);
        $stranger = $this->scenario->activeUser('ful12-stranger@example.com');

        foreach ([$stranger, $operator] as $actor) {
            $this->expectCommerceFailure(
                CommerceFailureReason::Unauthorized,
                function () use ($subscription, $actor): void {
                    $this->subscriptions()->cancelNow(
                        $this->scenario->refresh(CommerceSubscription::class, $subscription->getId()),
                        $actor,
                        CommerceCancellationReasonCode::PurchaserRequested,
                        'cancel_now',
                    );
                },
            );
        }
    }

    public function testReversalRevokesTheLicenseAndIsNotRepeatable(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('ful13');
        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful13-fulfil-00000000000',
            'fulfil_order',
        );
        $license = $fulfillments[0]->getAccessLicense();
        self::assertInstanceOf(AccessLicense::class, $license);

        $reversed = $this->fulfillments()->reverse(
            $fulfillments[0],
            $this->scenario->refresh(User::class, $operator->getId()),
            'operator_reversal',
            'reverse_fulfillment',
        );
        self::assertSame(CommerceFulfillmentStatus::Reversed, $reversed->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $reversed->getReversedAt());
        self::assertSame('operator_reversal', $reversed->getReversalReasonCode());

        $revoked = $this->scenario->refresh(AccessLicense::class, $license->getId());
        self::assertSame(AccessLicenseStatus::Revoked, $revoked->getStatus());
        self::assertSame('operator_reversal', $revoked->getRevocationReasonCode());
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceFulfillmentReversed->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::AccessLicenseRevoked->value));

        $this->expectCommerceFailure(
            CommerceFailureReason::InvalidTransition,
            function () use ($reversed, $operator): void {
                $this->fulfillments()->reverse(
                    $reversed,
                    $this->scenario->refresh(User::class, $operator->getId()),
                    'operator_reversal',
                    'reverse_fulfillment',
                );
            },
        );
    }

    public function testAFullRefundAloneNeverRevokesAccess(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('ful14', 10000, 2000);
        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful14-fulfil-00000000000',
            'fulfil_order',
        );
        $license = $fulfillments[0]->getAccessLicense();
        self::assertInstanceOf(AccessLicense::class, $license);

        $refund = $this->refunds()->request(
            $this->reloadAttempt($attempt),
            $this->scenario->refresh(User::class, $operator->getId()),
            Money::fromMinor(12000, 'TRY'),
            PaymentRefundReasonCode::PurchaserRequested,
            'ful14-refund-00000000000',
            'refund_full',
        );
        $this->refunds()->markSucceeded(
            $refund,
            $this->scenario->refresh(User::class, $operator->getId()),
            'ful14-settle-00000000000',
            'refund_settled',
        );

        self::assertSame(
            AccessLicenseStatus::Active,
            $this->scenario->refresh(AccessLicense::class, $license->getId())->getStatus(),
        );
        self::assertSame(
            CommerceFulfillmentStatus::Completed,
            $this->scenario->refresh(CommerceFulfillment::class, $fulfillments[0]->getId())->getStatus(),
        );
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM commerce_fulfillments'));

        // Access disappears only when an operator reverses the fulfillment explicitly.
        $this->fulfillments()->reverse(
            $this->scenario->refresh(CommerceFulfillment::class, $fulfillments[0]->getId()),
            $this->scenario->refresh(User::class, $operator->getId()),
            'refund_reversal',
            'reverse_fulfillment',
        );
        self::assertSame(
            AccessLicenseStatus::Revoked,
            $this->scenario->refresh(AccessLicense::class, $license->getId())->getStatus(),
        );
    }

    public function testFulfillmentRowsAreImmutableAndUndeletableInTheDatabase(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('ful15');
        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'ful15-fulfil-00000000000',
            'fulfil_order',
        );
        $connection = $this->em->getConnection();
        $fulfillmentId = $fulfillments[0]->getId()->toBinary();

        $this->expectDatabaseRejection(static function () use ($connection, $fulfillmentId): void {
            $connection->executeStatement(
                'UPDATE commerce_fulfillments SET fulfillment_number = 9 WHERE id = ?',
                [$fulfillmentId],
            );
        });
        $this->expectDatabaseRejection(static function () use ($connection, $fulfillmentId): void {
            $connection->executeStatement(
                'UPDATE commerce_fulfillments SET access_license_id = NULL WHERE id = ?',
                [$fulfillmentId],
            );
        });
        $this->expectDatabaseRejection(static function () use ($connection, $fulfillmentId): void {
            $connection->executeStatement('DELETE FROM commerce_fulfillments WHERE id = ?', [$fulfillmentId]);
        });
    }

    public function testTheDatabaseRefusesAFulfillmentForAnUncapturedAttempt(): void
    {
        $sa = $this->scenario->superAdmin('ful16-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ful16');
        $offer = $this->scenario->activeOffer($sa, $version, 'ful16_code', 10000, 2000);
        $buyer = $this->scenario->activeUser('ful16-buyer@example.com');
        $order = $this->createUserOrder($buyer, $offer);
        $attempt = $this->startAttempt($order, $buyer, 'ful16-key-00000000000000');

        // Even a caller bypassing the managers cannot grant access before capture.
        $connection = $this->em->getConnection();
        $orderId = $order->getId()->toBinary();
        $attemptId = $attempt->getId()->toBinary();
        $this->expectDatabaseRejection(static function () use ($connection, $orderId, $attemptId): void {
            $connection->executeStatement(
                'INSERT INTO commerce_fulfillments (id, order_id, order_item_id, payment_attempt_id, subscription_id,'
                .' access_license_id, fulfillment_number, status, idempotency_key_hash, period_key, created_at,'
                .' updated_at, fulfilled_at, reversed_at, failure_code, reversal_reason_code)'
                ." SELECT ?, i.order_id, i.id, ?, NULL, NULL, 99, 'pending', ?, NULL, ?, ?, NULL, NULL, NULL, NULL"
                .' FROM commerce_order_items i WHERE i.order_id = ? LIMIT 1',
                [
                    \Symfony\Component\Uid\Uuid::v7()->toBinary(),
                    $attemptId,
                    str_repeat('a', 64),
                    '2026-09-13 12:00:00',
                    '2026-09-13 12:00:00',
                    $orderId,
                ],
            );
        });
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM commerce_fulfillments'));
    }

    /**
     * @return array{0: CommerceOrder, 1: PaymentAttempt, 2: User}
     */
    private function capturedOneTimeOrder(string $suffix, int $price = 19999, int $taxRateBasisPoints = 2000): array
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrderWithBuyer($suffix, $price, $taxRateBasisPoints);

        return [$order, $attempt, $operator];
    }

    /**
     * @return array{0: CommerceOrder, 1: PaymentAttempt, 2: User, 3: User}
     */
    private function capturedOneTimeOrderWithBuyer(
        string $suffix,
        int $price = 19999,
        int $taxRateBasisPoints = 2000,
    ): array {
        $sa = $this->scenario->superAdmin($suffix.'-sa@example.com');
        $version = $this->scenario->activeVersion($sa, $suffix);
        $offer = $this->scenario->activeOffer($sa, $version, $suffix.'_code', $price, $taxRateBasisPoints);
        $buyer = $this->scenario->activeUser($suffix.'-buyer@example.com');
        $order = $this->createUserOrder($buyer, $offer);
        $attempt = $this->captureAttempt(
            $this->startAttempt($order, $buyer, $suffix.'-key-000000000000000'),
            $sa,
            $suffix,
        );

        return [
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $attempt,
            $this->scenario->refresh(User::class, $sa->getId()),
            $this->scenario->refresh(User::class, $buyer->getId()),
        ];
    }

    /**
     * @return array{0: CommerceOrder, 1: PaymentAttempt, 2: User}
     */
    private function capturedRecurringOrder(string $suffix): array
    {
        [$order, $attempt, $operator] = $this->capturedRecurringOrderWithBuyer($suffix);

        return [$order, $attempt, $operator];
    }

    /**
     * @return array{0: CommerceOrder, 1: PaymentAttempt, 2: User, 3: User}
     */
    private function capturedRecurringOrderWithBuyer(string $suffix): array
    {
        $sa = $this->scenario->superAdmin($suffix.'-sa@example.com');
        $version = $this->scenario->activeVersion($sa, $suffix);
        $offer = $this->scenario->activeOffer(
            $sa,
            $version,
            $suffix.'_code',
            29999,
            2000,
            CommercialOfferBillingType::Recurring,
            CommercialOfferBillingInterval::Monthly,
        );
        $buyer = $this->scenario->activeUser($suffix.'-buyer@example.com');
        $order = $this->createUserOrder($buyer, $offer);
        $attempt = $this->captureAttempt(
            $this->startAttempt($order, $buyer, $suffix.'-key-000000000000000'),
            $sa,
            $suffix,
        );

        return [
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $attempt,
            $this->scenario->refresh(User::class, $sa->getId()),
            $this->scenario->refresh(User::class, $buyer->getId()),
        ];
    }

    private function createUserOrder(User $buyer, CommercialOffer $offer): CommerceOrder
    {
        return $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
    }

    private function startAttempt(CommerceOrder $order, User $buyer, string $idempotencyKey): PaymentAttempt
    {
        return $this->attempts()->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $idempotencyKey,
            'start_payment',
        );
    }

    private function captureAttempt(PaymentAttempt $attempt, User $settlementActor, string $suffix): PaymentAttempt
    {
        $this->settlement()->recordAuthorized(
            $this->reloadAttempt($attempt),
            $settlementActor,
            $attempt->getAmount(),
            $this->clock->now(),
            $suffix.'-auth-000000000000000',
            'authorize',
            'prov_pay_'.$suffix,
            'prov_auth_'.$suffix,
        );
        $this->settlement()->recordCaptured(
            $this->reloadAttempt($attempt),
            $settlementActor,
            $attempt->getAmount(),
            $this->clock->now(),
            $suffix.'-cap-0000000000000000',
            'capture',
            'prov_pay_'.$suffix,
        );

        return $this->reloadAttempt($attempt);
    }

    private function reloadAttempt(PaymentAttempt $attempt): PaymentAttempt
    {
        return $this->scenario->refresh(PaymentAttempt::class, $attempt->getId());
    }

    private function orders(): CommerceOrderManager
    {
        return $this->scenario->service(CommerceOrderManager::class);
    }

    private function attempts(): PaymentAttemptManager
    {
        return $this->scenario->service(PaymentAttemptManager::class);
    }

    private function settlement(): PaymentSettlementManager
    {
        return $this->scenario->service(PaymentSettlementManager::class);
    }

    private function subscriptions(): CommerceSubscriptionManager
    {
        return $this->scenario->service(CommerceSubscriptionManager::class);
    }

    private function fulfillments(): CommerceFulfillmentManager
    {
        return $this->scenario->service(CommerceFulfillmentManager::class);
    }

    private function refunds(): PaymentRefundManager
    {
        return $this->scenario->service(PaymentRefundManager::class);
    }
}
