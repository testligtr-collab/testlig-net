<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Commerce\VerifiedPaymentWebhook;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PaymentOperationsCommandsTest extends KernelTestCase
{
    use CommerceTestFixtures;

    private \App\Entity\User $sa;

    protected function setUp(): void
    {
        $this->bootCommerce();
        $this->sa = $this->scenario->superAdmin('cmd_sa@example.com');
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($this->sa);
    }

    protected function tearDown(): void
    {
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

    public function testProcessDueCommandNumericOutputAndInvalidLimit(): void
    {
        $attempt = $this->initiatedAttempt('cmd_due');
        $this->persistInbox($attempt, 'cmd_due-auth-00000001', PaymentEventType::Authorized);

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $app = new Application($kernel);
        $tester = new CommandTester($app->find('app:payment:webhook:process-due'));
        $exit = $tester->execute(['--limit' => '25', '--dry-run' => true]);
        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/selected=\d+/', $display);
        self::assertStringNotContainsString('cmd_due', $display);
        self::assertStringNotContainsString('@', $display);

        $bad = $tester->execute(['--limit' => '999']);
        self::assertSame(1, $bad);
    }

    public function testRetryDeadLetterCommandRequiresConfirm(): void
    {
        $attempt = $this->initiatedAttempt('cmd_dl');
        $event = $this->persistInbox($attempt, 'cmd_dl-auth-000000001', PaymentEventType::Authorized);
        $this->forceDeadLetter($event->getId());

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $app = new Application($kernel);
        $tester = new CommandTester($app->find('app:payment:webhook:retry-dead-letter'));
        $exit = $tester->execute([
            '--event-id' => $event->getId()->toRfc4122(),
            '--actor-id' => $this->sa->getId()->toRfc4122(),
            '--reason-code' => 'manual_requeue_review',
        ]);
        self::assertSame(1, $exit);

        $ok = $tester->execute([
            '--event-id' => $event->getId()->toRfc4122(),
            '--actor-id' => $this->sa->getId()->toRfc4122(),
            '--reason-code' => 'manual_requeue_review',
            '--confirm' => true,
        ]);
        self::assertSame(0, $ok);
        self::assertStringContainsString('processing_status=retry_pending', $tester->getDisplay());
        self::assertStringNotContainsString($event->getId()->toRfc4122(), $tester->getDisplay());
    }

    public function testReconcileCommandDryRun(): void
    {
        $attempt = $this->initiatedAttempt('cmd_rec');

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $app = new Application($kernel);
        $tester = new CommandTester($app->find('app:payment:reconcile'));
        $exit = $tester->execute([
            '--actor-id' => $this->sa->getId()->toRfc4122(),
            '--provider' => 'sandbox_provider',
            '--environment' => 'sandbox',
            '--attempt-id' => $attempt->getId()->toRfc4122(),
            '--reason-code' => 'manual_reconciliation',
            '--dry-run' => true,
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('dry_run=1', $tester->getDisplay());
        self::assertStringNotContainsString($attempt->getId()->toRfc4122(), $tester->getDisplay());
    }

    private function forceDeadLetter(\Symfony\Component\Uid\Uuid $id): void
    {
        $bin = $id->toBinary();
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'UPDATE payment_webhook_inbox_events SET
                processing_status = ?, claim_token = ?, lease_expires_at = ?,
                processing_started_at = ?, attempt_count = 5,
                next_retry_at = NULL, processed_at = NULL, closed_at = NULL, failure_reason_code = NULL
             WHERE id = ?',
            [PaymentWebhookInboxStatus::Processing->value, $bin, '2026-09-13 12:01:00', '2026-09-13 12:00:00', $bin],
        );
        $conn->executeStatement(
            'UPDATE payment_webhook_inbox_events SET
                processing_status = ?, closed_at = ?, failure_reason_code = ?, last_failure_reason_code = ?,
                claim_token = NULL, lease_expires_at = NULL, next_retry_at = NULL, processed_at = NULL, attempt_count = 5
             WHERE id = ?',
            [
                PaymentWebhookInboxStatus::DeadLetter->value,
                '2026-09-13 12:02:00',
                'webhook_retry_exhausted',
                'webhook_retry_exhausted',
                $bin,
            ],
        );
        $this->em->clear();
    }

    private function persistInbox(
        PaymentAttempt $attempt,
        string $providerEventReference,
        PaymentEventType $eventType,
    ): PaymentWebhookInboxEvent {
        $verified = new VerifiedPaymentWebhook(
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            providerEventReference: $providerEventReference,
            eventType: $eventType,
            payloadHash: hash('sha256', 'seed-'.$providerEventReference),
            signatureFingerprint: hash('sha256', 'sig-'.$providerEventReference),
            providerOccurredAt: $this->clock->now(),
            receivedAt: $this->clock->now(),
            paymentAttemptId: $attempt->getId(),
            orderPublicReference: $attempt->getOrder()->getPublicReference(),
            amount: $attempt->getAmount(),
            sanitizedMetadata: [
                'provider_code' => 'sandbox_provider',
                'provider_environment' => 'sandbox',
                'event_source' => 'webhook',
            ],
        );
        $event = PaymentWebhookInboxEvent::receiveVerified($verified, $attempt);
        $repo = static::getContainer()->get(PaymentWebhookInboxEventRepository::class);
        self::assertInstanceOf(PaymentWebhookInboxEventRepository::class, $repo);
        $repo->save($event);

        return $event;
    }

    private function initiatedAttempt(string $suffix): PaymentAttempt
    {
        $version = $this->scenario->activeVersion($this->sa, $suffix);
        $offer = $this->scenario->activeOffer($this->sa, $version, $suffix.'_code', 19999, 2000);
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
