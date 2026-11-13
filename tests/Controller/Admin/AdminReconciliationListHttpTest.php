<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\PaymentReconciliationRun;
use App\Entity\User;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentReconciliationMode;
use App\Enum\PaymentReconciliationRunStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use App\Tests\Support\CommerceTestFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AdminReconciliationListHttpTest extends WebTestCase
{
    use CommerceTestFixtures;

    private const LONG_PROVIDER = 'sandbox_provider_long_code_xx';

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
        $client->request('GET', '/yonetim/uzlastirma');
        self::assertResponseRedirects('/giris');
    }

    public function testAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'recon_list_admin@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim/uzlastirma');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'recon_list_teacher@example.com', UserRole::Teacher);
        $client->request('GET', '/yonetim/uzlastirma');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnverifiedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->loginHttp($client, 'recon_list_unverified_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/uzlastirma');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuspendedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->createAndPersist('recon_list_susp_sa@example.com', 'Guclu-Parola-123!', 'Ad', 'Min', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        $user->addGlobalRole(UserRole::SuperAdmin);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        $user->transitionTo(UserStatus::Suspended);
        $users->save($user);

        $client->loginUser($user);
        $client->request('GET', '/yonetim/uzlastirma');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyListCopyAndLayoutShell(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'recon_list_empty_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/uzlastirma');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Uzlaştırma');
        self::assertSelectorTextContains('body', 'Önceden çalıştırılan uzlaştırma koşularının salt okunur geçmişi');
        self::assertSelectorTextContains('body', 'Bu ekrandan koşu başlatılmaz');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim/uzlastirma"][aria-current="page"]');
        self::assertSelectorTextContains('body', 'Koşu yok');
        self::assertSelectorTextContains('body', 'Henüz uzlaştırma koşusu kaydı bulunmuyor.');
        self::assertSelectorNotExists('#reconciliation-list-heading');
        self::assertSelectorNotExists('button[type="submit"]');
        self::assertStringNotContainsStringIgnoringCase('sk_live', (string) $client->getResponse()->getContent());
    }

    public function testMixedListTurkishLabelsPaginationSortingAndNoWrite(): void
    {
        $this->scenario->superAdmin('recon_list_mix_sa@example.com');
        $base = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));

        $oldest = $this->persistRun('sandbox_provider', PaymentProviderEnvironment::Sandbox, $base, PaymentReconciliationRunStatus::Completed, 2, 2, 0, 0);
        $failed = $this->persistRun('sandbox_provider', PaymentProviderEnvironment::Sandbox, $base->modify('+1 minute'), PaymentReconciliationRunStatus::Failed, 1, 0, 0, 1);
        $disc = $this->persistRun('sandbox_provider', PaymentProviderEnvironment::Sandbox, $base->modify('+2 minutes'), PaymentReconciliationRunStatus::CompletedWithDiscrepancies, 3, 1, 2, 0);
        $running = $this->persistRun('sandbox_provider', PaymentProviderEnvironment::Sandbox, $base->modify('+3 minutes'), PaymentReconciliationRunStatus::Running, 0, 0, 0, 0);
        $long = $this->persistRun(self::LONG_PROVIDER, PaymentProviderEnvironment::Sandbox, $base->modify('+4 minutes'), PaymentReconciliationRunStatus::Completed, 1, 1, 0, 0);
        $live = $this->persistRun('sandbox_provider', PaymentProviderEnvironment::Production, $base->modify('+5 minutes'), PaymentReconciliationRunStatus::Completed, 1, 1, 0, 0);

        $newestId = $live->getId()->toRfc4122();
        $oldestId = $oldest->getId()->toRfc4122();
        $longId = $long->getId()->toRfc4122();
        $runningId = $running->getId()->toRfc4122();
        $discId = $disc->getId()->toRfc4122();
        $failedId = $failed->getId()->toRfc4122();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'recon_list_mix_sa@example.com');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $runsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_runs');
        $itemsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_items');
        $auditBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');
        $inboxBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events');

        $client->request('GET', '/yonetim/uzlastirma');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#reconciliation-list-heading');
        self::assertSelectorTextContains('body', 'Tamamlandı');
        self::assertSelectorTextContains('body', 'Tutarsızlıklarla tamamlandı');
        self::assertSelectorTextContains('body', 'Başarısız');
        self::assertSelectorTextContains('body', 'Devam ediyor');
        self::assertSelectorTextContains('body', 'Test (sandbox)');
        self::assertSelectorTextContains('body', 'Canlı');
        self::assertSelectorTextContains('body', self::LONG_PROVIDER);
        self::assertSelectorExists('a[href="/yonetim/uzlastirma/'.$newestId.'"]');
        self::assertSelectorExists('a[href="/yonetim/uzlastirma/'.$longId.'"]');
        self::assertSelectorExists('a[href="/yonetim/uzlastirma/'.$runningId.'"]');
        self::assertSelectorExists('a[href="/yonetim/uzlastirma/'.$discId.'"]');
        self::assertSelectorExists('a[href="/yonetim/uzlastirma/'.$failedId.'"]');
        self::assertSelectorExists('a[href="/yonetim/uzlastirma/'.$oldestId.'"]');
        self::assertSelectorNotExists('button[type="submit"]');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('safe_snapshot_hash', $html);
        self::assertStringNotContainsString('provider_payment_reference', $html);
        self::assertStringNotContainsStringIgnoringCase('sk_live', $html);
        self::assertStringNotContainsString('recon_list_canary_token', $html);
        self::assertDoesNotMatchRegularExpression('/\bcompleted_with_discrepancies\b/', $html);
        self::assertDoesNotMatchRegularExpression('/\brunning\b/', $html);

        $detailLinks = $client->getCrawler()->filter('a[href^="/yonetim/uzlastirma/"]');
        self::assertGreaterThanOrEqual(6, $detailLinks->count());
        $firstHref = (string) $detailLinks->first()->attr('href');
        self::assertSame('/yonetim/uzlastirma/'.$newestId, $firstHref);

        $client->request('GET', '/yonetim/uzlastirma', [
            'page' => 1,
            'page_size' => 2,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', '2/sayfa');
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 1 / 3');
        self::assertSelectorTextContains('.admin-pagination', '6 kayıt');
        $next = $client->getCrawler()->filter('.admin-pagination a:contains("Sonraki")');
        self::assertCount(1, $next);
        $nextHref = (string) $next->attr('href');
        self::assertStringContainsString('page_size=2', $nextHref);
        self::assertStringContainsString('page=2', $nextHref);
        self::assertSelectorExists('a[href="/yonetim/uzlastirma/'.$newestId.'"]');
        self::assertSelectorExists('a[href="/yonetim/uzlastirma/'.$longId.'"]');
        self::assertSelectorNotExists('a[href="/yonetim/uzlastirma/'.$oldestId.'"]');

        $client->request('GET', $nextHref);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 2 / 3');
        self::assertSelectorExists('.admin-pagination a:contains("Önceki")');
        self::assertSelectorExists('.admin-pagination a:contains("Sonraki")');

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        self::assertSame($runsBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_runs'));
        self::assertSame($itemsBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_reconciliation_items'));
        self::assertSame($auditBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events'));
        self::assertSame($inboxBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events'));
    }

    private function persistRun(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        \DateTimeImmutable $startedAt,
        PaymentReconciliationRunStatus $status,
        int $checked,
        int $matched,
        int $discrepancy,
        int $failed,
    ): PaymentReconciliationRun {
        $run = PaymentReconciliationRun::start(
            providerCode: $providerCode,
            environment: $environment,
            mode: PaymentReconciliationMode::Manual,
            reasonCode: 'manual_reconciliation',
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
