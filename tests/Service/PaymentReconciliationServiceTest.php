<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Commerce\PaymentProviderTransactionSnapshot;
use App\Commerce\Sandbox\SandboxPaymentProviderReconciliationAdapter;
use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\CommerceFailureReason;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentProviderTransactionStatus;
use App\Enum\PaymentReconciliationItemOutcome;
use App\Enum\PaymentReconciliationRunStatus;
use App\Enum\SecurityAuditAction;
use App\Exception\CommerceException;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Service\PaymentReconciliationService;
use App\Service\PaymentSettlementManager;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Reconciliation decision matrix and fail-closed auth for Stage 2.19.
 */
final class PaymentReconciliationServiceTest extends KernelTestCase
{
    use CommerceTestFixtures;

    private SandboxPaymentProviderReconciliationAdapter $adapter;

    private User $sa;

    protected function setUp(): void
    {
        $this->bootCommerce();
        $this->sa = $this->scenario->superAdmin('prs_sa@example.com');
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($this->sa);
        $adapter = static::getContainer()->get(SandboxPaymentProviderReconciliationAdapter::class);
        self::assertInstanceOf(SandboxPaymentProviderReconciliationAdapter::class, $adapter);
        $adapter->clear();
        $this->adapter = $adapter;
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        try {
            $this->adapter->clear();
            $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
            self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
            $override->setActor(null);
            $this->cleanupCommerce();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testMatchedAuthorized(): void
    {
        $attempt = $this->authorizedAttempt('prs_m');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        $this->adapter->setSnapshot($ref, $this->snapshot($attempt, PaymentProviderTransactionStatus::Authorized));

        $summary = $this->reconcile($attempt);
        self::assertSame(1, $summary->matched);
        self::assertSame(0, $summary->discrepancy);
        self::assertSame(PaymentReconciliationRunStatus::Completed->value, $summary->runStatus);
        self::assertSame(PaymentAttemptStatus::Authorized, $this->reload($attempt)->getStatus());
    }

    public function testLocalBehindEnqueuesCaptureWebhook(): void
    {
        $attempt = $this->authorizedAttempt('prs_lb');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        $this->adapter->setSnapshot($ref, $this->snapshot(
            $attempt,
            PaymentProviderTransactionStatus::Captured,
            // Must be <= MockClock (bootCommerce default 12:00:00) or settlement rejects future occurredAt.
            capturedAt: new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC')),
        ));

        $summary = $this->reconcile($attempt);
        self::assertSame(1, $summary->discrepancy);
        self::assertSame(PaymentReconciliationRunStatus::CompletedWithDiscrepancies->value, $summary->runStatus);
        self::assertSame(PaymentAttemptStatus::Captured, $this->reload($attempt)->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM payment_webhook_inbox_events WHERE provider_event_reference LIKE 'recon_%'",
        ));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::PaymentReconciliationActionApplied->value));
    }

    public function testProviderBehindDoesNotReverseLocalCapture(): void
    {
        $attempt = $this->capturedAttempt('prs_pb');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        $this->adapter->setSnapshot($ref, $this->snapshot($attempt, PaymentProviderTransactionStatus::Authorized));

        $summary = $this->reconcile($attempt);
        self::assertSame(1, $summary->discrepancy);
        self::assertSame(PaymentAttemptStatus::Captured, $this->reload($attempt)->getStatus());
        $outcome = $this->em->getConnection()->fetchOne(
            'SELECT outcome FROM payment_reconciliation_items ORDER BY checked_at DESC LIMIT 1',
        );
        self::assertSame(PaymentReconciliationItemOutcome::ProviderBehind->value, $outcome);
    }

    public function testAmountMismatchRequiresManualReviewWithoutMutation(): void
    {
        $attempt = $this->authorizedAttempt('prs_am');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        $snap = $this->snapshot($attempt, PaymentProviderTransactionStatus::Authorized);
        $snap = new PaymentProviderTransactionSnapshot(
            providerCode: $snap->providerCode,
            environment: $snap->environment,
            providerPaymentReference: $snap->providerPaymentReference,
            providerStatus: $snap->providerStatus,
            amountMinor: $snap->amountMinor + 1,
            currency: $snap->currency,
            providerUpdatedAt: $snap->providerUpdatedAt,
            authorizedAt: $snap->authorizedAt,
            providerAuthorizationReference: $snap->providerAuthorizationReference,
        );
        $this->adapter->setSnapshot($ref, $snap);

        $summary = $this->reconcile($attempt);
        self::assertSame(1, $summary->discrepancy);
        self::assertSame(PaymentAttemptStatus::Authorized, $this->reload($attempt)->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM payment_webhook_inbox_events WHERE provider_event_reference LIKE 'recon_%'",
        ));
    }

    public function testDryRunDoesNotPersistRun(): void
    {
        $attempt = $this->authorizedAttempt('prs_dr');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        $this->adapter->setSnapshot($ref, $this->snapshot($attempt, PaymentProviderTransactionStatus::Authorized));

        $summary = $this->scenario->service(PaymentReconciliationService::class)->reconcile(
            actorId: $this->sa->getId(),
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            reasonCode: 'manual_reconciliation',
            confirm: false,
            dryRun: true,
            attemptId: $attempt->getId(),
        );
        self::assertTrue($summary->dryRun);
        self::assertSame(1, $summary->matched);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_runs'));
    }

    public function testStaleSuperAdminDenied(): void
    {
        $attempt = $this->authorizedAttempt('prs_st');
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$this->sa->getId()->toBinary()],
        );

        try {
            $this->reconcile($attempt);
            self::fail('Expected stale SA denied');
        } catch (CommerceException $e) {
            self::assertSame(CommerceFailureReason::Unauthorized, $e->getReason());
        } finally {
            $this->recoverDoctrine();
        }
    }

    public function testOperatorRevokedDuringProviderCallIsDenied(): void
    {
        $attempt = $this->authorizedAttempt('prs_toctou');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        $this->adapter->setSnapshot($ref, $this->snapshot($attempt, PaymentProviderTransactionStatus::Authorized));
        $actorId = $this->sa->getId();
        $this->adapter->setBeforeQuery(function () use ($actorId): void {
            $this->em->getConnection()->executeStatement(
                'UPDATE users SET global_roles = ? WHERE id = ?',
                [json_encode(['ROLE_USER'], \JSON_THROW_ON_ERROR), $actorId->toBinary()],
            );
        });

        try {
            $this->reconcile($attempt);
            self::fail('Expected operator revoked during provider call');
        } catch (CommerceException $e) {
            self::assertSame(CommerceFailureReason::Unauthorized, $e->getReason());
        } finally {
            $this->adapter->setBeforeQuery(null);
            $this->recoverDoctrine();
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM payment_reconciliation_items',
        ));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM payment_webhook_inbox_events WHERE provider_event_reference LIKE 'recon_%'",
        ));
    }

    public function testAttemptChangedDuringProviderCallUsesFreshState(): void
    {
        $attempt = $this->authorizedAttempt('prs_race');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        // Provider reports capture, but a concurrent local capture lands during the network call.
        $this->adapter->setSnapshot($ref, $this->snapshot(
            $attempt,
            PaymentProviderTransactionStatus::Captured,
            capturedAt: new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC')),
        ));
        $attemptId = $attempt->getId();
        $sa = $this->sa;
        $this->adapter->setBeforeQuery(function () use ($attemptId, $sa): void {
            $fresh = $this->scenario->refresh(PaymentAttempt::class, $attemptId);
            self::assertInstanceOf(PaymentAttempt::class, $fresh);
            $this->scenario->service(PaymentSettlementManager::class)->recordCaptured(
                $fresh,
                $sa,
                $fresh->getAmount(),
                $this->clock->now(),
                'prs_race-cap-000000000',
                'capture',
                $fresh->getProviderPaymentReference(),
                'prov_prs_race_evt_c',
                ['provider_code' => 'sandbox_provider', 'event_source' => 'test'],
            );
        });

        try {
            $summary = $this->reconcile($attempt);
        } finally {
            $this->adapter->setBeforeQuery(null);
        }

        self::assertSame(1, $summary->matched);
        self::assertSame(0, $summary->discrepancy);
        self::assertSame(PaymentAttemptStatus::Captured, $this->reload($attempt)->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM payment_webhook_inbox_events WHERE provider_event_reference LIKE 'recon_%'",
        ));
        $outcome = $this->em->getConnection()->fetchOne(
            'SELECT outcome FROM payment_reconciliation_items ORDER BY checked_at DESC LIMIT 1',
        );
        self::assertSame(PaymentReconciliationItemOutcome::Matched->value, $outcome);
    }

    public function testStartAuditFailureRollsBackRun(): void
    {
        $attempt = $this->authorizedAttempt('prs_aud_start');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        $this->adapter->setSnapshot($ref, $this->snapshot($attempt, PaymentProviderTransactionStatus::Authorized));

        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');
        try {
            try {
                $this->reconcile($attempt);
                self::fail('Expected audit failure');
            } catch (\Throwable) {
            }
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
            $this->recoverDoctrine();
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM payment_reconciliation_runs',
        ));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM payment_reconciliation_items',
        ));
    }

    public function testItemAuditFailureRollsBackItem(): void
    {
        $attempt = $this->authorizedAttempt('prs_aud_item');
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);
        $snap = $this->snapshot($attempt, PaymentProviderTransactionStatus::Authorized);
        $this->adapter->setSnapshot($ref, new PaymentProviderTransactionSnapshot(
            providerCode: $snap->providerCode,
            environment: $snap->environment,
            providerPaymentReference: $snap->providerPaymentReference,
            providerStatus: $snap->providerStatus,
            amountMinor: $snap->amountMinor + 1,
            currency: $snap->currency,
            providerUpdatedAt: $snap->providerUpdatedAt,
            authorizedAt: $snap->authorizedAt,
            providerAuthorizationReference: $snap->providerAuthorizationReference,
        ));
        $this->adapter->setBeforeQuery(function (): void {
            $this->em->getConnection()->executeStatement(
                'RENAME TABLE security_audit_events TO security_audit_events_bak',
            );
        });

        try {
            try {
                $this->reconcile($attempt);
                self::fail('Expected item audit failure');
            } catch (\Throwable) {
            }
        } finally {
            $this->adapter->setBeforeQuery(null);
            try {
                $this->em->getConnection()->executeStatement(
                    'RENAME TABLE security_audit_events_bak TO security_audit_events',
                );
            } catch (\Throwable) {
            }
            $this->recoverDoctrine();
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM payment_reconciliation_items',
        ));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM payment_webhook_inbox_events WHERE provider_event_reference LIKE 'recon_%'",
        ));
    }

    private function reconcile(PaymentAttempt $attempt): \App\Dto\PaymentReconciliationSummary
    {
        return $this->scenario->service(PaymentReconciliationService::class)->reconcile(
            actorId: $this->sa->getId(),
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            reasonCode: 'manual_reconciliation',
            confirm: true,
            dryRun: false,
            attemptId: $attempt->getId(),
        );
    }

    private function snapshot(
        PaymentAttempt $attempt,
        PaymentProviderTransactionStatus $status,
        ?\DateTimeImmutable $capturedAt = null,
    ): PaymentProviderTransactionSnapshot {
        $ref = $attempt->getProviderPaymentReference();
        self::assertNotNull($ref);

        return new PaymentProviderTransactionSnapshot(
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            providerPaymentReference: $ref,
            providerStatus: $status,
            amountMinor: $attempt->getAmountMinor(),
            currency: $attempt->getCurrency(),
            providerUpdatedAt: new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC')),
            authorizedAt: $attempt->getAuthorizedAt()
                ?? new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC')),
            capturedAt: $capturedAt,
            providerAuthorizationReference: $attempt->getProviderAuthorizationReference() ?? $ref.'-auth',
        );
    }

    private function authorizedAttempt(string $suffix): PaymentAttempt
    {
        [$order, $buyer] = $this->orderBuyer($suffix);
        $attempt = $this->scenario->service(PaymentAttemptManager::class)->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $suffix.'-key-0000000000000000',
            'start_payment',
        );
        $this->scenario->service(PaymentSettlementManager::class)->recordAuthorized(
            $attempt,
            $this->sa,
            $attempt->getAmount(),
            $this->clock->now(),
            $suffix.'-auth-00000000000000',
            'authorize',
            'prov_'.$suffix.'_pay',
            'prov_'.$suffix.'_auth',
            'prov_'.$suffix.'_evt_a',
            ['provider_code' => 'sandbox_provider', 'event_source' => 'test'],
        );

        return $this->reload($attempt);
    }

    private function capturedAttempt(string $suffix): PaymentAttempt
    {
        $attempt = $this->authorizedAttempt($suffix);
        $this->scenario->service(PaymentSettlementManager::class)->recordCaptured(
            $attempt,
            $this->sa,
            $attempt->getAmount(),
            $this->clock->now(),
            $suffix.'-cap-000000000000000',
            'capture',
            $attempt->getProviderPaymentReference(),
            'prov_'.$suffix.'_evt_c',
            ['provider_code' => 'sandbox_provider', 'event_source' => 'test'],
        );

        return $this->reload($attempt);
    }

    /**
     * @return array{0: \App\Entity\CommerceOrder, 1: User}
     */
    private function orderBuyer(string $suffix): array
    {
        $version = $this->scenario->activeVersion($this->sa, $suffix);
        $offer = $this->scenario->activeOffer($this->sa, $version, $suffix.'_code', 10000, 2000);
        $buyer = $this->scenario->activeUser($suffix.'-buyer@example.com');
        $order = $this->scenario->service(CommerceOrderManager::class)->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        return [$order, $buyer];
    }

    private function reload(PaymentAttempt $attempt): PaymentAttempt
    {
        $fresh = $this->scenario->refresh(PaymentAttempt::class, $attempt->getId());
        self::assertInstanceOf(PaymentAttempt::class, $fresh);

        return $fresh;
    }
}
