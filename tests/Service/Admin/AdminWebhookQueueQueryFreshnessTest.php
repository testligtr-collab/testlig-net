<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Commerce\VerifiedPaymentWebhook;
use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Service\Admin\AdminWebhookQueueQuery;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Admin webhook detail must not trust a stale identity-map entity.
 */
final class AdminWebhookQueueQueryFreshnessTest extends KernelTestCase
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

    public function testGetDetailRefreshesManagedStaleDeadLetterStatus(): void
    {
        $sa = $this->scenario->superAdmin('admin_wh_fresh_sa@example.com');
        $buyer = $this->scenario->activeUser('admin_wh_fresh_buyer@example.com');
        $pkgSa = $this->scenario->superAdmin('admin_wh_fresh_pkg_sa@example.com');
        $version = $this->scenario->activeVersion($pkgSa, 'whfresh');
        $offer = $this->scenario->activeOffer($pkgSa, $version, 'whfresh_code', 10000, 2000);
        $order = $this->scenario->service(CommerceOrderManager::class)->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $attempt = $this->scenario->service(PaymentAttemptManager::class)->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            'whfresh-key-0000000000000000',
            'start_payment',
        );

        $ref = 'admin-wh-fresh-'.bin2hex(random_bytes(4));
        $verified = new VerifiedPaymentWebhook(
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            providerEventReference: $ref,
            eventType: PaymentEventType::Authorized,
            payloadHash: hash('sha256', 'seed-'.$ref),
            signatureFingerprint: hash('sha256', 'sig-'.$ref),
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
            ],
        );
        $event = PaymentWebhookInboxEvent::receiveVerified($verified, $attempt);
        $this->em->persist($event);
        $this->em->flush();

        $bin = $event->getId()->toBinary();
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'UPDATE payment_webhook_inbox_events SET
                processing_status = ?, claim_token = ?, lease_expires_at = ?,
                processing_started_at = ?, attempt_count = ?,
                next_retry_at = NULL, processed_at = NULL, closed_at = NULL, failure_reason_code = NULL
             WHERE id = ?',
            [
                PaymentWebhookInboxStatus::Processing->value,
                $bin,
                '2026-09-13 12:01:00',
                '2026-09-13 12:00:00',
                4,
                $bin,
            ],
        );
        $conn->executeStatement(
            'UPDATE payment_webhook_inbox_events SET
                processing_status = ?, closed_at = ?, failure_reason_code = ?,
                attempt_count = ?, last_failure_reason_code = ?,
                claim_token = NULL, lease_expires_at = NULL, next_retry_at = NULL, processed_at = NULL
             WHERE id = ?',
            [
                PaymentWebhookInboxStatus::DeadLetter->value,
                '2026-09-13 12:05:00',
                'conflict',
                4,
                'conflict',
                $bin,
            ],
        );
        $this->em->clear();

        /** @var PaymentWebhookInboxEventRepository $repo */
        $repo = static::getContainer()->get(PaymentWebhookInboxEventRepository::class);
        $managed = $repo->find($event->getId());
        self::assertInstanceOf(PaymentWebhookInboxEvent::class, $managed);
        self::assertSame(PaymentWebhookInboxStatus::DeadLetter, $managed->getProcessingStatus());

        // Out-of-band counter bump while identity-map still has the old attempt_count.
        $conn->executeStatement(
            'UPDATE payment_webhook_inbox_events SET attempt_count = 9 WHERE id = ?',
            [$bin],
        );
        self::assertSame(
            4,
            $managed->getAttemptCount(),
            'Identity-map entity must still look stale before HINT_REFRESH',
        );
        self::assertSame(PaymentWebhookInboxStatus::DeadLetter, $managed->getProcessingStatus());

        /** @var AdminWebhookQueueQuery $query */
        $query = static::getContainer()->get(AdminWebhookQueueQuery::class);
        $view = $query->getDetail($sa->getId(), $event->getId());

        self::assertSame(PaymentWebhookInboxStatus::DeadLetter, $view->status);
        self::assertTrue($view->canRequeue);
        self::assertSame(9, $view->attemptCount);
    }
}
