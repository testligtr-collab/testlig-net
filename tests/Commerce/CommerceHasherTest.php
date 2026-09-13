<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Commerce\CommerceCanonicalInstant;
use App\Commerce\CommerceIdempotencyKeyHasher;
use App\Commerce\CommerceOrderHasher;
use App\Commerce\CommerceSubscriptionHasher;
use App\Commerce\CommercialOfferHasher;
use App\Commerce\PaymentEventHasher;
use App\Enum\CommerceFailureReason;
use App\Enum\CommercePurchaserType;
use App\Enum\CommerceSubscriberType;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\CommercialOfferTargetType;
use App\Enum\PaymentEventType;
use App\Exception\CommerceException;
use App\Question\Content\QuestionContentCanonicalEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class CommerceHasherTest extends TestCase
{
    private const KEY = 'test_commerce_idempotency_hash_key_not_for_production';

    private CommercialOfferHasher $offerHasher;
    private CommerceOrderHasher $orderHasher;
    private PaymentEventHasher $eventHasher;
    private CommerceSubscriptionHasher $subscriptionHasher;
    private Uuid $offerId;
    private Uuid $packageId;
    private Uuid $versionId;
    private Uuid $orderId;
    private Uuid $userId;
    private Uuid $attemptId;
    private Uuid $subscriptionId;

    protected function setUp(): void
    {
        $encoder = new QuestionContentCanonicalEncoder();
        $this->offerHasher = new CommercialOfferHasher($encoder);
        $this->orderHasher = new CommerceOrderHasher($encoder);
        $this->eventHasher = new PaymentEventHasher($encoder);
        $this->subscriptionHasher = new CommerceSubscriptionHasher($encoder);
        $this->offerId = new UuidV7();
        $this->packageId = new UuidV7();
        $this->versionId = new UuidV7();
        $this->orderId = new UuidV7();
        $this->userId = new UuidV7();
        $this->attemptId = new UuidV7();
        $this->subscriptionId = new UuidV7();
    }

    public function testOfferHashIsDeterministicLowercaseHexAndVerifies(): void
    {
        $first = $this->offerHash(19999);
        $second = $this->offerHash(19999);
        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->verifyOffer($first, 19999);
    }

    public function testOfferHashChangesWithPrice(): void
    {
        self::assertNotSame($this->offerHash(19999), $this->offerHash(20000));
    }

    public function testOfferHashMismatchIsRejected(): void
    {
        $this->expectFailure(CommerceFailureReason::HashMismatch, function (): void {
            $this->verifyOffer($this->offerHash(19999), 20000);
        });
    }

    /**
     * @return list<array{0: string}>
     */
    public static function malformedHashProvider(): array
    {
        return [
            [''],
            ['not-a-hash'],
            [str_repeat('A', 64)],
            [str_repeat('a', 63)],
            [str_repeat('a', 65)],
            [str_repeat('g', 64)],
        ];
    }

    #[DataProvider('malformedHashProvider')]
    public function testOfferVerifyRejectsMalformedHash(string $storedHash): void
    {
        $this->expectFailure(CommerceFailureReason::HashMismatch, function () use ($storedHash): void {
            $this->verifyOffer($storedHash, 19999);
        });
    }

    #[DataProvider('malformedHashProvider')]
    public function testOfferAssertHashRejectsMalformedHash(string $hash): void
    {
        $this->expectFailure(CommerceFailureReason::InvalidInput, static function () use ($hash): void {
            CommercialOfferHasher::assertHash($hash);
        });
    }

    public function testOfferHashCoversBillingIntervalAndTargetType(): void
    {
        $oneTime = $this->offerHash(19999, CommercialOfferBillingType::OneTime, null);
        $monthly = $this->offerHash(19999, CommercialOfferBillingType::Recurring, CommercialOfferBillingInterval::Monthly);
        $yearly = $this->offerHash(19999, CommercialOfferBillingType::Recurring, CommercialOfferBillingInterval::Yearly);
        self::assertNotSame($oneTime, $monthly);
        self::assertNotSame($monthly, $yearly);

        $institution = $this->offerHasher->hash(
            $this->offerId,
            'offer_code',
            $this->packageId,
            $this->versionId,
            CommercialOfferTargetType::Institution,
            CommercialOfferBillingType::OneTime,
            null,
            19999,
            'TRY',
            2000,
            null,
            null,
        );
        self::assertNotSame($oneTime, $institution);
    }

    public function testOfferHashCoversValidityWindowAndSchemaVersion(): void
    {
        $withWindow = $this->offerHasher->hash(
            $this->offerId,
            'offer_code',
            $this->packageId,
            $this->versionId,
            CommercialOfferTargetType::Individual,
            CommercialOfferBillingType::OneTime,
            null,
            19999,
            'TRY',
            2000,
            new \DateTimeImmutable('2026-09-13 00:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-12-13 00:00:00', new \DateTimeZone('UTC')),
        );
        self::assertNotSame($this->offerHash(19999), $withWindow);

        $otherSchema = $this->offerHasher->hash(
            $this->offerId,
            'offer_code',
            $this->packageId,
            $this->versionId,
            CommercialOfferTargetType::Individual,
            CommercialOfferBillingType::OneTime,
            null,
            19999,
            'TRY',
            2000,
            null,
            null,
            2,
        );
        self::assertNotSame($this->offerHash(19999), $otherSchema);
    }

    public function testOfferHashTreatsEquivalentInstantsInAnyTimezoneAsEqual(): void
    {
        $utc = new \DateTimeImmutable('2026-09-13 09:00:00', new \DateTimeZone('UTC'));
        $istanbul = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('Europe/Istanbul'));
        self::assertSame(
            CommerceCanonicalInstant::format($utc),
            CommerceCanonicalInstant::format($istanbul),
        );
        self::assertNull(CommerceCanonicalInstant::format(null));
    }

    public function testOrderHashCoversTotalsAndLineSnapshots(): void
    {
        $items = [$this->orderItemPayload()];
        $base = $this->orderHash($items, 10000, 0, 2000, 12000);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $base);
        $this->orderHasher->verify(
            $base,
            $this->orderId,
            CommercePurchaserType::User,
            $this->userId,
            null,
            'TRY',
            10000,
            0,
            2000,
            12000,
            $items,
        );

        self::assertNotSame($base, $this->orderHash($items, 10000, 500, 2000, 11500));

        $tampered = $items;
        $tampered[0]['quantity'] = 2;
        self::assertNotSame($base, $this->orderHash($tampered, 10000, 0, 2000, 12000));

        $rehashed = $items;
        $rehashed[0]['offerSnapshotHash'] = str_repeat('b', 64);
        self::assertNotSame($base, $this->orderHash($rehashed, 10000, 0, 2000, 12000));
    }

    public function testOrderHashIsStableRegardlessOfItemOrder(): void
    {
        $first = $this->orderItemPayload();
        $second = $this->orderItemPayload();
        $second['offerId'] = (new UuidV7())->toRfc4122();

        $forward = $this->orderHash([$first, $second], 20000, 0, 4000, 24000);
        $reverse = $this->orderHash([$second, $first], 20000, 0, 4000, 24000);
        self::assertSame($forward, $reverse, 'Line ordering must not change the order hash.');
    }

    public function testOrderHashDistinguishesPurchaserIdentity(): void
    {
        $items = [$this->orderItemPayload()];
        $userOrder = $this->orderHash($items, 10000, 0, 2000, 12000);
        $institutionOrder = $this->orderHasher->hash(
            $this->orderId,
            CommercePurchaserType::Institution,
            null,
            new UuidV7(),
            'TRY',
            10000,
            0,
            2000,
            12000,
            $items,
        );
        self::assertNotSame($userOrder, $institutionOrder);
    }

    public function testOrderVerifyRejectsTamperedTotals(): void
    {
        $items = [$this->orderItemPayload()];
        $hash = $this->orderHash($items, 10000, 0, 2000, 12000);
        $this->expectFailure(CommerceFailureReason::HashMismatch, function () use ($hash, $items): void {
            $this->orderHasher->verify(
                $hash,
                $this->orderId,
                CommercePurchaserType::User,
                $this->userId,
                null,
                'TRY',
                10000,
                0,
                2000,
                999999,
                $items,
            );
        });
    }

    public function testPaymentEventHashChainsPreviousHash(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-09-13 10:00:00', new \DateTimeZone('UTC'));
        $first = $this->eventHasher->hash(
            $this->attemptId,
            1,
            PaymentEventType::Authorized,
            'prov-ref-1',
            $occurredAt,
            12000,
            'TRY',
            ['provider_code' => 'sandbox_manual'],
            null,
        );
        $second = $this->eventHasher->hash(
            $this->attemptId,
            2,
            PaymentEventType::Captured,
            'prov-ref-2',
            $occurredAt,
            12000,
            'TRY',
            ['provider_code' => 'sandbox_manual'],
            $first,
        );
        $forked = $this->eventHasher->hash(
            $this->attemptId,
            2,
            PaymentEventType::Captured,
            'prov-ref-2',
            $occurredAt,
            12000,
            'TRY',
            ['provider_code' => 'sandbox_manual'],
            null,
        );
        self::assertNotSame($second, $forked, 'A broken chain must produce a different hash.');

        $this->eventHasher->verify(
            $second,
            $this->attemptId,
            2,
            PaymentEventType::Captured,
            'prov-ref-2',
            $occurredAt,
            12000,
            'TRY',
            ['provider_code' => 'sandbox_manual'],
            $first,
        );

        $this->expectFailure(CommerceFailureReason::HashMismatch, function () use ($second, $occurredAt, $first): void {
            $this->eventHasher->verify(
                $second,
                $this->attemptId,
                3,
                PaymentEventType::Captured,
                'prov-ref-2',
                $occurredAt,
                12000,
                'TRY',
                ['provider_code' => 'sandbox_manual'],
                $first,
            );
        });
    }

    public function testPaymentEventHashCoversMetadataAndAmount(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-09-13 10:00:00', new \DateTimeZone('UTC'));
        $base = $this->eventHasher->hash(
            $this->attemptId,
            1,
            PaymentEventType::Captured,
            null,
            $occurredAt,
            12000,
            'TRY',
            [],
            null,
        );
        $withMetadata = $this->eventHasher->hash(
            $this->attemptId,
            1,
            PaymentEventType::Captured,
            null,
            $occurredAt,
            12000,
            'TRY',
            ['failure_code' => 'none'],
            null,
        );
        $withoutAmount = $this->eventHasher->hash(
            $this->attemptId,
            1,
            PaymentEventType::Captured,
            null,
            $occurredAt,
            null,
            null,
            [],
            null,
        );
        self::assertNotSame($base, $withMetadata);
        self::assertNotSame($base, $withoutAmount);
    }

    public function testSubscriptionHashCoversPeriodAndSubscriber(): void
    {
        $start = new \DateTimeImmutable('2026-09-13 00:00:00', new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable('2026-10-13 00:00:00', new \DateTimeZone('UTC'));
        $base = $this->subscriptionHash($start, $end);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $base);
        $this->subscriptionHasher->verify(
            $base,
            $this->subscriptionId,
            CommerceSubscriberType::User,
            $this->userId,
            null,
            $this->offerId,
            $this->packageId,
            $this->versionId,
            CommercialOfferBillingInterval::Monthly,
            $start,
            $end,
        );

        $nextPeriod = $this->subscriptionHash(
            $end,
            new \DateTimeImmutable('2026-11-13 00:00:00', new \DateTimeZone('UTC')),
        );
        self::assertNotSame($base, $nextPeriod, 'Each period must hash differently.');

        $institution = $this->subscriptionHasher->hash(
            $this->subscriptionId,
            CommerceSubscriberType::Institution,
            null,
            new UuidV7(),
            $this->offerId,
            $this->packageId,
            $this->versionId,
            CommercialOfferBillingInterval::Monthly,
            $start,
            $end,
        );
        self::assertNotSame($base, $institution);
    }

    public function testSubscriptionVerifyRejectsTamperedPeriod(): void
    {
        $start = new \DateTimeImmutable('2026-09-13 00:00:00', new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable('2026-10-13 00:00:00', new \DateTimeZone('UTC'));
        $hash = $this->subscriptionHash($start, $end);
        $this->expectFailure(CommerceFailureReason::HashMismatch, function () use ($hash, $start): void {
            $this->subscriptionHasher->verify(
                $hash,
                $this->subscriptionId,
                CommerceSubscriberType::User,
                $this->userId,
                null,
                $this->offerId,
                $this->packageId,
                $this->versionId,
                CommercialOfferBillingInterval::Yearly,
                $start,
                new \DateTimeImmutable('2027-09-13 00:00:00', new \DateTimeZone('UTC')),
            );
        });
    }

    public function testIdempotencyHashIsHmacKeyedAndDeterministic(): void
    {
        $hasher = $this->idempotencyHasher();
        $first = $hasher->hash('payment_attempt', 'order-1-attempt-1');
        self::assertSame($first, $hasher->hash('payment_attempt', 'order-1-attempt-1'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertSame(
            hash_hmac('sha256', 'payment_attempt:order-1-attempt-1', self::KEY),
            $first,
        );
        $hasher->verify($first, 'payment_attempt', 'order-1-attempt-1');
    }

    public function testIdempotencyHashIsScopeSeparated(): void
    {
        $hasher = $this->idempotencyHasher();
        self::assertNotSame(
            $hasher->hash('payment_attempt', 'same-key-value'),
            $hasher->hash('payment_refund', 'same-key-value'),
        );
    }

    public function testIdempotencyHashDependsOnKeyMaterial(): void
    {
        $other = new CommerceIdempotencyKeyHasher('another_test_key_that_is_long_enough_here', 'test');
        self::assertNotSame(
            $this->idempotencyHasher()->hash('payment_attempt', 'order-1-attempt-1'),
            $other->hash('payment_attempt', 'order-1-attempt-1'),
        );
    }

    public function testScopedIdempotencyHashFansOutPerDiscriminator(): void
    {
        $hasher = $this->idempotencyHasher();
        $first = $hasher->hashScoped('commerce_fulfillment', 'settle-order-1', 'oi:'.$this->orderId->toRfc4122());
        $second = $hasher->hashScoped('commerce_fulfillment', 'settle-order-1', 'oi:'.$this->offerId->toRfc4122());
        self::assertNotSame($first, $second);
        self::assertNotSame($hasher->hash('commerce_fulfillment', 'settle-order-1'), $first);
        $hasher->verifyScoped($first, 'commerce_fulfillment', 'settle-order-1', 'oi:'.$this->orderId->toRfc4122());
    }

    public function testIdempotencyVerifyRejectsDifferentKey(): void
    {
        $hasher = $this->idempotencyHasher();
        $hash = $hasher->hash('payment_attempt', 'order-1-attempt-1');
        $this->expectFailure(CommerceFailureReason::IdempotencyConflict, static function () use ($hasher, $hash): void {
            $hasher->verify($hash, 'payment_attempt', 'order-1-attempt-2');
        });
        $this->expectFailure(
            CommerceFailureReason::IdempotencyConflict,
            static function () use ($hasher, $hash): void {
                $hasher->verifyScoped($hash, 'commerce_fulfillment', 'order-1-attempt-1', 'oi:1');
            },
        );
    }

    public function testShortKeyMaterialIsRejected(): void
    {
        $hasher = new CommerceIdempotencyKeyHasher('too-short-key', 'test');
        $this->expectFailure(
            CommerceFailureReason::IdempotencyMisconfigured,
            static function () use ($hasher): void {
                $hasher->hash('payment_attempt', 'order-1-attempt-1');
            },
        );
    }

    public function testProductionRejectsPlaceholderKeyMaterial(): void
    {
        foreach ([
            'change-me-commerce-idempotency-hash-key-here',
            'change_me_dev_commerce_idempotency_hash_not_for_production',
            'placeholder_key_material_that_is_long_enough',
            'test_commerce_idempotency_hash_key_not_for_production',
            'ci_commerce_idempotency_hash_key_not_for_production',
            'example-commerce-idempotency-hash-key-value',
        ] as $keyMaterial) {
            $hasher = new CommerceIdempotencyKeyHasher($keyMaterial, 'prod');
            $this->expectFailure(
                CommerceFailureReason::IdempotencyMisconfigured,
                static function () use ($hasher): void {
                    $hasher->hash('payment_attempt', 'order-1-attempt-1');
                },
            );
        }
    }

    public function testProductionAcceptsStrongKeyMaterial(): void
    {
        $hasher = new CommerceIdempotencyKeyHasher(str_repeat('9f3c', 16), 'prod');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $hasher->hash('payment_attempt', 'order-1-attempt-1'),
        );
    }

    public function testKeyMaterialComesOnlyFromTheCommerceEnvVariable(): void
    {
        $constructor = (new \ReflectionClass(CommerceIdempotencyKeyHasher::class))->getConstructor();
        self::assertInstanceOf(\ReflectionMethod::class, $constructor);
        $expressions = [];
        foreach ($constructor->getParameters() as $parameter) {
            foreach ($parameter->getAttributes(Autowire::class) as $attribute) {
                $arguments = $attribute->getArguments();
                self::assertArrayHasKey(0, $arguments);
                self::assertIsString($arguments[0]);
                $expressions[] = $arguments[0];
            }
        }
        self::assertContains('%env(COMMERCE_IDEMPOTENCY_HASH_KEY)%', $expressions);
        foreach ($expressions as $expression) {
            self::assertStringNotContainsString('APP_SECRET', $expression);
            self::assertStringNotContainsString('AUDIT_HASH_KEY', $expression);
            self::assertStringNotContainsString('kernel.secret', $expression);
        }
    }

    public function testHasherNeverReadsEnvironmentDirectly(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(CommerceIdempotencyKeyHasher::class))->getFileName(),
        );
        foreach (['getenv(', '$_ENV', '$_SERVER'] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidIdempotencyKeyProvider(): array
    {
        return [
            [''],
            ['short'],
            [str_repeat('a', CommerceIdempotencyKeyHasher::MAX_IDEMPOTENCY_KEY_LENGTH + 1)],
            ['user@example.com'],
            ['has space here'],
            ['-leading-dash'],
            ['key/with/slash'],
            ['key#with#hash'],
        ];
    }

    #[DataProvider('invalidIdempotencyKeyProvider')]
    public function testInvalidIdempotencyKeysAreRejected(string $idempotencyKey): void
    {
        $hasher = $this->idempotencyHasher();
        $this->expectFailure(
            CommerceFailureReason::InvalidInput,
            static function () use ($hasher, $idempotencyKey): void {
                $hasher->hash('payment_attempt', $idempotencyKey);
            },
        );
    }

    public function testIdempotencyKeyIsTrimmedNotAltered(): void
    {
        $hasher = $this->idempotencyHasher();
        self::assertSame(
            $hasher->hash('payment_attempt', 'order-1-attempt-1'),
            $hasher->hash('payment_attempt', '  order-1-attempt-1  '),
        );
        self::assertSame(
            'order-1-attempt-1',
            CommerceIdempotencyKeyHasher::normalizeIdempotencyKey(' order-1-attempt-1 '),
        );
    }

    public function testInvalidScopeIsRejected(): void
    {
        $hasher = $this->idempotencyHasher();
        foreach (['', 'A', 'Payment-Attempt', '1payment', 'payment attempt'] as $scope) {
            $this->expectFailure(
                CommerceFailureReason::InvalidInput,
                static function () use ($hasher, $scope): void {
                    $hasher->hash($scope, 'order-1-attempt-1');
                },
            );
        }
    }

    public function testScopeIsCaseInsensitiveAfterNormalization(): void
    {
        $hasher = $this->idempotencyHasher();
        self::assertSame(
            $hasher->hash('payment_attempt', 'order-1-attempt-1'),
            $hasher->hash(' PAYMENT_ATTEMPT ', 'order-1-attempt-1'),
        );
    }

    public function testInvalidDiscriminatorIsRejected(): void
    {
        $hasher = $this->idempotencyHasher();
        $this->expectFailure(
            CommerceFailureReason::InvalidInput,
            static function () use ($hasher): void {
                $hasher->hashScoped('commerce_fulfillment', 'settle-order-1', 'user@example.com');
            },
        );
    }

    private function idempotencyHasher(): CommerceIdempotencyKeyHasher
    {
        return new CommerceIdempotencyKeyHasher(self::KEY, 'test');
    }

    private function offerHash(
        int $priceAmountMinor,
        CommercialOfferBillingType $billingType = CommercialOfferBillingType::OneTime,
        ?CommercialOfferBillingInterval $billingInterval = null,
    ): string {
        return $this->offerHasher->hash(
            $this->offerId,
            'offer_code',
            $this->packageId,
            $this->versionId,
            CommercialOfferTargetType::Individual,
            $billingType,
            $billingInterval,
            $priceAmountMinor,
            'TRY',
            2000,
            null,
            null,
        );
    }

    private function verifyOffer(string $storedHash, int $priceAmountMinor): void
    {
        $this->offerHasher->verify(
            $storedHash,
            $this->offerId,
            'offer_code',
            $this->packageId,
            $this->versionId,
            CommercialOfferTargetType::Individual,
            CommercialOfferBillingType::OneTime,
            null,
            $priceAmountMinor,
            'TRY',
            2000,
            null,
            null,
        );
    }

    /**
     * @return array{
     *     offerId: string,
     *     packageId: string,
     *     packageVersionId: string,
     *     quantity: int,
     *     unitPriceAmountMinor: int,
     *     taxRateBasisPoints: int,
     *     lineSubtotalAmountMinor: int,
     *     lineTaxAmountMinor: int,
     *     lineTotalAmountMinor: int,
     *     offerSnapshotHash: string,
     *     packagePolicySnapshotHash: string
     * }
     */
    private function orderItemPayload(): array
    {
        return [
            'offerId' => $this->offerId->toRfc4122(),
            'packageId' => $this->packageId->toRfc4122(),
            'packageVersionId' => $this->versionId->toRfc4122(),
            'quantity' => 1,
            'unitPriceAmountMinor' => 10000,
            'taxRateBasisPoints' => 2000,
            'lineSubtotalAmountMinor' => 10000,
            'lineTaxAmountMinor' => 2000,
            'lineTotalAmountMinor' => 12000,
            'offerSnapshotHash' => str_repeat('a', 64),
            'packagePolicySnapshotHash' => str_repeat('c', 64),
        ];
    }

    /**
     * @param list<array{
     *     offerId: string,
     *     packageId: string,
     *     packageVersionId: string,
     *     quantity: int,
     *     unitPriceAmountMinor: int,
     *     taxRateBasisPoints: int,
     *     lineSubtotalAmountMinor: int,
     *     lineTaxAmountMinor: int,
     *     lineTotalAmountMinor: int,
     *     offerSnapshotHash: string,
     *     packagePolicySnapshotHash: string
     * }> $items
     */
    private function orderHash(
        array $items,
        int $subtotal,
        int $discount,
        int $tax,
        int $grandTotal,
    ): string {
        return $this->orderHasher->hash(
            $this->orderId,
            CommercePurchaserType::User,
            $this->userId,
            null,
            'TRY',
            $subtotal,
            $discount,
            $tax,
            $grandTotal,
            $items,
        );
    }

    private function subscriptionHash(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        return $this->subscriptionHasher->hash(
            $this->subscriptionId,
            CommerceSubscriberType::User,
            $this->userId,
            null,
            $this->offerId,
            $this->packageId,
            $this->versionId,
            CommercialOfferBillingInterval::Monthly,
            $start,
            $end,
        );
    }

    private function expectFailure(CommerceFailureReason $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected CommerceException '.$reason->value);
        } catch (CommerceException $e) {
            self::assertSame($reason, $e->getReason());
        }
    }
}
