<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PaymentAttempt;
use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\CommerceFailureReason;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Exception\CommerceException;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Service\PaymentWebhookDeadLetterRequeueService;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Controlled dead-letter requeue for Stage 2.19.
 */
final class PaymentWebhookDeadLetterRequeueTest extends KernelTestCase
{
    use CommerceTestFixtures;

    protected function setUp(): void
    {
        $this->bootCommerce();
        $sa = $this->scenario->superAdmin('pdl_sa@example.com');
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

    public function testSuperAdminRequeuePreservesAttemptCount(): void
    {
        $sa = $this->scenario->superAdmin('pdl_rq_sa@example.com');
        $attempt = $this->initiatedAttempt('pdl_a');
        $event = $this->persistDeadLetter($attempt, 'pdl_a-dl-0000000001', 4);

        $result = $this->requeue()->requeue(
            $event->getId(),
            $sa->getId(),
            'ops_requeue',
            true,
        );

        self::assertSame(PaymentWebhookInboxStatus::RetryPending, $result->status);
        self::assertSame(4, $result->attemptCount);
        self::assertSame(
            PaymentWebhookInboxStatus::RetryPending->value,
            $this->em->getConnection()->fetchOne(
                'SELECT processing_status FROM payment_webhook_inbox_events WHERE id = ?',
                [$event->getId()->toBinary()],
            ),
        );
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne(
            'SELECT attempt_count FROM payment_webhook_inbox_events WHERE id = ?',
            [$event->getId()->toBinary()],
        ));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookDeadLetterRequeued->value));
    }

    public function testConfirmRequired(): void
    {
        $sa = $this->scenario->superAdmin('pdl_cf_sa@example.com');
        $attempt = $this->initiatedAttempt('pdl_b');
        $event = $this->persistDeadLetter($attempt, 'pdl_b-dl-0000000001', 2);

        try {
            $this->requeue()->requeue($event->getId(), $sa->getId(), 'ops_requeue', false);
            self::fail('Expected confirm required');
        } catch (CommerceException $e) {
            self::assertSame(CommerceFailureReason::InvalidInput, $e->getReason());
        } finally {
            $this->recoverDoctrine();
        }
    }

    public function testStaleSuperAdminDenied(): void
    {
        $sa = $this->scenario->superAdmin('pdl_st_sa@example.com');
        $attempt = $this->initiatedAttempt('pdl_c');
        $event = $this->persistDeadLetter($attempt, 'pdl_c-dl-0000000001', 3);

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$sa->getId()->toBinary()],
        );

        try {
            $this->requeue()->requeue($event->getId(), $sa->getId(), 'ops_requeue', true);
            self::fail('Expected stale SA denied');
        } catch (CommerceException $e) {
            self::assertSame(CommerceFailureReason::Unauthorized, $e->getReason());
        } finally {
            $this->recoverDoctrine();
        }
    }

    private function requeue(): PaymentWebhookDeadLetterRequeueService
    {
        return $this->scenario->service(PaymentWebhookDeadLetterRequeueService::class);
    }

    private function persistDeadLetter(PaymentAttempt $attempt, string $ref, int $attemptCount): PaymentWebhookInboxEvent
    {
        $verified = new \App\Commerce\VerifiedPaymentWebhook(
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
        $this->scenario->service(\App\Repository\PaymentWebhookInboxEventRepository::class)->save($event);

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
                max(1, $attemptCount),
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
                $attemptCount,
                'conflict',
                $bin,
            ],
        );
        $this->em->clear();

        $fresh = $this->scenario->service(\App\Repository\PaymentWebhookInboxEventRepository::class)
            ->findOneById($event->getId());
        self::assertInstanceOf(PaymentWebhookInboxEvent::class, $fresh);

        return $fresh;
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
