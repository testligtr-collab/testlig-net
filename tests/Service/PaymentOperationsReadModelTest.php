<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\PaymentProviderEnvironment;
use App\Service\PaymentOperationsReadModel;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Light coverage for payment ops read models.
 */
final class PaymentOperationsReadModelTest extends KernelTestCase
{
    use CommerceTestFixtures;

    protected function setUp(): void
    {
        $this->bootCommerce();
        $sa = $this->scenario->superAdmin('prm_sa@example.com');
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($sa);
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

    public function testSummaryAndQueueViewsAreSafeAggregates(): void
    {
        $sa = $this->scenario->superAdmin('prm_op_sa@example.com');
        $read = $this->scenario->service(PaymentOperationsReadModel::class);

        $summary = $read->getOperationsSummary($sa->getId(), 'sandbox_provider', PaymentProviderEnvironment::Sandbox);
        self::assertArrayHasKey('initiated', $summary->attemptCountsByStatus);
        self::assertSame(0, $summary->dueWebhookCount);
        self::assertSame(0, $summary->deadLetterCount);

        $queue = $read->getWebhookQueueSummary($sa->getId(), 'sandbox_provider', PaymentProviderEnvironment::Sandbox);
        self::assertSame(0, $queue->dueCount);
        self::assertSame(0, $queue->deadLetterCount);
        self::assertSame(0, $queue->processedCount);
    }
}
