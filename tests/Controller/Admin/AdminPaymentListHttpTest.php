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

final class AdminPaymentListHttpTest extends WebTestCase
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
        $client->request('GET', '/yonetim/odemeler');
        self::assertResponseRedirects('/giris');
    }

    public function testAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'pay_list_admin@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim/odemeler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnverifiedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->loginHttp($client, 'pay_list_stale_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/odemeler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyListCopyAndLayoutShell(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'pay_list_empty_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/odemeler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ödemeler');
        self::assertSelectorTextContains('body', 'Ödeme denemelerini salt okunur izleyin');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim/odemeler"][aria-current="page"]');
        self::assertSelectorTextContains('body', 'Ödeme denemesi yok');
        self::assertSelectorTextContains('body', 'Henüz listelenecek bir ödeme denemesi yok.');
        self::assertSelectorNotExists('button[type="submit"][name="refund"]');
        self::assertStringNotContainsStringIgnoringCase('sk_live', (string) $client->getResponse()->getContent());
    }

    public function testMixedListFiltersPaginationAndEscaping(): void
    {
        $this->scenario->superAdmin('pay_list_mix_sa@example.com');
        $initiated = $this->startAttempt('mix_init', PaymentProviderEnvironment::Sandbox);
        // List sorts createdAt DESC; advance MockClock so page_size=1 order is deterministic.
        $failedEarlier = $this->startAttempt('mix_fail_a', PaymentProviderEnvironment::Sandbox);
        $failedEarlier->markFailed('card_declined', new \DateTimeImmutable('2026-09-13 12:01:00', new \DateTimeZone('UTC')));
        $this->clock->modify('+2 minutes');
        $failedLater = $this->startAttempt('mix_fail_b', PaymentProviderEnvironment::Sandbox);
        $failedLater->markFailed('provider_timeout', new \DateTimeImmutable('2026-09-13 12:03:00', new \DateTimeZone('UTC')));
        $this->clock->modify('+1 minute');
        $live = $this->startAttempt('mix_live', PaymentProviderEnvironment::Production);
        $live->markAuthorized('pay_ref_live_1', 'auth_ref_live_1', new \DateTimeImmutable('2026-09-13 12:05:00', new \DateTimeZone('UTC')));
        $this->em->flush();

        $failedLaterRef = $failedLater->getOrder()->getPublicReference();
        $failedEarlierRef = $failedEarlier->getOrder()->getPublicReference();
        $failedLaterId = $failedLater->getId()->toRfc4122();
        $failedEarlierId = $failedEarlier->getId()->toRfc4122();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'pay_list_mix_sa@example.com');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $beforeCount = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_attempts');

        $client->request('GET', '/yonetim/odemeler');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#payment-list-heading');
        self::assertSelectorTextContains('body', 'Başlatıldı');
        self::assertSelectorTextContains('body', 'Başarısız');
        self::assertSelectorTextContains('body', 'Yetkilendirildi');
        self::assertSelectorTextContains('body', 'Test (sandbox)');
        self::assertSelectorTextContains('body', 'Canlı');
        self::assertSelectorTextContains('body', '₺');
        self::assertSelectorExists('a[href="/yonetim/odemeler/'.$initiated->getId()->toRfc4122().'"]');
        self::assertSelectorExists('label[for="payment-filter-status"]');
        self::assertSelectorExists('.admin-payment-filters');

        $client->request('GET', '/yonetim/odemeler', [
            'status' => 'failed',
            'provider' => 'sandbox_provider',
            'environment' => 'sandbox',
            'order_public_reference' => $failedLaterRef,
            'page' => 1,
            'page_size' => 25,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Başarısız');
        self::assertSelectorTextContains('body', $failedLaterRef);
        self::assertSelectorExists('a.btn[href="/yonetim/odemeler"]');
        $crawler = $client->getCrawler();
        self::assertSame('failed', $crawler->filter('#payment-filter-status')->attr('value'));
        self::assertSame('sandbox_provider', $crawler->filter('#payment-filter-provider')->attr('value'));
        self::assertSame('sandbox', $crawler->filter('#payment-filter-environment')->attr('value'));
        self::assertSelectorTextContains('.admin-pagination', '25/sayfa');
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 1 / 1');
        self::assertSelectorNotExists('.admin-pagination a');

        $client->request('GET', '/yonetim/odemeler', [
            'status' => 'failed',
            'page' => 1,
            'page_size' => 1,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', '1/sayfa');
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 1 / 2');
        self::assertSelectorTextContains('.admin-pagination', '2 kayıt');
        $next = $client->getCrawler()->filter('.admin-pagination a:contains("Sonraki")');
        self::assertCount(1, $next);
        $nextHref = (string) $next->attr('href');
        self::assertStringContainsString('status=failed', $nextHref);
        self::assertStringContainsString('page_size=1', $nextHref);
        self::assertStringContainsString('page=2', $nextHref);
        self::assertSelectorExists('a[href="/yonetim/odemeler/'.$failedLaterId.'"]');
        self::assertSelectorNotExists('a[href="/yonetim/odemeler/'.$failedEarlierId.'"]');

        $client->request('GET', $nextHref);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 2 / 2');
        self::assertSame('failed', $client->getCrawler()->filter('#payment-filter-status')->attr('value'));
        self::assertSelectorExists('a[href="/yonetim/odemeler/'.$failedEarlierId.'"]');
        self::assertSelectorNotExists('a[href="/yonetim/odemeler/'.$failedLaterId.'"]');
        self::assertSelectorTextContains('body', $failedEarlierRef);
        self::assertSelectorNotExists('.admin-pagination a:contains("Sonraki")');
        self::assertSelectorExists('.admin-pagination a:contains("Önceki")');

        $client->request('GET', '/yonetim/odemeler', [
            'status' => 'initiated',
            'order_public_reference' => 'ORD-DOESNOTEXIST000000000000',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sonuç yok');
        self::assertSelectorTextContains('body', 'Bu filtrelere uyan ödeme denemesi yok.');
        self::assertSelectorNotExists('.admin-pagination a');

        $client->request('GET', '/yonetim/odemeler', [
            'provider' => '"><script>alert(1)</script>',
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/yonetim/odemeler', ['status' => 'not_a_status']);
        self::assertResponseStatusCodeSame(404);

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        $afterCount = (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_attempts');
        self::assertSame($beforeCount, $afterCount);
        unset($live);
    }

    private function startAttempt(string $suffix, PaymentProviderEnvironment $environment): PaymentAttempt
    {
        $unique = $suffix.'_'.bin2hex(random_bytes(3));
        $sa = $this->scenario->superAdmin('pay_list_'.$unique.'_pkg_sa@example.com');
        $version = $this->scenario->activeVersion($sa, $unique);
        $offer = $this->scenario->activeOffer($sa, $version, $unique.'_code', 12345, 0);
        $buyer = $this->scenario->activeUser('pay_list_'.$unique.'_buyer@example.com');
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
