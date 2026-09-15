<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PaymentAttempt;
use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Service\PaymentWebhookDueProcessor;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Due-batch selection and processing for Stage 2.19 webhook ops.
 */
final class PaymentWebhookProcessDueTest extends KernelTestCase
{
    use CommerceTestFixtures;

    protected function setUp(): void
    {
        $this->bootCommerce();
        $sa = $this->scenario->superAdmin('pwd_sa@example.com');
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

    public function testDueIncludesReceivedRetryPendingAndStaleLease(): void
    {
        $received = $this->persistReceived($this->initiatedAttempt('pwd_a1'), 'pwd_a-rcv-00000001');
        $retry = $this->persistReceived($this->initiatedAttempt('pwd_a2'), 'pwd_a-rty-00000002');
        $stale = $this->persistReceived($this->initiatedAttempt('pwd_a3'), 'pwd_a-stl-00000003');

        $this->forceRetryPending($retry, '2020-01-01 00:00:00');
        $this->forceStaleProcessing($stale, '2020-01-01 00:00:00');
        $this->em->clear();

        $summary = $this->dueProcessor()->process(25);
        self::assertSame(3, $summary->selected);
        self::assertSame(3, $summary->processed);
        self::assertSame(0, $summary->failed);
        self::assertSame(
            PaymentWebhookInboxStatus::Processed->value,
            $this->statusOf($received->getId()),
        );
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookBatchProcessed->value));
    }

    public function testFutureRetryAndTerminalAreSkipped(): void
    {
        $attempt = $this->initiatedAttempt('pwd_b');
        $future = $this->persistReceived($attempt, 'pwd_b-fut-00000001');
        $processed = $this->persistReceived($this->initiatedAttempt('pwd_b2'), 'pwd_b-prc-00000002');

        $this->forceRetryPending($future, '2099-01-01 00:00:00');
        $this->forceProcessed($processed);
        $this->em->clear();

        $summary = $this->dueProcessor()->process(25);
        self::assertSame(0, $summary->selected);
        self::assertSame(PaymentWebhookInboxStatus::RetryPending->value, $this->statusOf($future->getId()));
        self::assertSame(PaymentWebhookInboxStatus::Processed->value, $this->statusOf($processed->getId()));
    }

    public function testLimitCapsSelection(): void
    {
        $this->persistReceived($this->initiatedAttempt('pwd_c1'), 'pwd_c-a-0000000001');
        $this->persistReceived($this->initiatedAttempt('pwd_c2'), 'pwd_c-b-0000000002');
        $this->persistReceived($this->initiatedAttempt('pwd_c3'), 'pwd_c-c-0000000003');
        $this->em->clear();

        $summary = $this->dueProcessor()->process(2);
        self::assertSame(2, $summary->selected);
        self::assertSame(2, $summary->processed);
    }

    public function testDryRunCountsWithoutClaim(): void
    {
        $attempt = $this->initiatedAttempt('pwd_d');
        $event = $this->persistReceived($attempt, 'pwd_d-dry-00000001');
        $this->em->clear();

        $summary = $this->dueProcessor()->process(50, dryRun: true);
        self::assertTrue($summary->dryRun);
        self::assertSame(1, $summary->selected);
        self::assertSame(0, $summary->processed);
        self::assertSame(PaymentWebhookInboxStatus::Received->value, $this->statusOf($event->getId()));
        self::assertSame(0, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookBatchProcessed->value));
    }

    private function dueProcessor(): PaymentWebhookDueProcessor
    {
        return $this->scenario->service(PaymentWebhookDueProcessor::class);
    }

    private function statusOf(Uuid $id): string
    {
        $status = $this->em->getConnection()->fetchOne(
            'SELECT processing_status FROM payment_webhook_inbox_events WHERE id = ?',
            [$id->toBinary()],
        );
        self::assertIsString($status);

        return $status;
    }

    private function forceRetryPending(PaymentWebhookInboxEvent $event, string $nextRetryAt): void
    {
        $now = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));
        $token = new UuidV7();
        $fresh = $this->reloadInbox($event->getId());
        $fresh->applyClaim($token, $now);
        $this->em->flush();
        $fresh->scheduleRetry('conflict', $now, $token);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE payment_webhook_inbox_events SET next_retry_at = ? WHERE id = ?',
            [$nextRetryAt, $event->getId()->toBinary()],
        );
    }

    private function forceStaleProcessing(PaymentWebhookInboxEvent $event, string $leaseExpiresAt): void
    {
        $now = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));
        $token = new UuidV7();
        $fresh = $this->reloadInbox($event->getId());
        $fresh->applyClaim($token, $now);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE payment_webhook_inbox_events SET lease_expires_at = ? WHERE id = ?',
            [$leaseExpiresAt, $event->getId()->toBinary()],
        );
    }

    private function forceProcessed(PaymentWebhookInboxEvent $event): void
    {
        $now = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));
        $token = new UuidV7();
        $fresh = $this->reloadInbox($event->getId());
        $fresh->applyClaim($token, $now);
        $this->em->flush();
        $fresh->markProcessed($token, $now);
        $this->em->flush();
    }

    private function reloadInbox(Uuid $id): PaymentWebhookInboxEvent
    {
        $fresh = $this->scenario->service(\App\Repository\PaymentWebhookInboxEventRepository::class)->findOneById($id);
        self::assertInstanceOf(PaymentWebhookInboxEvent::class, $fresh);

        return $fresh;
    }

    private function persistReceived(PaymentAttempt $attempt, string $providerEventReference): PaymentWebhookInboxEvent
    {
        $verified = new \App\Commerce\VerifiedPaymentWebhook(
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            providerEventReference: $providerEventReference,
            eventType: PaymentEventType::Authorized,
            payloadHash: hash('sha256', 'seed-'.$providerEventReference),
            signatureFingerprint: hash('sha256', 'sig-'.$providerEventReference),
            providerOccurredAt: new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC')),
            receivedAt: new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC')),
            paymentAttemptId: $attempt->getId(),
            orderPublicReference: $attempt->getOrder()->getPublicReference(),
            amount: $attempt->getAmount(),
            sanitizedMetadata: [
                'provider_code' => 'sandbox_provider',
                'provider_environment' => 'sandbox',
                'event_source' => 'webhook',
                'amount_minor' => $attempt->getAmountMinor(),
                'currency' => $attempt->getCurrency(),
                'order_public_reference' => $attempt->getOrder()->getPublicReference(),
            ],
        );
        $event = PaymentWebhookInboxEvent::receiveVerified($verified, $attempt);
        $this->scenario->service(\App\Repository\PaymentWebhookInboxEventRepository::class)->save($event);

        return $event;
    }

    private function initiatedAttempt(string $suffix): PaymentAttempt
    {
        $sa = $this->scenario->superAdmin($suffix.'-sa@example.com');
        $version = $this->scenario->activeVersion($sa, $suffix);
        $offer = $this->scenario->activeOffer($sa, $version, $suffix.'_code', 10000, 2000);
        $buyer = $this->scenario->activeUser($suffix.'-buyer@example.com');
        $order = $this->scenario->service(CommerceOrderManager::class)->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        return $this->scenario->service(PaymentAttemptManager::class)->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $suffix.'-key-0000000000000000',
            'start_payment',
        );
    }
}
