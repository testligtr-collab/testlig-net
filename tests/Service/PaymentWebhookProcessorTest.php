<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Commerce\Sandbox\SandboxWebhookSignatureVerifier;
use App\Commerce\VerifiedPaymentWebhook;
use App\Entity\CommerceOrder;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentWebhookInboxEvent;
use App\Entity\User;
use App\Enum\CommerceFailureReason;
use App\Enum\CommerceOrderStatus;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Service\PaymentWebhookIngress;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Verified webhook inbox rows drive settlement, refunds and fulfillment exactly once.
 */
final class PaymentWebhookProcessorTest extends KernelTestCase
{
    use CommerceTestFixtures;

    private string $signingKey;

    protected function setUp(): void
    {
        $this->bootCommerce();
        $sa = $this->scenario->superAdmin('whp-sa@example.com');
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($sa);
        $this->signingKey = (string) ($_ENV['PAYMENT_SANDBOX_WEBHOOK_SIGNING_KEY'] ?? getenv('PAYMENT_SANDBOX_WEBHOOK_SIGNING_KEY'));
        self::assertNotSame('', $this->signingKey);
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

    public function testAuthorizeThenCaptureViaWebhookSettlesAndFulfillsOnce(): void
    {
        $attempt = $this->initiatedAttempt('whp1', 10000, 2000);

        $auth = $this->ingressWebhook($attempt, 'whp1-auth-0000000001', PaymentEventType::Authorized);
        self::assertTrue($auth->accepted);
        self::assertSame(PaymentAttemptStatus::Authorized, $this->reloadAttempt($attempt)->getStatus());

        $cap = $this->ingressWebhook($attempt, 'whp1-cap-00000000001', PaymentEventType::Captured);
        self::assertTrue($cap->accepted);
        $fresh = $this->reloadAttempt($attempt);
        self::assertSame(PaymentAttemptStatus::Captured, $fresh->getStatus());
        self::assertSame(CommerceOrderStatus::Paid, $fresh->getOrder()->getStatus());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookProcessed->value));
    }

    public function testDuplicateSameEventReferenceIsIdempotent(): void
    {
        $attempt = $this->initiatedAttempt('whp2');
        $body = $this->webhookBody($attempt, 'whp2-auth-0000000002', PaymentEventType::Authorized);

        $first = $this->ingressRaw($body);
        $second = $this->ingressRaw($body);

        self::assertTrue($first->accepted);
        self::assertTrue($second->accepted);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
    }

    public function testSameReferenceDifferentPayloadHashIsIntegrityConflict(): void
    {
        $attempt = $this->initiatedAttempt('whp3');
        $firstBody = $this->webhookBody($attempt, 'whp3-conflict-0000001', PaymentEventType::Authorized, 12000);
        self::assertTrue($this->ingressRaw($firstBody)->accepted);

        $secondBody = $this->webhookBody($attempt, 'whp3-conflict-0000001', PaymentEventType::Authorized, 11999);
        $result = $this->ingressRaw($secondBody);

        self::assertFalse($result->accepted);
        self::assertSame(409, $result->httpStatus);
        self::assertSame('integrity_conflict', $result->reasonCode);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events'));
    }

    public function testAmountMismatchIsIntegrityRejected(): void
    {
        $attempt = $this->initiatedAttempt('whp4', 10000, 2000);
        $result = $this->ingressWebhook($attempt, 'whp4-amt-00000000001', PaymentEventType::Authorized, 11999);

        self::assertTrue($result->accepted);
        self::assertSame(PaymentWebhookInboxStatus::Rejected->value, $result->reasonCode);
        $inbox = $this->em->getConnection()->fetchAssociative(
            'SELECT processing_status, failure_reason_code FROM payment_webhook_inbox_events WHERE provider_event_reference = ?',
            ['whp4-amt-00000000001'],
        );
        self::assertIsArray($inbox);
        self::assertSame(PaymentWebhookInboxStatus::Rejected->value, $inbox['processing_status']);
        self::assertSame(CommerceFailureReason::TotalMismatch->value, $inbox['failure_reason_code']);
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentWebhookIntegrityFailed->value));
    }

    public function testCurrencyMismatchIsIntegrityRejected(): void
    {
        $attempt = $this->initiatedAttempt('whp5', 10000, 2000);
        $result = $this->ingressWebhook($attempt, 'whp5-cur-00000000001', PaymentEventType::Authorized, 12000, 'USD');

        self::assertTrue($result->accepted);
        self::assertSame(PaymentWebhookInboxStatus::Rejected->value, $result->reasonCode);
        $inbox = $this->em->getConnection()->fetchAssociative(
            'SELECT processing_status, failure_reason_code FROM payment_webhook_inbox_events WHERE provider_event_reference = ?',
            ['whp5-cur-00000000001'],
        );
        self::assertIsArray($inbox);
        self::assertSame(PaymentWebhookInboxStatus::Rejected->value, $inbox['processing_status']);
        self::assertSame(CommerceFailureReason::CurrencyMismatch->value, $inbox['failure_reason_code']);
    }

    public function testOutOfOrderCaptureThenLateAuthorizeDoesNotRollback(): void
    {
        $attempt = $this->initiatedAttempt('whp6', 10000, 2000);

        $capture = $this->ingressWebhook($attempt, 'whp6-cap-00000000001', PaymentEventType::Captured);
        self::assertTrue($capture->accepted);
        self::assertSame(PaymentAttemptStatus::Captured, $this->reloadAttempt($attempt)->getStatus());

        $authorize = $this->ingressWebhook($attempt, 'whp6-auth-0000000001', PaymentEventType::Authorized);
        self::assertTrue($authorize->accepted);
        self::assertSame(PaymentAttemptStatus::Captured, $this->reloadAttempt($attempt)->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_events'));
    }

    public function testCaptureAfterFailedIsRejected(): void
    {
        $attempt = $this->initiatedAttempt('whp7');
        self::assertTrue($this->ingressWebhook($attempt, 'whp7-fail-00000000001', PaymentEventType::Failed)->accepted);
        self::assertSame(PaymentAttemptStatus::Failed, $this->reloadAttempt($attempt)->getStatus());

        $capture = $this->ingressWebhook($attempt, 'whp7-cap-00000000001', PaymentEventType::Captured);
        self::assertTrue($capture->accepted);
        self::assertSame(PaymentWebhookInboxStatus::Rejected->value, $capture->reasonCode);
        $inbox = $this->em->getConnection()->fetchAssociative(
            'SELECT processing_status, failure_reason_code FROM payment_webhook_inbox_events WHERE provider_event_reference = ?',
            ['whp7-cap-00000000001'],
        );
        self::assertIsArray($inbox);
        self::assertSame(PaymentWebhookInboxStatus::Rejected->value, $inbox['processing_status']);
        self::assertSame(CommerceFailureReason::InvalidTransition->value, $inbox['failure_reason_code']);
    }

    public function testRefundSucceededPath(): void
    {
        $attempt = $this->initiatedAttempt('whp8', 10000, 2000);
        $this->ingressWebhook($attempt, 'whp8-auth-0000000001', PaymentEventType::Authorized);
        $this->ingressWebhook($attempt, 'whp8-cap-00000000001', PaymentEventType::Captured);

        $refund = $this->ingressWebhook(
            $attempt,
            'whp8-ref-000000000001',
            PaymentEventType::RefundSucceeded,
            2000,
        );
        self::assertTrue($refund->accepted);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_refunds'));
        self::assertSame('succeeded', $this->em->getConnection()->fetchOne('SELECT status FROM payment_refunds'));
    }

    public function testOnlyVerifiedCaptureFulfillsAndReplayDoesNotGrantSecondLicense(): void
    {
        $attempt = $this->initiatedAttempt('whp9', 10000, 2000);
        $this->ingressWebhook($attempt, 'whp9-auth-0000000001', PaymentEventType::Authorized);
        $body = $this->webhookBody($attempt, 'whp9-cap-00000000001', PaymentEventType::Captured);

        self::assertTrue($this->ingressRaw($body)->accepted);
        self::assertTrue($this->ingressRaw($body)->accepted);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM commerce_fulfillments WHERE status = 'completed'",
        ));
    }

    public function testStaleOutOfBandAttemptMutationFailsClosedWithoutEntityManagerClear(): void
    {
        $attempt = $this->initiatedAttempt('whp10');
        $this->em->getConnection()->executeStatement(
            'UPDATE payment_attempts SET status = ?, failure_code = ?, failed_at = ?, updated_at = ? WHERE id = ?',
            [
                PaymentAttemptStatus::Failed->value,
                'provider_declined',
                '2026-09-13 12:00:00',
                '2026-09-13 12:00:00',
                $attempt->getId()->toBinary(),
            ],
        );
        // Keep the managed entity stale on purpose — processor must reload fresh rows.
        self::assertSame(PaymentAttemptStatus::Initiated, $attempt->getStatus());

        $result = $this->ingressWebhook($attempt, 'whp10-cap-0000000001', PaymentEventType::Captured);
        self::assertTrue($result->accepted);
        self::assertSame(PaymentWebhookInboxStatus::Rejected->value, $result->reasonCode);
        self::assertSame(PaymentAttemptStatus::Failed, $this->reloadAttempt($attempt)->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
    }

    public function testRawSignatureBodyAndIdempotencyNeverPersistInInboxOrAudit(): void
    {
        $attempt = $this->initiatedAttempt('whp11');
        $body = $this->webhookBody($attempt, 'whp11-auth-000000001', PaymentEventType::Authorized);
        $ts = $this->clock->now()->getTimestamp();
        $verifier = $this->scenario->service(SandboxWebhookSignatureVerifier::class);
        $signature = $verifier->sign($body, $ts);
        $idempotencyHint = 'whp11-idempotency-never-store-me';

        self::assertTrue($this->ingressRaw($body, $signature, $ts)->accepted);

        $inboxRows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM payment_webhook_inbox_events');
        self::assertCount(1, $inboxRows);
        foreach ($inboxRows as $row) {
            foreach ($row as $value) {
                $serialized = \is_string($value) ? $value : json_encode($value, \JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString($body, $serialized);
                self::assertStringNotContainsString($signature, $serialized);
                self::assertStringNotContainsString($this->signingKey, $serialized);
                self::assertStringNotContainsString($idempotencyHint, $serialized);
            }
        }

        $auditMetadata = $this->em->getConnection()->fetchFirstColumn('SELECT metadata FROM security_audit_events');
        foreach ($auditMetadata as $metadata) {
            self::assertStringNotContainsString($body, (string) $metadata);
            self::assertStringNotContainsString($signature, (string) $metadata);
            self::assertStringNotContainsString($this->signingKey, (string) $metadata);
            self::assertStringNotContainsString($idempotencyHint, (string) $metadata);
        }
    }

    public function testConcurrentUniqueProviderEventReferenceIsEnforcedByDatabase(): void
    {
        $attempt = $this->initiatedAttempt('whp12');
        $verified = $this->verifiedWebhook($attempt, 'whp12-uniq-000000001', PaymentEventType::Authorized);
        $first = PaymentWebhookInboxEvent::receiveVerified($verified, $attempt);
        $this->scenario->service(\App\Repository\PaymentWebhookInboxEventRepository::class)->save($first);

        $connection = $this->em->getConnection();
        $this->expectDatabaseRejection(static function () use ($connection, $verified, $attempt): void {
            $connection->executeStatement(
                'INSERT INTO payment_webhook_inbox_events (
                    id, provider_code, environment, provider_event_reference, event_type,
                    payload_hash, signature_fingerprint, received_at, provider_occurred_at,
                    processing_status, payment_attempt_id, schema_version, sanitized_metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v7()->toBinary(),
                    $verified->providerCode,
                    $verified->environment->value,
                    $verified->providerEventReference,
                    $verified->eventType->value,
                    $verified->payloadHash,
                    $verified->signatureFingerprint,
                    $verified->receivedAt->format('Y-m-d H:i:s'),
                    $verified->providerOccurredAt->format('Y-m-d H:i:s'),
                    PaymentWebhookInboxStatus::Received->value,
                    $attempt->getId()->toBinary(),
                    1,
                    json_encode($verified->sanitizedMetadata, \JSON_THROW_ON_ERROR),
                ],
            );
        });
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
            'occurred_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
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

    private function ingressRaw(string $body, ?string $signature = null, ?int $timestamp = null): \App\Dto\PaymentWebhookIngressResult
    {
        $ts = $timestamp ?? $this->clock->now()->getTimestamp();
        $verifier = $this->scenario->service(SandboxWebhookSignatureVerifier::class);
        $request = Request::create(
            '/webhook/odeme/sandbox_provider',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TESTLIG_WEBHOOK_SIGNATURE' => $signature ?? $verifier->sign($body, $ts),
                'HTTP_X_TESTLIG_WEBHOOK_TIMESTAMP' => (string) $ts,
            ],
            $body,
        );

        return $this->ingress()->handle('sandbox_provider', $request);
    }

    private function verifiedWebhook(
        PaymentAttempt $attempt,
        string $providerEventReference,
        PaymentEventType $eventType,
    ): VerifiedPaymentWebhook {
        $body = $this->webhookBody($attempt, $providerEventReference, $eventType);
        $verifier = $this->scenario->service(SandboxWebhookSignatureVerifier::class);
        $ts = $this->clock->now()->getTimestamp();
        $signature = $verifier->sign($body, $ts);

        return new VerifiedPaymentWebhook(
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            providerEventReference: $providerEventReference,
            eventType: $eventType,
            payloadHash: hash('sha256', $body),
            signatureFingerprint: $verifier->fingerprint($signature),
            providerOccurredAt: $this->clock->now(),
            receivedAt: $this->clock->now(),
            paymentAttemptId: $attempt->getId(),
            orderPublicReference: $attempt->getOrder()->getPublicReference(),
            amount: $attempt->getAmount(),
            sanitizedMetadata: [
                'provider_code' => 'sandbox_provider',
                'provider_environment' => PaymentProviderEnvironment::Sandbox->value,
                'event_source' => 'webhook',
                'order_public_reference' => $attempt->getOrder()->getPublicReference(),
                'amount_minor' => $attempt->getAmountMinor(),
                'currency' => $attempt->getCurrency(),
            ],
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

    private function ingress(): PaymentWebhookIngress
    {
        return $this->scenario->service(PaymentWebhookIngress::class);
    }
}
