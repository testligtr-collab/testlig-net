<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\PaymentProviderEnvironment;
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

final class AdminPaymentDetailHttpTest extends WebTestCase
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

    public function testAnonymousRedirectsToLogin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/yonetim/odemeler/'.Uuid::v7()->toRfc4122());
        self::assertResponseRedirects('/giris');
    }

    public function testAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'pay_detail_admin@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim/odemeler/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'pay_detail_teacher@example.com', UserRole::Teacher);
        $client->request('GET', '/yonetim/odemeler/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnverifiedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->loginHttp($client, 'pay_detail_stale_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/odemeler/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuspendedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->loginHttp($client, 'pay_detail_susp_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/odemeler/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownUuidNotFound(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'pay_detail_missing_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/odemeler/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidAttemptIdNotFound(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'pay_detail_badid_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/odemeler/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuperAdminSeesInitiatedDetailWithoutOptionalDates(): void
    {
        $this->scenario->superAdmin('pay_detail_init_sa@example.com');
        $attempt = $this->startAttempt('detail_init', PaymentProviderEnvironment::Sandbox);
        $this->em->flush();
        $id = $attempt->getId()->toRfc4122();
        $ref = $attempt->getOrder()->getPublicReference();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'pay_detail_init_sa@example.com');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $beforeAttempts = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_attempts');
        $beforeOrders = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM commerce_orders');
        $beforeAudit = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');

        $client->request('GET', '/yonetim/odemeler/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ödeme detayı');
        self::assertSelectorExists('a.admin-payment-detail__back[href="/yonetim/odemeler"]');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim/odemeler"][aria-current="page"]');
        self::assertSelectorTextContains('.admin-payment-detail__status', 'Başlatıldı');
        self::assertSelectorTextContains('.admin-payment__environment--sandbox', 'Test (sandbox)');
        self::assertSelectorTextContains('.admin-payment__amount', '₺');
        self::assertSelectorTextContains('.admin-payment__reference', $ref);
        $crawler = $client->getCrawler();
        self::assertSame('—', trim($crawler->filter('dt:contains("Yetkilendirme") + dd')->text()));
        self::assertSame('—', trim($crawler->filter('dt:contains("Tahsil") + dd')->text()));
        self::assertSelectorTextContains('.admin-payment-detail__failure', '—');
        self::assertSelectorNotExists('button[type="submit"][name="refund"]');
        self::assertSelectorNotExists('form[method="post"]');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase('sk_live', $html);
        self::assertStringNotContainsString('pay_ref_secret_token', $html);
        self::assertStringNotContainsString('auth_ref_secret_token', $html);
        self::assertStringNotContainsString('|raw', $html);
        $template = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/admin/payments/detail.html.twig');
        self::assertStringNotContainsString('|raw', $template);

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        self::assertSame($beforeAttempts, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_attempts'));
        self::assertSame($beforeOrders, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM commerce_orders'));
        self::assertSame($beforeAudit, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events'));
    }

    public function testCapturedFailedCancelledLabelsAndSensitiveRefsAbsent(): void
    {
        $this->scenario->superAdmin('pay_detail_mix_sa@example.com');
        $captured = $this->startAttempt('detail_cap', PaymentProviderEnvironment::Production);
        $captured->markAuthorized('pay_ref_secret_token', 'auth_ref_secret_token', new \DateTimeImmutable('2026-09-13 12:02:00', new \DateTimeZone('UTC')));
        $captured->markCaptured('pay_ref_secret_token', new \DateTimeImmutable('2026-09-13 12:03:00', new \DateTimeZone('UTC')));
        $longFailure = 'provider_timeout_insufficient_funds_retry_later';
        $failed = $this->startAttempt('detail_fail', PaymentProviderEnvironment::Sandbox);
        $failed->markFailed($longFailure, new \DateTimeImmutable('2026-09-13 12:04:00', new \DateTimeZone('UTC')));
        $cancelled = $this->startAttempt('detail_cancel', PaymentProviderEnvironment::Sandbox);
        $cancelled->markCancelled('user_cancelled', new \DateTimeImmutable('2026-09-13 12:05:00', new \DateTimeZone('UTC')));
        $this->em->flush();

        $capturedId = $captured->getId()->toRfc4122();
        $failedId = $failed->getId()->toRfc4122();
        $cancelledId = $cancelled->getId()->toRfc4122();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'pay_detail_mix_sa@example.com');

        $client->request('GET', '/yonetim/odemeler/'.$capturedId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-payment-detail__status', 'Tahsil edildi');
        self::assertSelectorTextContains('.admin-payment__environment--production', 'Canlı');
        $htmlCap = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('pay_ref_secret_token', $htmlCap);
        self::assertStringNotContainsString('auth_ref_secret_token', $htmlCap);
        self::assertSelectorNotExists('form[method="post"]');

        $client->request('GET', '/yonetim/odemeler/'.$failedId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-payment-detail__status', 'Başarısız');
        self::assertSelectorTextContains('.admin-payment-detail__failure', $longFailure);
        self::assertSelectorTextContains('.admin-payment__environment--sandbox', 'Test (sandbox)');
        $crawler = $client->getCrawler();
        self::assertSame('—', trim($crawler->filter('dt:contains("Yetkilendirme") + dd')->text()));
        self::assertSame('—', trim($crawler->filter('dt:contains("Tahsil") + dd')->text()));
        self::assertSame($longFailure, trim($crawler->filter('.admin-payment-detail__failure')->text()));

        $client->request('GET', '/yonetim/odemeler/'.$cancelledId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-payment-detail__status', 'İptal');
        self::assertSelectorTextContains('.admin-payment-detail__failure', 'user_cancelled');
    }

    public function testAuthorizedKeepsCaptureDashAndFocusableBackLink(): void
    {
        $this->scenario->superAdmin('pay_detail_auth_sa@example.com');
        $authorized = $this->startAttempt('detail_auth', PaymentProviderEnvironment::Sandbox);
        $authorized->markAuthorized('pay_ref_auth_only', 'auth_ref_auth_only', new \DateTimeImmutable('2026-09-13 12:05:00', new \DateTimeZone('UTC')));
        $this->em->flush();
        $id = $authorized->getId()->toRfc4122();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'pay_detail_auth_sa@example.com');
        $client->request('GET', '/yonetim/odemeler/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-payment-detail__status', 'Yetkilendirildi');
        $crawler = $client->getCrawler();
        self::assertNotSame('—', trim($crawler->filter('dt:contains("Yetkilendirme") + dd')->text()));
        self::assertSame('—', trim($crawler->filter('dt:contains("Tahsil") + dd')->text()));
        self::assertSelectorExists('a.admin-payment-detail__back');
        self::assertStringNotContainsString('pay_ref_auth_only', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('auth_ref_auth_only', (string) $client->getResponse()->getContent());
    }

    private function startAttempt(string $suffix, PaymentProviderEnvironment $environment): PaymentAttempt
    {
        $unique = $suffix.'_'.bin2hex(random_bytes(3));
        $sa = $this->scenario->superAdmin('pay_detail_'.$unique.'_pkg_sa@example.com');
        $version = $this->scenario->activeVersion($sa, $unique);
        $offer = $this->scenario->activeOffer($sa, $version, $unique.'_code', 12345, 0);
        $buyer = $this->scenario->activeUser('pay_detail_'.$unique.'_buyer@example.com');
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
            $environment,
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
