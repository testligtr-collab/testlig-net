<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CommerceOrder;
use App\Entity\CommercialOffer;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentEvent;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Enum\CommerceCancellationReasonCode;
use App\Enum\CommerceFailureReason;
use App\Enum\CommerceOrderStatus;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentRefundReasonCode;
use App\Enum\PaymentRefundStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Money\Money;
use App\Repository\PaymentEventRepository;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentRefundManager;
use App\Service\PaymentSettlementManager;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Payment attempt, settlement event log and refund behaviour.
 */
final class CommercePaymentSettlementTest extends KernelTestCase
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

    public function testAttemptSnapshotsTheOrderTotalAndMovesTheOrderToAwaitingPayment(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay1', 10000, 2000);

        $attempt = $this->attempts()->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            'pay1-key-0000000000000000',
            'start_payment',
        );

        self::assertSame(1, $attempt->getAttemptNumber());
        self::assertSame(PaymentAttemptStatus::Initiated, $attempt->getStatus());
        self::assertSame('sandbox_provider', $attempt->getProviderCode());
        self::assertSame(PaymentProviderEnvironment::Sandbox, $attempt->getEnvironment());
        self::assertSame(12000, $attempt->getAmountMinor());
        self::assertSame('TRY', $attempt->getCurrency());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $attempt->getIdempotencyKeyHash());
        self::assertNull($attempt->getProviderPaymentReference());
        self::assertSame(0, $attempt->getEventSequence());
        self::assertSame(CommerceOrderStatus::AwaitingPayment, $attempt->getOrder()->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $attempt->getOrder()->getPaymentStartedAt());
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentAttemptStarted->value));
    }

    public function testRawIdempotencyKeyIsNeverPersistedAndReplayReturnsTheSameAttempt(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay2');
        $key = 'pay2-key-1111111111111111';

        $first = $this->attempts()->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $key,
            'start_payment',
        );
        $second = $this->attempts()->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $key,
            'start_payment',
        );
        self::assertTrue($first->getId()->equals($second->getId()));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_attempts'));

        $found = $this->attempts()->findByIdempotencyKey($key);
        self::assertInstanceOf(PaymentAttempt::class, $found);
        self::assertTrue($first->getId()->equals($found->getId()));

        // The raw key must exist nowhere in the schema, and not in the audit trail either.
        $storedHashes = $this->em->getConnection()->fetchFirstColumn('SELECT idempotency_key_hash FROM payment_attempts');
        foreach ($storedHashes as $hash) {
            self::assertNotSame($key, $hash);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
        }
        $auditMetadata = $this->em->getConnection()->fetchFirstColumn('SELECT metadata FROM security_audit_events');
        foreach ($auditMetadata as $metadata) {
            self::assertStringNotContainsString($key, (string) $metadata);
        }
    }

    public function testTheSameKeyCannotBeReusedForADifferentOrderOrProvider(): void
    {
        [$order, $offer, $buyer] = $this->draftOrder('pay3');
        $key = 'pay3-key-2222222222222222';
        $this->attempts()->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $key,
            'start_payment',
        );

        $otherOrder = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $this->expectCommerceFailure(
            CommerceFailureReason::IdempotencyConflict,
            function () use ($otherOrder, $buyer, $key): void {
                $this->attempts()->start(
                    $otherOrder,
                    $buyer,
                    'sandbox_provider',
                    PaymentProviderEnvironment::Sandbox,
                    $key,
                    'start_payment',
                );
            },
        );
        $this->expectCommerceFailure(
            CommerceFailureReason::IdempotencyConflict,
            function () use ($order, $buyer, $key): void {
                $this->attempts()->start(
                    $order,
                    $buyer,
                    'other_provider',
                    PaymentProviderEnvironment::Sandbox,
                    $key,
                    'start_payment',
                );
            },
        );
    }

    public function testOnlyThePurchaserCanStartAPayment(): void
    {
        [$order] = $this->draftOrder('pay4');
        $stranger = $this->scenario->activeUser('pay4-stranger@example.com');
        $admin = $this->scenario->activeUser('pay4-admin@example.com', UserRole::Admin);
        $sa = $this->scenario->activeUser('pay4-sa2@example.com', UserRole::SuperAdmin);

        foreach ([$stranger, $admin, $sa] as $index => $actor) {
            $this->expectCommerceFailure(
                CommerceFailureReason::Unauthorized,
                function () use ($order, $actor, $index): void {
                    $this->attempts()->start(
                        $order,
                        $actor,
                        'sandbox_provider',
                        PaymentProviderEnvironment::Sandbox,
                        'pay4-key-'.$index.'-333333333333',
                        'start_payment',
                    );
                },
            );
        }
    }

    public function testCancelledAndExpiredOrdersRefusePayments(): void
    {
        [$order, $offer, $buyer] = $this->draftOrder('pay5');
        $this->orders()->cancel($order, $buyer, CommerceCancellationReasonCode::PurchaserRequested, 'cancel_order');
        $this->expectCommerceFailure(
            CommerceFailureReason::InvalidTransition,
            function () use ($order, $buyer): void {
                $this->attempts()->start(
                    $order,
                    $buyer,
                    'sandbox_provider',
                    PaymentProviderEnvironment::Sandbox,
                    'pay5-key-4444444444444444',
                    'start_payment',
                );
            },
        );

        $second = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $this->clock->modify('2026-09-13 13:00:01');
        $this->expectCommerceFailure(
            CommerceFailureReason::InvalidTransition,
            function () use ($second, $buyer): void {
                $this->attempts()->start(
                    $second,
                    $buyer,
                    'sandbox_provider',
                    PaymentProviderEnvironment::Sandbox,
                    'pay5-key-5555555555555555',
                    'start_payment',
                );
            },
        );
        $expired = $this->scenario->refresh(CommerceOrder::class, $second->getId());
        self::assertSame(CommerceOrderStatus::Expired, $expired->getStatus());
    }

    public function testSettlementBuildsAVerifiableHashChain(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay6', 10000, 2000);
        $sa = $this->scenario->superAdmin('pay6-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, 'pay6-key-6666666666666666');

        $authorized = $this->settlement()->recordAuthorized(
            $attempt,
            $sa,
            $attempt->getAmount(),
            $this->clock->now(),
            'pay6-auth-6666666666666666',
            'authorize',
            'prov_pay_6',
            'prov_auth_6',
            'prov_evt_6a',
            ['provider_code' => 'sandbox_provider', 'installment_count' => 1],
        );
        self::assertSame(PaymentEventType::Authorized, $authorized->getEventType());
        self::assertSame(1, $authorized->getSequenceNumber());
        self::assertNull($authorized->getPreviousEventHash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $authorized->getEventHash());
        self::assertSame(12000, $authorized->getAmountMinor());
        self::assertSame(['installment_count' => 1, 'provider_code' => 'sandbox_provider'], $authorized->getSanitizedMetadata());
        self::assertSame(PaymentAttemptStatus::Authorized, $authorized->getAttempt()->getStatus());
        self::assertSame('prov_pay_6', $authorized->getAttempt()->getProviderPaymentReference());

        $captured = $this->settlement()->recordCaptured(
            $this->reloadAttempt($attempt),
            $sa,
            $attempt->getAmount(),
            $this->clock->now(),
            'pay6-cap-6666666666666666',
            'capture',
            'prov_pay_6',
            'prov_evt_6b',
        );
        self::assertSame(2, $captured->getSequenceNumber());
        self::assertSame($authorized->getEventHash(), $captured->getPreviousEventHash());
        self::assertSame(PaymentAttemptStatus::Captured, $captured->getAttempt()->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $captured->getAttempt()->getCapturedAt());
        // Capture alone must not mark the order paid: only fulfillment does that.
        self::assertSame(CommerceOrderStatus::AwaitingPayment, $captured->getAttempt()->getOrder()->getStatus());

        $this->settlement()->assertEventChainIntegrity($attempt->getId());
        $capture = $this->settlement()->requireCaptureEvent($this->reloadAttempt($attempt));
        self::assertTrue($capture->getId()->equals($captured->getId()));

        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentAuthorized->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentCaptured->value));
    }

    public function testReplayedSettlementKeyReturnsTheSameEventAndConflictsAcrossTypes(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay7');
        $sa = $this->scenario->superAdmin('pay7-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, 'pay7-key-7777777777777777');
        $key = 'pay7-auth-7777777777777777';

        $first = $this->settlement()->recordAuthorized(
            $attempt,
            $sa,
            $attempt->getAmount(),
            $this->clock->now(),
            $key,
            'authorize',
        );
        $second = $this->settlement()->recordAuthorized(
            $this->reloadAttempt($attempt),
            $sa,
            $attempt->getAmount(),
            $this->clock->now(),
            $key,
            'authorize',
        );
        self::assertTrue($first->getId()->equals($second->getId()));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));

        $this->expectCommerceFailure(
            CommerceFailureReason::IdempotencyConflict,
            function () use ($attempt, $sa, $key): void {
                $this->settlement()->recordCaptured(
                    $this->reloadAttempt($attempt),
                    $sa,
                    $attempt->getAmount(),
                    $this->clock->now(),
                    $key,
                    'capture',
                );
            },
        );
    }

    public function testSettlementRejectsWrongAmountsCurrenciesFutureEventsAndDoubleCapture(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay8', 10000, 2000);
        $sa = $this->scenario->superAdmin('pay8-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, 'pay8-key-8888888888888888');

        $this->expectCommerceFailure(
            CommerceFailureReason::TotalMismatch,
            function () use ($attempt, $sa): void {
                $this->settlement()->recordCaptured(
                    $this->reloadAttempt($attempt),
                    $sa,
                    Money::fromMinor(11999, 'TRY'),
                    $this->clock->now(),
                    'pay8-cap-a888888888888888',
                    'capture',
                );
            },
        );
        $this->expectCommerceFailure(
            CommerceFailureReason::CurrencyMismatch,
            function () use ($attempt, $sa): void {
                $this->settlement()->recordCaptured(
                    $this->reloadAttempt($attempt),
                    $sa,
                    Money::fromMinor(12000, 'USD'),
                    $this->clock->now(),
                    'pay8-cap-b888888888888888',
                    'capture',
                );
            },
        );
        $this->expectCommerceFailure(
            CommerceFailureReason::InvalidInput,
            function () use ($attempt, $sa): void {
                $this->settlement()->recordCaptured(
                    $this->reloadAttempt($attempt),
                    $sa,
                    Money::fromMinor(12000, 'TRY'),
                    $this->clock->now()->modify('+1 hour'),
                    'pay8-cap-c888888888888888',
                    'capture',
                );
            },
        );

        $this->settlement()->recordCaptured(
            $this->reloadAttempt($attempt),
            $sa,
            Money::fromMinor(12000, 'TRY'),
            $this->clock->now(),
            'pay8-cap-d888888888888888',
            'capture',
        );
        $this->expectCommerceFailure(
            CommerceFailureReason::InvalidTransition,
            function () use ($attempt, $sa): void {
                $this->settlement()->recordCaptured(
                    $this->reloadAttempt($attempt),
                    $sa,
                    Money::fromMinor(12000, 'TRY'),
                    $this->clock->now(),
                    'pay8-cap-e888888888888888',
                    'capture',
                );
            },
        );
    }

    public function testOnlyASuperAdminOperatorCanRecordSettlementEvents(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay9');
        $attempt = $this->startAttempt($order, $buyer, 'pay9-key-9999999999999999');
        $moderator = $this->scenario->activeUser('pay9-mod@example.com', UserRole::Moderator);

        foreach ([$buyer, $moderator] as $index => $actor) {
            $this->expectCommerceFailure(
                CommerceFailureReason::Unauthorized,
                function () use ($attempt, $actor, $index): void {
                    $this->settlement()->recordCaptured(
                        $this->reloadAttempt($attempt),
                        $actor,
                        $attempt->getAmount(),
                        $this->clock->now(),
                        'pay9-cap-'.$index.'-99999999999',
                        'capture',
                    );
                },
            );
        }
    }

    public function testFailureMarksTheOrderFailedAndAllowsARetryAttempt(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay10');
        $sa = $this->scenario->superAdmin('pay10-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, 'pay10-key-aaaaaaaaaaaaaaa');

        $failed = $this->settlement()->recordFailed(
            $attempt,
            $sa,
            'provider_declined',
            $this->clock->now(),
            'pay10-fail-aaaaaaaaaaaaaaa',
            'fail_payment',
        );
        self::assertSame(PaymentAttemptStatus::Failed, $failed->getAttempt()->getStatus());
        self::assertSame('provider_declined', $failed->getAttempt()->getFailureCode());
        self::assertSame(CommerceOrderStatus::Failed, $failed->getAttempt()->getOrder()->getStatus());
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentFailed->value));

        $retry = $this->attempts()->start(
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $this->scenario->refresh(User::class, $buyer->getId()),
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            'pay10-key-bbbbbbbbbbbbbbb',
            'start_payment',
        );
        self::assertSame(2, $retry->getAttemptNumber());
        self::assertSame(CommerceOrderStatus::AwaitingPayment, $retry->getOrder()->getStatus());
    }

    public function testCancellationMarksTheOrderFailedAndBlocksLaterCapture(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay11');
        $sa = $this->scenario->superAdmin('pay11-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, 'pay11-key-ccccccccccccccc');

        $cancelled = $this->settlement()->recordCancelled(
            $attempt,
            $sa,
            'provider_cancelled',
            $this->clock->now(),
            'pay11-cancel-cccccccccccccc',
            'cancel_payment',
        );
        self::assertSame(PaymentAttemptStatus::Cancelled, $cancelled->getAttempt()->getStatus());
        self::assertSame(CommerceOrderStatus::Failed, $cancelled->getAttempt()->getOrder()->getStatus());

        $this->expectCommerceFailure(
            CommerceFailureReason::InvalidTransition,
            function () use ($attempt, $sa): void {
                $this->settlement()->recordCaptured(
                    $this->reloadAttempt($attempt),
                    $sa,
                    $attempt->getAmount(),
                    $this->clock->now(),
                    'pay11-cap-ccccccccccccccc',
                    'capture',
                );
            },
        );
    }

    public function testPaymentEventsAreAppendOnlyInTheDatabase(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay12');
        $sa = $this->scenario->superAdmin('pay12-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, 'pay12-key-ddddddddddddddd');
        $event = $this->settlement()->recordAuthorized(
            $attempt,
            $sa,
            $attempt->getAmount(),
            $this->clock->now(),
            'pay12-auth-dddddddddddddd',
            'authorize',
        );

        $connection = $this->em->getConnection();
        $eventId = $event->getId()->toBinary();
        $this->expectDatabaseRejection(static function () use ($connection, $eventId): void {
            $connection->executeStatement(
                "UPDATE payment_events SET event_type = 'captured' WHERE id = ?",
                [$eventId],
            );
        });
        $this->expectDatabaseRejection(static function () use ($connection, $eventId): void {
            $connection->executeStatement('DELETE FROM payment_events WHERE id = ?', [$eventId]);
        });
    }

    public function testTamperedEventChainIsDetected(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay13');
        $sa = $this->scenario->superAdmin('pay13-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, 'pay13-key-eeeeeeeeeeeeeee');
        $first = $this->settlement()->recordAuthorized(
            $attempt,
            $sa,
            $attempt->getAmount(),
            $this->clock->now(),
            'pay13-auth-eeeeeeeeeeeeee',
            'authorize',
        );
        $this->settlement()->assertEventChainIntegrity($attempt->getId());

        // The append-only trigger blocks UPDATE and DELETE, and the insert trigger enforces
        // chaining, so drift is simulated with a correctly chained row whose event_hash was
        // never derived from its own payload.
        $this->em->getConnection()->executeStatement(
            'INSERT INTO payment_events (id, attempt_id, sequence_number, event_type, provider_event_reference,'
            .' idempotency_key_hash, occurred_at, received_at, amount_minor, currency, sanitized_metadata,'
            .' event_hash, previous_event_hash)'
            .' VALUES (?, ?, 2, ?, NULL, ?, ?, ?, NULL, NULL, ?, ?, ?)',
            [
                \Symfony\Component\Uid\Uuid::v7()->toBinary(),
                $attempt->getId()->toBinary(),
                PaymentEventType::Failed->value,
                str_repeat('b', 64),
                '2026-09-13 12:00:00',
                '2026-09-13 12:00:00',
                '{}',
                str_repeat('c', 64),
                $first->getEventHash(),
            ],
        );
        $this->em->clear();

        $this->expectCommerceFailure(
            CommerceFailureReason::HashMismatch,
            function () use ($attempt): void {
                $this->settlement()->assertEventChainIntegrity($attempt->getId());
            },
        );
    }

    public function testRequireCaptureEventFailsWithoutACapture(): void
    {
        [$order, , $buyer] = $this->draftOrder('pay14');
        $attempt = $this->startAttempt($order, $buyer, 'pay14-key-fffffffffffffff');

        $this->expectCommerceFailure(
            CommerceFailureReason::PaymentNotCaptured,
            function () use ($attempt): void {
                $this->settlement()->requireCaptureEvent($this->reloadAttempt($attempt));
            },
        );
    }

    public function testPartialRefundIsRecordedWithoutTouchingEntitlements(): void
    {
        [$attempt, $sa] = $this->capturedAttempt('ref1', 10000, 2000);

        $refund = $this->refunds()->request(
            $attempt,
            $sa,
            Money::fromMinor(2000, 'TRY'),
            PaymentRefundReasonCode::PurchaserRequested,
            'ref1-refund-000000000000',
            'refund_partial',
        );
        self::assertSame(1, $refund->getRefundNumber());
        self::assertSame(PaymentRefundStatus::Requested, $refund->getStatus());
        self::assertSame(2000, $refund->getAmountMinor());
        self::assertSame('TRY', $refund->getCurrency());
        self::assertFalse($refund->isFullRefundOf($this->reloadAttempt($attempt)));
        self::assertSame(2000, $this->refunds()->reservedAmount($this->reloadAttempt($attempt))->getAmountMinor());

        $settled = $this->refunds()->markSucceeded(
            $refund,
            $sa,
            'ref1-settle-000000000000',
            'refund_settled',
            'prov_refund_1',
        );
        self::assertSame(PaymentRefundStatus::Succeeded, $settled->getStatus());
        self::assertSame('prov_refund_1', $settled->getProviderRefundReference());
        self::assertInstanceOf(\DateTimeImmutable::class, $settled->getSucceededAt());
        // The attempt stays captured; refunds are money movement only.
        self::assertSame(PaymentAttemptStatus::Captured, $this->reloadAttempt($attempt)->getStatus());

        $events = $this->events()->findChainForAttempt($attempt->getId());
        $types = array_map(static fn (PaymentEvent $event): string => $event->getEventType()->value, $events);
        self::assertSame(
            ['authorized', 'captured', 'refund_requested', 'refund_succeeded'],
            $types,
        );
        $this->settlement()->assertEventChainIntegrity($attempt->getId());
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentRefundRequested->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentRefundSucceeded->value));
    }

    public function testRefundsCannotExceedTheCapturedAmount(): void
    {
        [$attempt, $sa] = $this->capturedAttempt('ref2', 10000, 2000);

        $this->refunds()->request(
            $attempt,
            $sa,
            Money::fromMinor(11000, 'TRY'),
            PaymentRefundReasonCode::PurchaserRequested,
            'ref2-refund-a00000000000',
            'refund_partial',
        );
        $this->expectCommerceFailure(
            CommerceFailureReason::RefundExceedsCapture,
            function () use ($attempt, $sa): void {
                $this->refunds()->request(
                    $this->reloadAttempt($attempt),
                    $sa,
                    Money::fromMinor(1001, 'TRY'),
                    PaymentRefundReasonCode::PurchaserRequested,
                    'ref2-refund-b00000000000',
                    'refund_partial',
                );
            },
        );

        // The remaining 1000 minor units are still refundable.
        $second = $this->refunds()->request(
            $this->reloadAttempt($attempt),
            $sa,
            Money::fromMinor(1000, 'TRY'),
            PaymentRefundReasonCode::PurchaserRequested,
            'ref2-refund-c00000000000',
            'refund_partial',
        );
        self::assertSame(2, $second->getRefundNumber());
        self::assertSame(12000, $this->refunds()->reservedAmount($this->reloadAttempt($attempt))->getAmountMinor());
    }

    public function testRefundCapIsAlsoEnforcedByTheDatabase(): void
    {
        [$attempt, $sa] = $this->capturedAttempt('ref3', 10000, 2000);
        $refund = $this->refunds()->request(
            $attempt,
            $sa,
            Money::fromMinor(12000, 'TRY'),
            PaymentRefundReasonCode::PurchaserRequested,
            'ref3-refund-000000000000',
            'refund_full',
        );
        self::assertTrue($refund->isFullRefundOf($this->reloadAttempt($attempt)));

        $connection = $this->em->getConnection();
        $attemptId = $attempt->getId()->toBinary();
        $this->expectDatabaseRejection(static function () use ($connection, $attemptId): void {
            $connection->executeStatement(
                'INSERT INTO payment_refunds (id, payment_attempt_id, refund_number, status, amount_minor, currency,'
                .' reason_code, provider_refund_reference, idempotency_key_hash, failure_code, created_at, updated_at)'
                ." VALUES (?, ?, 2, 'requested', 1, 'TRY', 'purchaser_requested', NULL, ?, NULL, ?, ?)",
                [
                    \Symfony\Component\Uid\Uuid::v7()->toBinary(),
                    $attemptId,
                    str_repeat('e', 64),
                    '2026-09-13 12:00:00',
                    '2026-09-13 12:00:00',
                ],
            );
        });
    }

    public function testRefundReplayIsIdempotentAndMismatchedReplayConflicts(): void
    {
        [$attempt, $sa] = $this->capturedAttempt('ref4', 10000, 2000);
        $key = 'ref4-refund-000000000000';

        $first = $this->refunds()->request(
            $attempt,
            $sa,
            Money::fromMinor(2000, 'TRY'),
            PaymentRefundReasonCode::PurchaserRequested,
            $key,
            'refund_partial',
        );
        $second = $this->refunds()->request(
            $this->reloadAttempt($attempt),
            $sa,
            Money::fromMinor(2000, 'TRY'),
            PaymentRefundReasonCode::PurchaserRequested,
            $key,
            'refund_partial',
        );
        self::assertTrue($first->getId()->equals($second->getId()));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_refunds'));

        $this->expectCommerceFailure(
            CommerceFailureReason::IdempotencyConflict,
            function () use ($attempt, $sa, $key): void {
                $this->refunds()->request(
                    $this->reloadAttempt($attempt),
                    $sa,
                    Money::fromMinor(3000, 'TRY'),
                    PaymentRefundReasonCode::PurchaserRequested,
                    $key,
                    'refund_partial',
                );
            },
        );

        $found = $this->refunds()->findByIdempotencyKey($key);
        self::assertInstanceOf(PaymentRefund::class, $found);
        self::assertTrue($first->getId()->equals($found->getId()));
    }

    public function testRefundFailureIsTerminalAndCurrencyIsChecked(): void
    {
        [$attempt, $sa] = $this->capturedAttempt('ref5', 10000, 2000);

        $this->expectCommerceFailure(
            CommerceFailureReason::CurrencyMismatch,
            function () use ($attempt, $sa): void {
                $this->refunds()->request(
                    $this->reloadAttempt($attempt),
                    $sa,
                    Money::fromMinor(1000, 'USD'),
                    PaymentRefundReasonCode::PurchaserRequested,
                    'ref5-refund-a00000000000',
                    'refund_partial',
                );
            },
        );

        $refund = $this->refunds()->request(
            $this->reloadAttempt($attempt),
            $sa,
            Money::fromMinor(1000, 'TRY'),
            PaymentRefundReasonCode::DuplicateCharge,
            'ref5-refund-b00000000000',
            'refund_partial',
        );
        $failed = $this->refunds()->markFailed(
            $refund,
            $sa,
            'provider_declined',
            'ref5-fail-b00000000000',
            'refund_failed',
        );
        self::assertSame(PaymentRefundStatus::Failed, $failed->getStatus());
        self::assertSame('provider_declined', $failed->getFailureCode());
        self::assertSame(0, $this->refunds()->reservedAmount($this->reloadAttempt($attempt))->getAmountMinor());

        $this->expectCommerceFailure(
            CommerceFailureReason::InvalidTransition,
            function () use ($failed, $sa): void {
                $this->refunds()->markSucceeded(
                    $failed,
                    $sa,
                    'ref5-settle-b00000000000',
                    'refund_settled',
                );
            },
        );
    }

    public function testRefundRequiresACapturedPaymentAndASuperAdminOperator(): void
    {
        [$order, , $buyer] = $this->draftOrder('ref6');
        $sa = $this->scenario->superAdmin('ref6-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, 'ref6-key-000000000000000');

        $this->expectCommerceFailure(
            CommerceFailureReason::PaymentNotCaptured,
            function () use ($attempt, $sa): void {
                $this->refunds()->request(
                    $this->reloadAttempt($attempt),
                    $sa,
                    Money::fromMinor(100, 'TRY'),
                    PaymentRefundReasonCode::PurchaserRequested,
                    'ref6-refund-a00000000000',
                    'refund_partial',
                );
            },
        );

        $captured = $this->captureAttempt($attempt, $sa, 'ref6');
        $this->expectCommerceFailure(
            CommerceFailureReason::Unauthorized,
            function () use ($captured, $buyer): void {
                $this->refunds()->request(
                    $this->reloadAttempt($captured),
                    $buyer,
                    Money::fromMinor(100, 'TRY'),
                    PaymentRefundReasonCode::PurchaserRequested,
                    'ref6-refund-b00000000000',
                    'refund_partial',
                );
            },
        );
    }

    /**
     * @return array{0: CommerceOrder, 1: CommercialOffer, 2: User}
     */
    private function draftOrder(string $suffix, int $price = 19999, int $taxRateBasisPoints = 2000): array
    {
        $sa = $this->scenario->superAdmin($suffix.'-sa@example.com');
        $version = $this->scenario->activeVersion($sa, $suffix);
        $offer = $this->scenario->activeOffer($sa, $version, $suffix.'_code', $price, $taxRateBasisPoints);
        $buyer = $this->scenario->activeUser($suffix.'-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        return [$order, $offer, $buyer];
    }

    /**
     * @return array{0: PaymentAttempt, 1: User} a captured attempt and the settlement operator
     */
    private function capturedAttempt(string $suffix, int $price, int $taxRateBasisPoints): array
    {
        [$order, , $buyer] = $this->draftOrder($suffix, $price, $taxRateBasisPoints);
        $sa = $this->scenario->superAdmin($suffix.'-settle@example.com');
        $attempt = $this->startAttempt($order, $buyer, $suffix.'-key-000000000000000');

        return [$this->captureAttempt($attempt, $sa, $suffix), $sa];
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

    private function refunds(): PaymentRefundManager
    {
        return $this->scenario->service(PaymentRefundManager::class);
    }

    private function events(): PaymentEventRepository
    {
        return $this->scenario->service(PaymentEventRepository::class);
    }
}
