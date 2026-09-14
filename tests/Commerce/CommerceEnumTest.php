<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Enum\AccessPackageTargetType;
use App\Enum\CommerceCancellationReasonCode;
use App\Enum\CommerceFulfillmentStatus;
use App\Enum\CommerceOrderStatus;
use App\Enum\CommercePurchaserType;
use App\Enum\CommerceSubscriberType;
use App\Enum\CommerceSubscriptionStatus;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\CommercialOfferStatus;
use App\Enum\CommercialOfferTargetType;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentRefundReasonCode;
use App\Enum\PaymentRefundStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommerceEnumTest extends TestCase
{
    /**
     * @return list<array{0: class-string<\UnitEnum>}>
     */
    public static function commerceEnumProvider(): array
    {
        return [
            [CommercialOfferTargetType::class],
            [CommercialOfferBillingType::class],
            [CommercialOfferBillingInterval::class],
            [CommercialOfferStatus::class],
            [CommercePurchaserType::class],
            [CommerceOrderStatus::class],
            [PaymentAttemptStatus::class],
            [PaymentEventType::class],
            [PaymentProviderEnvironment::class],
            [CommerceSubscriptionStatus::class],
            [CommerceSubscriberType::class],
            [CommerceFulfillmentStatus::class],
            [PaymentRefundStatus::class],
            [CommerceCancellationReasonCode::class],
            [PaymentRefundReasonCode::class],
        ];
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    #[DataProvider('commerceEnumProvider')]
    public function testEnumsAreStringBackedWithSnakeCaseUniqueValues(string $enumClass): void
    {
        $reflection = new \ReflectionEnum($enumClass);
        self::assertTrue($reflection->isBacked());
        $backing = $reflection->getBackingType();
        self::assertInstanceOf(\ReflectionNamedType::class, $backing);
        self::assertSame('string', $backing->getName());

        $values = [];
        foreach ($reflection->getCases() as $case) {
            $value = $case->getBackingValue();
            self::assertIsString($value);
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $value);
            $values[] = $value;
        }
        self::assertNotSame([], $values);
        self::assertSame(\count($values), \count(array_unique($values)));
    }

    public function testOfferTargetTypeValuesAndMapping(): void
    {
        self::assertSame('individual', CommercialOfferTargetType::Individual->value);
        self::assertSame('institution', CommercialOfferTargetType::Institution->value);
        self::assertTrue(
            CommercialOfferTargetType::Individual->matchesPackageTarget(AccessPackageTargetType::Individual),
        );
        self::assertFalse(
            CommercialOfferTargetType::Individual->matchesPackageTarget(AccessPackageTargetType::Institution),
        );
        self::assertTrue(
            CommercialOfferTargetType::Institution->matchesPackageTarget(AccessPackageTargetType::Institution),
        );
        self::assertSame(
            CommercePurchaserType::User,
            CommercialOfferTargetType::Individual->toPurchaserType(),
        );
        self::assertSame(
            CommercePurchaserType::Institution,
            CommercialOfferTargetType::Institution->toPurchaserType(),
        );
    }

    public function testBillingTypeRequiresIntervalOnlyForRecurring(): void
    {
        self::assertSame('one_time', CommercialOfferBillingType::OneTime->value);
        self::assertSame('recurring', CommercialOfferBillingType::Recurring->value);
        self::assertFalse(CommercialOfferBillingType::OneTime->requiresBillingInterval());
        self::assertTrue(CommercialOfferBillingType::Recurring->requiresBillingInterval());
    }

    public function testBillingIntervalMapsToDateInterval(): void
    {
        self::assertSame('monthly', CommercialOfferBillingInterval::Monthly->value);
        self::assertSame('yearly', CommercialOfferBillingInterval::Yearly->value);

        self::assertSame('P1M', $this->intervalSpec(CommercialOfferBillingInterval::Monthly->toDateInterval()));
        self::assertSame('P1Y', $this->intervalSpec(CommercialOfferBillingInterval::Yearly->toDateInterval()));
    }

    public function testAdvanceClampsToTheLastDayOfTheTargetMonth(): void
    {
        $utc = new \DateTimeZone('UTC');

        // Plain P1M addition would overflow 2026-01-31 into 2026-03-03 and skip February.
        self::assertSame(
            '2026-02-28 09:30:00',
            CommercialOfferBillingInterval::Monthly
                ->advance(new \DateTimeImmutable('2026-01-31 09:30:00', $utc))
                ->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2028-02-29 00:00:00',
            CommercialOfferBillingInterval::Monthly
                ->advance(new \DateTimeImmutable('2028-01-30 00:00:00', $utc))
                ->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-11-30 00:00:00',
            CommercialOfferBillingInterval::Monthly
                ->advance(new \DateTimeImmutable('2026-10-31 00:00:00', $utc))
                ->format('Y-m-d H:i:s'),
        );
    }

    public function testAdvanceKeepsOrdinaryDatesUntouched(): void
    {
        $utc = new \DateTimeZone('UTC');
        self::assertSame(
            '2026-10-13 12:00:00',
            CommercialOfferBillingInterval::Monthly
                ->advance(new \DateTimeImmutable('2026-09-13 12:00:00', $utc))
                ->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2027-09-13 12:00:00',
            CommercialOfferBillingInterval::Yearly
                ->advance(new \DateTimeImmutable('2026-09-13 12:00:00', $utc))
                ->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2025-02-28 00:00:00',
            CommercialOfferBillingInterval::Yearly
                ->advance(new \DateTimeImmutable('2024-02-29 00:00:00', $utc))
                ->format('Y-m-d H:i:s'),
            'A leap day start must land on the last February day of a non-leap year.',
        );
    }

    public function testAdvanceIsStrictlyIncreasingAcrossAYearOfPeriods(): void
    {
        $period = new \DateTimeImmutable('2026-01-31 00:00:00', new \DateTimeZone('UTC'));
        for ($i = 0; $i < 12; ++$i) {
            $next = CommercialOfferBillingInterval::Monthly->advance($period);
            self::assertGreaterThan($period, $next);
            $period = $next;
        }
        self::assertSame('2027-01-28', $period->format('Y-m-d'));
    }

    private function intervalSpec(\DateInterval $interval): string
    {
        return \sprintf('P%d%s', $interval->y > 0 ? $interval->y : $interval->m, $interval->y > 0 ? 'Y' : 'M');
    }

    public function testOfferStatusTransitionPredicates(): void
    {
        self::assertSame(['draft', 'active', 'retired'], array_map(
            static fn (CommercialOfferStatus $status): string => $status->value,
            CommercialOfferStatus::cases(),
        ));

        self::assertTrue(CommercialOfferStatus::Draft->allowsDraftMutation());
        self::assertFalse(CommercialOfferStatus::Active->allowsDraftMutation());
        self::assertFalse(CommercialOfferStatus::Retired->allowsDraftMutation());

        self::assertTrue(CommercialOfferStatus::Draft->allowsActivation());
        self::assertFalse(CommercialOfferStatus::Active->allowsActivation());
        self::assertFalse(CommercialOfferStatus::Retired->allowsActivation());

        self::assertFalse(CommercialOfferStatus::Retired->allowsRetirement());
        self::assertTrue(CommercialOfferStatus::Active->allowsRetirement());

        self::assertTrue(CommercialOfferStatus::Active->allowsNewOrder());
        self::assertFalse(CommercialOfferStatus::Draft->allowsNewOrder());
        self::assertFalse(CommercialOfferStatus::Retired->allowsNewOrder());
    }

    public function testOrderStatusPredicates(): void
    {
        self::assertTrue(CommerceOrderStatus::Paid->isTerminal());
        self::assertTrue(CommerceOrderStatus::Cancelled->isTerminal());
        self::assertTrue(CommerceOrderStatus::Expired->isTerminal());
        self::assertFalse(CommerceOrderStatus::Draft->isTerminal());
        self::assertFalse(CommerceOrderStatus::AwaitingPayment->isTerminal());
        self::assertFalse(CommerceOrderStatus::Failed->isTerminal());

        self::assertTrue(CommerceOrderStatus::Draft->allowsPaymentAttempt());
        self::assertTrue(CommerceOrderStatus::AwaitingPayment->allowsPaymentAttempt());
        self::assertTrue(CommerceOrderStatus::Failed->allowsPaymentAttempt());
        self::assertFalse(CommerceOrderStatus::Paid->allowsPaymentAttempt());
        self::assertFalse(CommerceOrderStatus::Cancelled->allowsPaymentAttempt());
        self::assertFalse(CommerceOrderStatus::Expired->allowsPaymentAttempt());

        self::assertTrue(CommerceOrderStatus::Draft->allowsCancellation());
        self::assertTrue(CommerceOrderStatus::AwaitingPayment->allowsCancellation());
        self::assertFalse(CommerceOrderStatus::Paid->allowsCancellation());
    }

    public function testPaymentAttemptStatusPredicates(): void
    {
        self::assertTrue(PaymentAttemptStatus::Captured->isTerminal());
        self::assertTrue(PaymentAttemptStatus::Failed->isTerminal());
        self::assertTrue(PaymentAttemptStatus::Cancelled->isTerminal());
        self::assertFalse(PaymentAttemptStatus::Initiated->isTerminal());
        self::assertFalse(PaymentAttemptStatus::Authorized->isTerminal());

        self::assertTrue(PaymentAttemptStatus::Initiated->allowsCapture());
        self::assertTrue(PaymentAttemptStatus::Authorized->allowsCapture());
        self::assertFalse(PaymentAttemptStatus::Captured->allowsCapture());
        self::assertFalse(PaymentAttemptStatus::Failed->allowsCapture());
    }

    public function testPaymentEventTypeAmountRequirementAndAttemptMapping(): void
    {
        self::assertTrue(PaymentEventType::Authorized->requiresAmount());
        self::assertTrue(PaymentEventType::Captured->requiresAmount());
        self::assertFalse(PaymentEventType::Failed->requiresAmount());
        self::assertFalse(PaymentEventType::Cancelled->requiresAmount());

        self::assertSame(PaymentAttemptStatus::Authorized, PaymentEventType::Authorized->toAttemptStatus());
        self::assertSame(PaymentAttemptStatus::Captured, PaymentEventType::Captured->toAttemptStatus());
        self::assertSame(PaymentAttemptStatus::Failed, PaymentEventType::Failed->toAttemptStatus());
        self::assertSame(PaymentAttemptStatus::Cancelled, PaymentEventType::Cancelled->toAttemptStatus());
        self::assertNull(PaymentEventType::RefundRequested->toAttemptStatus());
        self::assertNull(PaymentEventType::RefundSucceeded->toAttemptStatus());
        self::assertNull(PaymentEventType::RefundFailed->toAttemptStatus());
    }

    public function testSubscriptionStatusPredicates(): void
    {
        self::assertFalse(CommerceSubscriptionStatus::Pending->isTerminal());
        self::assertFalse(CommerceSubscriptionStatus::Active->isTerminal());
        self::assertFalse(CommerceSubscriptionStatus::PastDue->isTerminal());
        self::assertTrue(CommerceSubscriptionStatus::Cancelled->isTerminal());
        self::assertTrue(CommerceSubscriptionStatus::Expired->isTerminal());

        self::assertTrue(CommerceSubscriptionStatus::Active->allowsRenewal());
        self::assertTrue(CommerceSubscriptionStatus::PastDue->allowsRenewal());
        self::assertFalse(CommerceSubscriptionStatus::Cancelled->allowsRenewal());
        self::assertFalse(CommerceSubscriptionStatus::Expired->allowsRenewal());
    }

    public function testFulfillmentStatusPredicates(): void
    {
        self::assertTrue(CommerceFulfillmentStatus::Completed->requiresLicense());
        self::assertTrue(CommerceFulfillmentStatus::Reversed->requiresLicense());
        self::assertFalse(CommerceFulfillmentStatus::Pending->requiresLicense());
        self::assertFalse(CommerceFulfillmentStatus::Failed->requiresLicense());

        self::assertTrue(CommerceFulfillmentStatus::Completed->allowsReversal());
        self::assertFalse(CommerceFulfillmentStatus::Reversed->allowsReversal());
        self::assertFalse(CommerceFulfillmentStatus::Pending->allowsReversal());
    }

    public function testRefundStatusPredicates(): void
    {
        self::assertFalse(PaymentRefundStatus::Requested->isTerminal());
        self::assertTrue(PaymentRefundStatus::Succeeded->isTerminal());
        self::assertTrue(PaymentRefundStatus::Failed->isTerminal());
    }

    public function testProviderEnvironmentsAreSandboxAndProduction(): void
    {
        self::assertSame(['sandbox', 'production'], array_map(
            static fn (PaymentProviderEnvironment $environment): string => $environment->value,
            PaymentProviderEnvironment::cases(),
        ));
    }

    public function testPurchaserAndSubscriberTypesStayAligned(): void
    {
        self::assertSame(
            array_map(
                static fn (CommercePurchaserType $type): string => $type->value,
                CommercePurchaserType::cases(),
            ),
            array_map(
                static fn (CommerceSubscriberType $type): string => $type->value,
                CommerceSubscriberType::cases(),
            ),
        );
    }

    public function testReasonCodeVocabulariesAreControlled(): void
    {
        self::assertGreaterThanOrEqual(3, \count(CommerceCancellationReasonCode::cases()));
        self::assertGreaterThanOrEqual(3, \count(PaymentRefundReasonCode::cases()));
        self::assertInstanceOf(
            CommerceCancellationReasonCode::class,
            CommerceCancellationReasonCode::tryFrom('purchaser_requested'),
        );
        $unknown = self::unknownReasonCode();
        self::assertNull(CommerceCancellationReasonCode::tryFrom($unknown));
        self::assertNull(PaymentRefundReasonCode::tryFrom($unknown));
    }

    private static function unknownReasonCode(): string
    {
        return 'because_i_said_so';
    }
}
