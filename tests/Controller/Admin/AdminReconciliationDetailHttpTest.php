<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\PaymentAttempt;
use App\Entity\PaymentReconciliationItem;
use App\Entity\PaymentReconciliationRun;
use App\Entity\User;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentProviderTransactionStatus;
use App\Enum\PaymentReconciliationItemAction;
use App\Enum\PaymentReconciliationItemOutcome;
use App\Enum\PaymentReconciliationMode;
use App\Enum\PaymentReconciliationRunStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\UserFactory;
use App\Tests\Support\CommerceTestFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

final class AdminReconciliationDetailHttpTest extends WebTestCase
{
    use CommerceTestFixtures;

    private const LONG_PROVIDER = 'sandbox_provider_long_code_xx';
    private const LONG_REASON = 'ops_manual_reconciliation_long_reason_code_x';
    private const CANARY_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CANARY_TOKEN = 'recon_detail_canary_token';

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

    public function testAnonymousRedirectsToLogin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/yonetim/uzlastirma/'.Uuid::v7()->toRfc4122());
        self::assertResponseRedirects('/giris');
    }

    public function testAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'recon_detail_admin@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim/uzlastirma/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'recon_detail_teacher@example.com', UserRole::Teacher);
        $client->request('GET', '/yonetim/uzlastirma/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnverifiedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->loginHttp($client, 'recon_detail_unverified_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/uzlastirma/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuspendedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->createAndPersist('recon_detail_susp_sa@example.com', 'Guclu-Parola-123!', 'Ad', 'Min', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        $user->addGlobalRole(UserRole::SuperAdmin);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        $user->transitionTo(UserStatus::Suspended);
        $users->save($user);

        $client->loginUser($user);
        $client->request('GET', '/yonetim/uzlastirma/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownUuidNotFound(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'recon_detail_missing_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/uzlastirma/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidRunIdNotFound(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'recon_detail_badid_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/uzlastirma/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCompletedEmptyDiscrepanciesAndMatchedExcluded(): void
    {
        $this->scenario->superAdmin('recon_detail_empty_sa@example.com');
        $base = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));
        $run = $this->persistRun(
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $base,
            PaymentReconciliationRunStatus::Completed,
            'manual_reconciliation',
            2,
            2,
            0,
            0,
        );
        $attempt = $this->startAttempt('recon_empty');
        $this->recordItem(
            $run,
            $attempt,
            PaymentAttemptStatus::Captured,
            PaymentProviderTransactionStatus::Captured,
            PaymentReconciliationItemOutcome::Matched,
            PaymentReconciliationItemAction::None,
            $base->modify('+10 seconds'),
            null,
            self::CANARY_HASH,
        );
        $this->em->flush();
        $runId = $run->getId()->toRfc4122();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'recon_detail_empty_sa@example.com');
        $client->request('GET', '/yonetim/uzlastirma/'.$runId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Uzlaştırma detayı');
        self::assertSelectorExists('a.admin-reconciliation-detail__back[href="/yonetim/uzlastirma"]');
        self::assertSelectorTextContains('.admin-reconciliation-detail__status', 'Tamamlandı');
        self::assertSelectorTextContains('body', 'Manuel');
        self::assertSelectorTextContains('.admin-reconciliation-detail__environment--sandbox', 'Test (sandbox)');
        self::assertSelectorTextContains('.admin-reconciliation-detail__empty', 'Bu koşuda henüz fark kaydı bulunmuyor');
        self::assertSelectorTextContains('.admin-reconciliation-detail__empty', 'koşu durumunu dikkate alın');
        self::assertSelectorTextContains('.admin-reconciliation-detail__discrepancy-note', 'Eşleşen satırlar bu tabloda gösterilmez');
        self::assertSelectorNotExists('.admin-reconciliation-detail-table');
        self::assertDoesNotMatchRegularExpression('/\bmatched\b/', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString(self::CANARY_HASH, (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('form[action*="uzlastirma"]');
        self::assertSelectorNotExists('button[name="reconcile"]');
    }

    public function testMixedDiscrepanciesIsolationPaginationTurkishLabelsAndNoWrite(): void
    {
        $this->scenario->superAdmin('recon_detail_mix_sa@example.com');
        $base = new \DateTimeImmutable('2026-09-13 13:00:00', new \DateTimeZone('UTC'));

        $runA = $this->persistRun(
            self::LONG_PROVIDER,
            PaymentProviderEnvironment::Production,
            $base,
            PaymentReconciliationRunStatus::CompletedWithDiscrepancies,
            self::LONG_REASON,
            5,
            1,
            4,
            0,
            PaymentReconciliationMode::Scheduled,
        );
        $runB = $this->persistRun(
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $base->modify('+1 hour'),
            PaymentReconciliationRunStatus::CompletedWithDiscrepancies,
            'manual_reconciliation',
            1,
            0,
            1,
            0,
        );

        $a1 = $this->startAttempt('recon_a1');
        $a2 = $this->startAttempt('recon_a2');
        $a3 = $this->startAttempt('recon_a3');
        $a4 = $this->startAttempt('recon_a4');
        $matched = $this->startAttempt('recon_am');
        $bOnly = $this->startAttempt('recon_b1');

        $this->recordItem($runA, $matched, PaymentAttemptStatus::Captured, PaymentProviderTransactionStatus::Captured, PaymentReconciliationItemOutcome::Matched, PaymentReconciliationItemAction::None, $base->modify('+1 second'), null, self::CANARY_HASH);
        $this->recordItem($runA, $a1, PaymentAttemptStatus::Authorized, PaymentProviderTransactionStatus::Captured, PaymentReconciliationItemOutcome::LocalBehind, PaymentReconciliationItemAction::WebhookRequeued, $base->modify('+2 seconds'), 'local_behind_capture');
        $this->recordItem($runA, $a2, PaymentAttemptStatus::Captured, PaymentProviderTransactionStatus::Authorized, PaymentReconciliationItemOutcome::ProviderBehind, PaymentReconciliationItemAction::ManualReviewRequired, $base->modify('+3 seconds'), 'provider_behind');
        $this->recordItem($runA, $a3, PaymentAttemptStatus::Captured, PaymentProviderTransactionStatus::Captured, PaymentReconciliationItemOutcome::AmountMismatch, PaymentReconciliationItemAction::ManualReviewRequired, $base->modify('+4 seconds'), 'amount_mismatch');
        $this->recordItem($runA, $a4, PaymentAttemptStatus::Initiated, null, PaymentReconciliationItemOutcome::MissingAtProvider, PaymentReconciliationItemAction::None, $base->modify('+5 seconds'), 'missing_at_provider');
        $this->recordItem($runB, $bOnly, PaymentAttemptStatus::Captured, null, PaymentReconciliationItemOutcome::Unsupported, PaymentReconciliationItemAction::ManualReviewRequired, $base->modify('+6 seconds'), 'reconciliation_unsupported');
        $this->em->flush();

        $runAId = $runA->getId()->toRfc4122();
        $runBId = $runB->getId()->toRfc4122();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'recon_detail_mix_sa@example.com');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $runsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_runs');
        $itemsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_items');
        $auditBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');
        $inboxBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events');
        $runARow = $em->getConnection()->fetchAssociative(
            'SELECT status, mode, provider_code, reason_code, checked_count, matched_count, discrepancy_count, failed_count FROM payment_reconciliation_runs WHERE id = ?',
            [$runA->getId()->toBinary()],
        );
        self::assertIsArray($runARow);
        $itemFingerprintsBefore = $em->getConnection()->fetchFirstColumn(
            'SELECT CONCAT(outcome, "|", action, "|", COALESCE(reason_code, ""), "|", COALESCE(safe_snapshot_hash, "")) FROM payment_reconciliation_items WHERE run_id = ? ORDER BY checked_at ASC, id ASC',
            [$runA->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/uzlastirma/'.$runAId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-reconciliation-detail__status', 'Tutarsızlıklarla tamamlandı');
        self::assertSelectorTextContains('body', 'Zamanlanmış');
        self::assertSelectorTextContains('.admin-reconciliation-detail__environment--production', 'Canlı');
        self::assertSelectorTextContains('.admin-reconciliation-detail__provider', self::LONG_PROVIDER);
        self::assertSelectorTextContains('.admin-reconciliation-detail__reason', self::LONG_REASON);
        self::assertSelectorTextContains('body', 'Yerel geride');
        self::assertSelectorTextContains('body', 'Sağlayıcı geride');
        self::assertSelectorTextContains('body', 'Tutar uyuşmazlığı');
        self::assertSelectorTextContains('body', 'Sağlayıcıda yok');
        self::assertSelectorTextContains('body', 'Webhook yeniden kuyruğa alındı');
        self::assertSelectorTextContains('body', 'Manuel inceleme gerekli');
        self::assertSelectorTextContains('body', 'Tahsil edildi');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Desteklenmiyor', $html);
        self::assertStringNotContainsString('local_behind', $html);
        self::assertStringNotContainsString('webhook_requeued', $html);
        self::assertStringNotContainsString(self::CANARY_HASH, $html);
        self::assertStringNotContainsString(self::CANARY_TOKEN, $html);
        self::assertStringNotContainsString('safe_snapshot_hash', $html);
        self::assertStringNotContainsString('provider_payment_reference', $html);
        self::assertStringNotContainsStringIgnoringCase('sk_live', $html);
        self::assertDoesNotMatchRegularExpression('/\bmatched\b/', $html);

        $rows = $client->getCrawler()->filter('.admin-reconciliation-detail-table tbody tr');
        self::assertCount(4, $rows);
        self::assertStringContainsString('Yerel geride', $rows->eq(0)->text());
        self::assertStringContainsString('Sağlayıcı geride', $rows->eq(1)->text());
        self::assertStringContainsString('Tutar uyuşmazlığı', $rows->eq(2)->text());
        self::assertStringContainsString('Sağlayıcıda yok', $rows->eq(3)->text());
        self::assertStringContainsString('—', $rows->eq(3)->filter('.admin-reconciliation-detail__provider-state')->text());

        $client->request('GET', '/yonetim/uzlastirma/'.$runAId, ['page' => 1, 'page_size' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', '2/sayfa');
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 1 / 2');
        self::assertSelectorTextContains('.admin-pagination', '4 kayıt');
        $next = $client->getCrawler()->filter('.admin-pagination a:contains("Sonraki")');
        self::assertCount(1, $next);
        $nextHref = (string) $next->attr('href');
        self::assertStringContainsString('page_size=2', $nextHref);
        self::assertStringContainsString('page=2', $nextHref);
        self::assertStringContainsString($runAId, $nextHref);
        self::assertCount(2, $client->getCrawler()->filter('.admin-reconciliation-detail-table tbody tr'));

        $client->request('GET', $nextHref);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 2 / 2');
        self::assertCount(2, $client->getCrawler()->filter('.admin-reconciliation-detail-table tbody tr'));

        $client->request('GET', '/yonetim/uzlastirma/'.$runBId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Desteklenmiyor');
        $htmlB = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Yerel geride', $htmlB);
        self::assertStringNotContainsString(self::LONG_PROVIDER, $htmlB);

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        self::assertSame($runsBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_runs'));
        self::assertSame($itemsBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_items'));
        self::assertSame($auditBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events'));
        self::assertSame($inboxBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events'));
        $runARowAfter = $emAfter->getConnection()->fetchAssociative(
            'SELECT status, mode, provider_code, reason_code, checked_count, matched_count, discrepancy_count, failed_count FROM payment_reconciliation_runs WHERE id = ?',
            [$runA->getId()->toBinary()],
        );
        self::assertSame($runARow, $runARowAfter);
        $itemFingerprintsAfter = $emAfter->getConnection()->fetchFirstColumn(
            'SELECT CONCAT(outcome, "|", action, "|", COALESCE(reason_code, ""), "|", COALESCE(safe_snapshot_hash, "")) FROM payment_reconciliation_items WHERE run_id = ? ORDER BY checked_at ASC, id ASC',
            [$runA->getId()->toBinary()],
        );
        self::assertSame($itemFingerprintsBefore, $itemFingerprintsAfter);
    }

    public function testFailedAndRunningEmptyCopyKeepVisibleStatus(): void
    {
        $this->scenario->superAdmin('recon_detail_status_sa@example.com');
        $base = new \DateTimeImmutable('2026-09-13 14:00:00', new \DateTimeZone('UTC'));
        $failed = $this->persistRun('sandbox_provider', PaymentProviderEnvironment::Sandbox, $base, PaymentReconciliationRunStatus::Failed, 'manual_reconciliation', 1, 0, 0, 1);
        $running = $this->persistRun('sandbox_provider', PaymentProviderEnvironment::Sandbox, $base->modify('+1 minute'), PaymentReconciliationRunStatus::Running, 'manual_reconciliation', 0, 0, 0, 0);
        $this->em->flush();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'recon_detail_status_sa@example.com');

        $client->request('GET', '/yonetim/uzlastirma/'.$failed->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-reconciliation-detail__status', 'Başarısız');
        self::assertSelectorTextContains('.admin-reconciliation-detail__empty', 'Bu koşuda henüz fark kaydı bulunmuyor');
        self::assertNotSame('—', trim($client->getCrawler()->filter('dt:contains("Bitiş") + dd')->text()));

        $client->request('GET', '/yonetim/uzlastirma/'.$running->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-reconciliation-detail__status', 'Devam ediyor');
        self::assertSelectorTextContains('.admin-reconciliation-detail__empty', 'koşu durumunu dikkate alın');
        self::assertSame('—', trim($client->getCrawler()->filter('dt:contains("Bitiş") + dd')->text()));
        self::assertSelectorNotExists('form[action*="uzlastirma"]');
    }

    private function persistRun(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        \DateTimeImmutable $startedAt,
        PaymentReconciliationRunStatus $status,
        string $reasonCode,
        int $checked,
        int $matched,
        int $discrepancy,
        int $failed,
        PaymentReconciliationMode $mode = PaymentReconciliationMode::Manual,
    ): PaymentReconciliationRun {
        $run = PaymentReconciliationRun::start(
            providerCode: $providerCode,
            environment: $environment,
            mode: $mode,
            reasonCode: $reasonCode,
            startedAt: $startedAt,
            createdBy: null,
        );
        if (PaymentReconciliationRunStatus::Running !== $status) {
            $run->complete(
                $status,
                $checked,
                $matched,
                $discrepancy,
                $failed,
                $startedAt->modify('+30 seconds'),
            );
        }
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function recordItem(
        PaymentReconciliationRun $run,
        PaymentAttempt $attempt,
        PaymentAttemptStatus $expected,
        ?PaymentProviderTransactionStatus $providerState,
        PaymentReconciliationItemOutcome $outcome,
        PaymentReconciliationItemAction $action,
        \DateTimeImmutable $checkedAt,
        ?string $reasonCode,
        ?string $safeSnapshotHash = null,
    ): void {
        $item = PaymentReconciliationItem::record(
            $run,
            $attempt,
            $expected,
            $providerState,
            $outcome,
            $action,
            $safeSnapshotHash,
            $checkedAt,
            $reasonCode,
        );
        $this->em->persist($item);
    }

    private function startAttempt(string $suffix): PaymentAttempt
    {
        $unique = $suffix.'_'.bin2hex(random_bytes(3));
        $sa = $this->scenario->superAdmin('recon_detail_'.$unique.'_pkg_sa@example.com');
        $version = $this->scenario->activeVersion($sa, $unique);
        $offer = $this->scenario->activeOffer($sa, $version, $unique.'_code', 12345, 0);
        $buyer = $this->scenario->activeUser('recon_detail_'.$unique.'_buyer@example.com');
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
            $unique.'-key-0000000000000000',
            'start_payment',
        );
    }

    private function loginHttp(KernelBrowser $client, string $email, UserRole $role): User
    {
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ad', 'Min', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        $this->submitLogin($client, $email);

        return $user;
    }

    private function submitLogin(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }
}
