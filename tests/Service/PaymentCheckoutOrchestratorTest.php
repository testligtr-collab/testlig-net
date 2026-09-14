<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Commerce\Sandbox\SandboxPaymentProviderAdapter;
use App\Entity\CommerceOrder;
use App\Entity\CommercialOffer;
use App\Entity\User;
use App\Enum\CommerceOrderStatus;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentCheckoutOutcome;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\SecurityAuditAction;
use App\Service\PaymentCheckoutOrchestrator;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Provider-neutral checkout orchestration: authorize, decline, ambiguity and idempotency.
 */
final class PaymentCheckoutOrchestratorTest extends KernelTestCase
{
    use CommerceTestFixtures;

    private SandboxPaymentProviderAdapter $sandboxAdapter;

    protected function setUp(): void
    {
        $this->bootCommerce();
        $sa = $this->scenario->superAdmin('checkout-sa@example.com');
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($sa);
        $adapter = static::getContainer()->get(SandboxPaymentProviderAdapter::class);
        self::assertInstanceOf(SandboxPaymentProviderAdapter::class, $adapter);
        $adapter->setMode(SandboxPaymentProviderAdapter::MODE_SUCCESS);
        $adapter->resetCalls();
        $this->sandboxAdapter = $adapter;
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        try {
            $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
            self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
            $override->setActor(null);
            $this->cleanupCommerce();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testSuccessfulAuthorizeCheckout(): void
    {
        [$order, , $buyer] = $this->draftOrder('co1', 10000, 2000);
        $key = 'co1-key-0000000000000000';

        $result = $this->checkout()->checkout(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $key,
        );

        self::assertSame(PaymentCheckoutOutcome::Accepted, $result->outcome);
        self::assertSame(PaymentEventType::Authorized, $result->providerEventType);
        self::assertSame(PaymentAttemptStatus::Authorized, $result->attempt->getStatus());
        self::assertSame('sandbox_provider', $result->attempt->getProviderCode());
        self::assertSame(12000, $result->attempt->getAmountMinor());
        self::assertNotNull($result->attempt->getProviderPaymentReference());
        self::assertSame(CommerceOrderStatus::AwaitingPayment, $result->attempt->getOrder()->getStatus());
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentCheckoutStarted->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentCheckoutProviderAccepted->value));
        self::assertSame(['authorize:'.$result->attempt->getId()->toRfc4122()], $this->sandboxAdapter->getCalls());
    }

    public function testDeclineReturnsRejectedOutcomeAndMarksAttemptFailed(): void
    {
        $this->sandboxAdapter->setMode(SandboxPaymentProviderAdapter::MODE_DECLINE);
        [$order, , $buyer] = $this->draftOrder('co2');

        $result = $this->checkout()->checkout(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            'co2-key-1111111111111111',
        );

        self::assertSame(PaymentCheckoutOutcome::Rejected, $result->outcome);
        self::assertSame(PaymentEventType::Failed, $result->providerEventType);
        self::assertSame('provider_declined', $result->reasonCode);
        self::assertSame(PaymentAttemptStatus::Failed, $result->attempt->getStatus());
        self::assertSame(CommerceOrderStatus::Failed, $result->attempt->getOrder()->getStatus());
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentCheckoutProviderRejected->value));
    }

    public function testAmbiguousModeReturnsAmbiguousAndLeavesAttemptInitiated(): void
    {
        $this->sandboxAdapter->setMode(SandboxPaymentProviderAdapter::MODE_AMBIGUOUS);
        [$order, , $buyer] = $this->draftOrder('co3');

        $result = $this->checkout()->checkout(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            'co3-key-2222222222222222',
        );

        self::assertSame(PaymentCheckoutOutcome::Ambiguous, $result->outcome);
        self::assertSame('provider_ambiguous', $result->reasonCode);
        self::assertNull($result->providerEventType);
        self::assertSame(PaymentAttemptStatus::Initiated, $result->attempt->getStatus());
        self::assertSame(CommerceOrderStatus::AwaitingPayment, $result->attempt->getOrder()->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentCheckoutProviderRejected->value));
    }

    public function testIdempotentReplayWithSameKeyDoesNotDoubleCharge(): void
    {
        [$order, , $buyer] = $this->draftOrder('co4', 10000, 2000);
        $key = 'co4-key-3333333333333333';

        $first = $this->checkout()->checkout(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $key,
        );
        $second = $this->checkout()->checkout(
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $this->scenario->refresh(User::class, $buyer->getId()),
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $key,
        );

        self::assertSame(PaymentCheckoutOutcome::Accepted, $first->outcome);
        self::assertSame(PaymentCheckoutOutcome::Accepted, $second->outcome);
        self::assertTrue($first->attempt->getId()->equals($second->attempt->getId()));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_attempts'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(['authorize:'.$first->attempt->getId()->toRfc4122()], $this->sandboxAdapter->getCalls());
    }

    public function testRawIdempotencyKeyIsNeverPersistedOrAudited(): void
    {
        [$order, , $buyer] = $this->draftOrder('co5');
        $key = 'co5-key-4444444444444444';

        $this->checkout()->checkout(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $key,
        );

        $storedHashes = $this->em->getConnection()->fetchFirstColumn('SELECT idempotency_key_hash FROM payment_attempts');
        foreach ($storedHashes as $hash) {
            self::assertNotSame($key, $hash);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
        }

        $eventHashes = $this->em->getConnection()->fetchFirstColumn('SELECT idempotency_key_hash FROM payment_events');
        foreach ($eventHashes as $hash) {
            self::assertNotSame($key, $hash);
        }

        $auditMetadata = $this->em->getConnection()->fetchFirstColumn('SELECT metadata FROM security_audit_events');
        foreach ($auditMetadata as $metadata) {
            self::assertStringNotContainsString($key, (string) $metadata);
        }

        $allColumnValues = $this->em->getConnection()->fetchFirstColumn(
            'SELECT CONCAT_WS("|", provider_code, environment, provider_event_reference, event_type, payload_hash, signature_fingerprint, failure_reason_code, sanitized_metadata)
               FROM payment_webhook_inbox_events',
        );
        foreach ($allColumnValues as $value) {
            self::assertStringNotContainsString($key, (string) $value);
        }
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
        $order = $this->scenario->service(\App\Service\CommerceOrderManager::class)->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        return [$order, $offer, $buyer];
    }

    private function checkout(): PaymentCheckoutOrchestrator
    {
        return $this->scenario->service(PaymentCheckoutOrchestrator::class);
    }
}
