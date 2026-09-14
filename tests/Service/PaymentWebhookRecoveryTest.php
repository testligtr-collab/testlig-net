<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CommerceOrder;
use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\CommerceFailureReason;
use App\Enum\CommerceOrderStatus;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Exception\CommerceException;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Service\PaymentWebhookIngress;
use App\Service\PaymentWebhookProcessor;
use App\Tests\Support\CommerceTestFixtures;
use App\Tests\Support\ConfigurablePaymentWebhookProcessingCheckpoint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Crash/recovery and claim/lease behaviour for payment webhooks.
 */
final class PaymentWebhookRecoveryTest extends KernelTestCase
{
    use CommerceTestFixtures;

    private ConfigurablePaymentWebhookProcessingCheckpoint $checkpoint;

    protected function setUp(): void
    {
        $this->bootCommerce();
        $sa = $this->scenario->superAdmin('whr_sa@example.com');
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($sa);
        $checkpoint = static::getContainer()->get(ConfigurablePaymentWebhookProcessingCheckpoint::class);
        self::assertInstanceOf(ConfigurablePaymentWebhookProcessingCheckpoint::class, $checkpoint);
        $checkpoint->clear();
        $this->checkpoint = $checkpoint;
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        try {
            $this->checkpoint->clear();
            $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
            self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
            $override->setActor(null);
            $this->cleanupCommerce();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testFailureAfterClaimBeforeSettlementSchedulesRetryThenSucceeds(): void
    {
        $attempt = $this->initiatedAttempt('whr_a');
        $this->checkpoint->failOnceAt('before_settlement');

        $failed = $this->ingressWebhook($attempt, 'whr_a-auth-00000001', PaymentEventType::Authorized);
        self::assertFalse($failed->accepted);
        self::assertSame(409, $failed->httpStatus);
        self::assertSame('conflict', $failed->reasonCode);

        $row = $this->inboxRow('whr_a-auth-00000001');
        self::assertSame(PaymentWebhookInboxStatus::RetryPending->value, $row['processing_status']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));

        $this->em->getConnection()->executeStatement(
            'UPDATE payment_webhook_inbox_events SET next_retry_at = ? WHERE provider_event_reference = ?',
            ['2020-01-01 00:00:00', 'whr_a-auth-00000001'],
        );

        $retry = $this->ingressWebhook($attempt, 'whr_a-auth-00000001', PaymentEventType::Authorized);
        self::assertTrue($retry->accepted);
        self::assertSame('processed', $retry->reasonCode);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(PaymentAttemptStatus::Authorized, $this->reloadAttempt($attempt)->getStatus());
    }

    public function testCaptureCommitsThenFulfillmentFailureRecoversOnRetry(): void
    {
        $attempt = $this->initiatedAttempt('whr_b', 10000, 2000);
        $this->ingressWebhook($attempt, 'whr_b-auth-00000001', PaymentEventType::Authorized);

        $this->checkpoint->failOnceAt('after_settlement_before_fulfillment');
        $failed = $this->ingressWebhook($attempt, 'whr_b-cap-0000000001', PaymentEventType::Captured);
        self::assertFalse($failed->accepted);
        self::assertSame(409, $failed->httpStatus);

        self::assertSame(PaymentAttemptStatus::Captured, $this->reloadAttempt($attempt)->getStatus());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        $row = $this->inboxRow('whr_b-cap-0000000001');
        self::assertSame(PaymentWebhookInboxStatus::RetryPending->value, $row['processing_status']);

        $this->em->getConnection()->executeStatement(
            'UPDATE payment_webhook_inbox_events SET next_retry_at = ? WHERE provider_event_reference = ?',
            ['2020-01-01 00:00:00', 'whr_b-cap-0000000001'],
        );

        $retry = $this->ingressWebhook($attempt, 'whr_b-cap-0000000001', PaymentEventType::Captured);
        self::assertTrue($retry->accepted);
        self::assertSame('processed', $retry->reasonCode);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM commerce_fulfillments WHERE status = 'completed'",
        ));
        self::assertSame(CommerceOrderStatus::Paid, $this->reloadAttempt($attempt)->getOrder()->getStatus());
        self::assertSame(PaymentWebhookInboxStatus::Processed->value, $this->inboxRow('whr_b-cap-0000000001')['processing_status']);
    }

    public function testFailureAfterFulfillmentBeforeProcessedRecoversWithoutDuplicates(): void
    {
        $attempt = $this->initiatedAttempt('whr_c', 10000, 2000);
        $this->ingressWebhook($attempt, 'whr_c-auth-00000001', PaymentEventType::Authorized);

        $this->checkpoint->failOnceAt('after_fulfillment_before_processed');
        $failed = $this->ingressWebhook($attempt, 'whr_c-cap-0000000001', PaymentEventType::Captured);
        self::assertFalse($failed->accepted);
        self::assertSame(409, $failed->httpStatus);

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        self::assertSame(PaymentWebhookInboxStatus::RetryPending->value, $this->inboxRow('whr_c-cap-0000000001')['processing_status']);

        $this->em->getConnection()->executeStatement(
            'UPDATE payment_webhook_inbox_events SET next_retry_at = ? WHERE provider_event_reference = ?',
            ['2020-01-01 00:00:00', 'whr_c-cap-0000000001'],
        );

        $retry = $this->ingressWebhook($attempt, 'whr_c-cap-0000000001', PaymentEventType::Captured);
        self::assertTrue($retry->accepted);
        self::assertSame('processed', $retry->reasonCode);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM commerce_fulfillments WHERE status = 'completed'",
        ));
        self::assertSame(CommerceOrderStatus::Paid, $this->reloadAttempt($attempt)->getOrder()->getStatus());
        self::assertSame(
            1,
            $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookProcessed->value),
        );
    }

    public function testStaleProcessingLeaseCanBeReclaimed(): void
    {
        $attempt = $this->initiatedAttempt('whr_d');
        $body = $this->webhookBody($attempt, 'whr_d-auth-00000001', PaymentEventType::Authorized);
        $this->persistReceivedInbox($attempt, $body, 'whr_d-auth-00000001', PaymentEventType::Authorized);

        $id = Uuid::fromBinary((string) $this->em->getConnection()->fetchOne(
            'SELECT id FROM payment_webhook_inbox_events WHERE provider_event_reference = ?',
            ['whr_d-auth-00000001'],
        ));

        $first = $this->inboxRepo()->claimForProcessing($id);
        self::assertNotNull($first);
        $blocked = $this->inboxRepo()->claimForProcessing($id);
        self::assertNull($blocked, 'Active lease must block second claim');

        $this->em->getConnection()->executeStatement(
            'UPDATE payment_webhook_inbox_events SET lease_expires_at = ? WHERE id = ?',
            ['2020-01-01 00:00:00', $id->toBinary()],
        );
        $this->em->clear();

        $second = $this->inboxRepo()->claimForProcessing($id);
        if (null === $second) {
            self::fail('Expected reclaim after lease expiry');
        }
        self::assertSame(2, $second['event']->getAttemptCount());
    }

    public function testRetryLimitMovesToDeadLetter(): void
    {
        $attempt = $this->initiatedAttempt('whr_e');
        $this->persistReceivedInbox(
            $attempt,
            $this->webhookBody($attempt, 'whr_e-auth-00000001', PaymentEventType::Authorized),
            'whr_e-auth-00000001',
            PaymentEventType::Authorized,
        );
        $id = Uuid::fromBinary((string) $this->em->getConnection()->fetchOne(
            'SELECT id FROM payment_webhook_inbox_events WHERE provider_event_reference = ?',
            ['whr_e-auth-00000001'],
        ));

        for ($i = 0; $i < 5; ++$i) {
            $this->checkpoint->failOnceAt('before_settlement');
            $this->em->getConnection()->executeStatement(
                'UPDATE payment_webhook_inbox_events
                    SET next_retry_at = IF(processing_status = \'retry_pending\', ?, next_retry_at),
                        lease_expires_at = IF(processing_status = \'processing\', ?, lease_expires_at)
                  WHERE id = ?',
                ['2020-01-01 00:00:00', '2020-01-01 00:00:00', $id->toBinary()],
            );
            $this->em->clear();
            $processor = static::getContainer()->get(PaymentWebhookProcessor::class);
            self::assertInstanceOf(PaymentWebhookProcessor::class, $processor);
            try {
                $result = $processor->process($id);
                self::assertSame(PaymentWebhookInboxStatus::DeadLetter, $result->getProcessingStatus());
                break;
            } catch (CommerceException $e) {
                self::assertSame(CommerceFailureReason::Conflict, $e->getReason());
            }
        }

        $row = $this->inboxRow('whr_e-auth-00000001');
        self::assertSame(PaymentWebhookInboxStatus::DeadLetter->value, $row['processing_status']);
        self::assertSame(CommerceFailureReason::Conflict->value, $row['failure_reason_code']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
    }

    public function testPermanentIntegrityMismatchRejects(): void
    {
        $attempt = $this->initiatedAttempt('whr_f', 10000, 2000);
        $result = $this->ingressWebhook($attempt, 'whr_f-amt-0000000001', PaymentEventType::Authorized, 11999);
        self::assertTrue($result->accepted);
        self::assertSame(PaymentWebhookInboxStatus::Rejected->value, $result->reasonCode);
        self::assertSame(CommerceFailureReason::TotalMismatch->value, $this->inboxRow('whr_f-amt-0000000001')['failure_reason_code']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
    }

    public function testAuditFailureBeforeProcessedKeepsInboxUnprocessed(): void
    {
        $attempt = $this->initiatedAttempt('whr_g');
        $this->checkpoint->failOnceAt('before_processed_audit');

        $failed = $this->ingressWebhook($attempt, 'whr_g-auth-00000001', PaymentEventType::Authorized);
        self::assertFalse($failed->accepted);
        self::assertSame(409, $failed->httpStatus);

        $row = $this->inboxRow('whr_g-auth-00000001');
        self::assertSame(PaymentWebhookInboxStatus::RetryPending->value, $row['processing_status']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(0, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookProcessed->value));

        $this->em->getConnection()->executeStatement(
            'UPDATE payment_webhook_inbox_events SET next_retry_at = ? WHERE provider_event_reference = ?',
            ['2020-01-01 00:00:00', 'whr_g-auth-00000001'],
        );
        $retry = $this->ingressWebhook($attempt, 'whr_g-auth-00000001', PaymentEventType::Authorized);
        self::assertTrue($retry->accepted);
        self::assertSame('processed', $retry->reasonCode);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookProcessed->value));
    }

    public function testProcessedDuplicateDoesNotMutateDomain(): void
    {
        $attempt = $this->initiatedAttempt('whr_h');
        $body = $this->webhookBody($attempt, 'whr_h-auth-00000001', PaymentEventType::Authorized);
        self::assertTrue($this->ingressRaw($body)->accepted);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));

        $replay = $this->ingressRaw($body);
        self::assertTrue($replay->accepted);
        self::assertSame('processed', $replay->reasonCode);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events'));
        self::assertSame(
            1,
            $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookProcessed->value),
        );
    }

    /**
     * @return array{processing_status: string, failure_reason_code: ?string}
     */
    private function inboxRow(string $providerEventReference): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT processing_status, failure_reason_code FROM payment_webhook_inbox_events WHERE provider_event_reference = ?',
            [$providerEventReference],
        );
        self::assertIsArray($row);
        self::assertArrayHasKey('processing_status', $row);
        self::assertArrayHasKey('failure_reason_code', $row);
        self::assertIsString($row['processing_status']);

        return [
            'processing_status' => $row['processing_status'],
            'failure_reason_code' => null === $row['failure_reason_code'] ? null : (string) $row['failure_reason_code'],
        ];
    }

    private function persistReceivedInbox(
        PaymentAttempt $attempt,
        string $body,
        string $providerEventReference,
        PaymentEventType $eventType,
    ): void {
        unset($body, $eventType);
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
        $event = \App\Entity\PaymentWebhookInboxEvent::receiveVerified($verified, $attempt);
        $this->inboxRepo()->save($event);
    }

    private function initiatedAttempt(string $suffix, int $price = 19999, int $taxRateBasisPoints = 2000): PaymentAttempt
    {
        [$order, , $buyer] = $this->draftOrder($suffix, $price, $taxRateBasisPoints);

        return $this->attempts()->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $suffix.'-key-0000000000000000',
            'start_payment',
        );
    }

    /**
     * @return array{0: CommerceOrder, 1: \App\Entity\CommercialOffer, 2: User}
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

    private function webhookBody(
        PaymentAttempt $attempt,
        string $providerEventReference,
        PaymentEventType $eventType,
        ?int $amountMinor = null,
        ?string $currency = null,
    ): string {
        $payload = [
            'provider_event_reference' => $providerEventReference,
            'event_type' => $eventType->value,
            'occurred_at' => '2026-09-13T12:00:00+00:00',
            'payment_attempt_id' => $attempt->getId()->toRfc4122(),
            'order_public_reference' => $attempt->getOrder()->getPublicReference(),
        ];
        if ($eventType->requiresAmount()) {
            $payload['amount_minor'] = $amountMinor ?? $attempt->getAmountMinor();
            $payload['currency'] = $currency ?? $attempt->getCurrency();
        }
        if (PaymentEventType::Failed === $eventType) {
            $payload['failure_code'] = 'provider_declined';
        }

        return json_encode($payload, \JSON_THROW_ON_ERROR);
    }

    private function ingressWebhook(
        PaymentAttempt $attempt,
        string $providerEventReference,
        PaymentEventType $eventType,
        ?int $amountMinor = null,
        ?string $currency = null,
    ): \App\Dto\PaymentWebhookIngressResult {
        return $this->ingressRaw($this->webhookBody($attempt, $providerEventReference, $eventType, $amountMinor, $currency));
    }

    private function ingressRaw(string $body): \App\Dto\PaymentWebhookIngressResult
    {
        $ts = (string) (new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $verifier = $this->scenario->service(\App\Commerce\Sandbox\SandboxWebhookSignatureVerifier::class);
        $signature = $verifier->sign($body, (int) $ts);
        $request = Request::create(
            '/webhook/odeme/sandbox_provider',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TESTLIG_WEBHOOK_SIGNATURE' => $signature,
                'HTTP_X_TESTLIG_WEBHOOK_TIMESTAMP' => $ts,
            ],
            content: $body,
        );

        return $this->scenario->service(PaymentWebhookIngress::class)->handle('sandbox_provider', $request);
    }

    private function reloadAttempt(PaymentAttempt $attempt): PaymentAttempt
    {
        $fresh = $this->scenario->refresh(PaymentAttempt::class, $attempt->getId());
        self::assertInstanceOf(PaymentAttempt::class, $fresh);

        return $fresh;
    }

    private function orders(): CommerceOrderManager
    {
        return $this->scenario->service(CommerceOrderManager::class);
    }

    private function attempts(): PaymentAttemptManager
    {
        return $this->scenario->service(PaymentAttemptManager::class);
    }

    private function inboxRepo(): \App\Repository\PaymentWebhookInboxEventRepository
    {
        return $this->scenario->service(\App\Repository\PaymentWebhookInboxEventRepository::class);
    }
}
