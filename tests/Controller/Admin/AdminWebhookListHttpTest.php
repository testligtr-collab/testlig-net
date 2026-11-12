<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Commerce\VerifiedPaymentWebhook;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentWebhookInboxEvent;
use App\Entity\User;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
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

final class AdminWebhookListHttpTest extends WebTestCase
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
        $client->request('GET', '/yonetim/webhook');
        self::assertResponseRedirects('/giris');
    }

    public function testAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'wh_list_admin@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim/webhook');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnverifiedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->loginHttp($client, 'wh_list_stale_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/webhook');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyDueCopyAndLayoutShell(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'wh_list_empty_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/webhook');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Webhook');
        self::assertSelectorTextContains('body', 'Ödeme webhook kuyruğunu salt okunur izleyin');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim/webhook"][aria-current="page"]');
        self::assertSelectorTextContains('body', 'İşlenecek');
        self::assertSelectorTextContains('body', 'Kuyruk boş');
        self::assertSelectorTextContains('body', 'Bu sekmede gösterilecek webhook olayı yok.');
        // Layout logout may submit; list itself must not expose requeue/write controls.
        self::assertSelectorNotExists('form[action*="yeniden-dene"]');
        self::assertSelectorNotExists('.admin-webhook-page button[type="submit"]');
        self::assertStringNotContainsStringIgnoringCase('payload_hash', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsStringIgnoringCase('signature_fingerprint', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsStringIgnoringCase('sk_live', (string) $client->getResponse()->getContent());
    }

    public function testInvalidTabReturns404(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'wh_list_bad_tab_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/webhook', ['tab' => 'not_a_tab']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMixedListTurkishLabelsPaginationNoWriteAndNoSecrets(): void
    {
        $this->scenario->superAdmin('wh_list_mix_sa@example.com');
        $received = $this->persistInbox('mix_recv', PaymentEventType::Authorized, PaymentWebhookInboxStatus::Received);
        $this->clock->modify('+1 minute');
        $refund = $this->persistInbox('mix_refund', PaymentEventType::RefundRequested, PaymentWebhookInboxStatus::Received);
        $this->clock->modify('+1 minute');
        $dead = $this->persistInbox('mix_dead', PaymentEventType::Failed, PaymentWebhookInboxStatus::DeadLetter);
        $this->clock->modify('+1 minute');
        $extraDue = $this->persistInbox('mix_due2', PaymentEventType::Captured, PaymentWebhookInboxStatus::Received);
        $this->em->flush();

        $deadId = $dead->getId()->toRfc4122();
        $refundId = $refund->getId()->toRfc4122();
        $receivedId = $received->getId()->toRfc4122();
        $extraId = $extraDue->getId()->toRfc4122();
        $deadRef = 'wh-list-mix_dead-ref';

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'wh_list_mix_sa@example.com');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $beforeCount = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events');

        $client->request('GET', '/yonetim/webhook');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#webhook-list-heading');
        self::assertSelectorTextContains('body', 'Webhook listesi');
        self::assertSelectorTextContains('body', 'Alındı');
        self::assertSelectorTextContains('body', 'İade talebi');
        self::assertSelectorTextContains('body', 'Tahsil edildi');
        self::assertSelectorTextContains('body', 'Test (sandbox)');
        self::assertSelectorExists('a[href="/yonetim/webhook/'.$receivedId.'"]');
        self::assertSelectorExists('a[href="/yonetim/webhook/'.$refundId.'"]');
        self::assertSelectorExists('.admin-webhook-tabs a[aria-current="page"]');
        self::assertSelectorTextContains('.admin-webhook-tabs a[aria-current="page"]', 'İşlenecek');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($deadRef, $html);
        self::assertStringNotContainsString('payload_hash', $html);
        self::assertStringNotContainsString('signature_fingerprint', $html);
        self::assertStringNotContainsString('provider_event_reference', $html);
        self::assertSelectorNotExists('form[action*="yeniden-dene"]');

        $client->request('GET', '/yonetim/webhook', ['tab' => 'dead_letter']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-webhook-tabs a[aria-current="page"]', 'Ölü mektup');
        self::assertSelectorTextContains('body', 'Ölü mektup');
        self::assertSelectorExists('a[href="/yonetim/webhook/'.$deadId.'"]');
        self::assertSelectorNotExists('a[href="/yonetim/webhook/'.$receivedId.'"]');
        self::assertStringNotContainsString($deadRef, (string) $client->getResponse()->getContent());

        $client->request('GET', '/yonetim/webhook', [
            'tab' => 'due',
            'page' => 1,
            'page_size' => 1,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', '1/sayfa');
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 1 /');
        $next = $client->getCrawler()->filter('.admin-pagination a:contains("Sonraki")');
        self::assertCount(1, $next);
        $nextHref = (string) $next->attr('href');
        self::assertStringContainsString('tab=due', $nextHref);
        self::assertStringContainsString('page_size=1', $nextHref);
        self::assertStringContainsString('page=2', $nextHref);
        self::assertSelectorExists('a[href="/yonetim/webhook/'.$extraId.'"]');

        $client->request('GET', $nextHref);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 2 /');
        self::assertSelectorTextContains('.admin-webhook-tabs a[aria-current="page"]', 'İşlenecek');

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        $afterCount = (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events');
        self::assertSame($beforeCount, $afterCount);
    }

    private function persistInbox(
        string $suffix,
        PaymentEventType $eventType,
        PaymentWebhookInboxStatus $status,
    ): PaymentWebhookInboxEvent {
        $attempt = $this->startAttempt($suffix, PaymentProviderEnvironment::Sandbox);
        $ref = 'wh-list-'.$suffix.'-ref';
        $verified = new VerifiedPaymentWebhook(
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            providerEventReference: $ref,
            eventType: $eventType,
            payloadHash: hash('sha256', 'body-'.$ref),
            signatureFingerprint: hash('sha256', 'sig-'.$ref),
            providerOccurredAt: new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC')),
            receivedAt: $this->clock->now(),
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

        if (PaymentWebhookInboxStatus::Received !== $status) {
            // Flush Processing first: MariaDB status-transition trigger rejects Received → terminal.
            $now = $this->clock->now();
            $claim = Uuid::v7();
            $event->applyClaim($claim, $now);
            $this->em->flush();
            if (PaymentWebhookInboxStatus::DeadLetter === $status) {
                $event->markDeadLetter('retry_exhausted', $now, $claim);
            } elseif (PaymentWebhookInboxStatus::Processed === $status) {
                $event->markProcessed($claim, $now);
            } elseif (PaymentWebhookInboxStatus::Rejected === $status) {
                $event->markRejected('invalid_signature', $now, $claim);
            }
            $this->em->flush();
        }

        return $event;
    }

    private function startAttempt(string $suffix, PaymentProviderEnvironment $environment): PaymentAttempt
    {
        $unique = $suffix.'_'.bin2hex(random_bytes(3));
        $sa = $this->scenario->superAdmin('wh_list_'.$unique.'_pkg_sa@example.com');
        $version = $this->scenario->activeVersion($sa, $unique);
        $offer = $this->scenario->activeOffer($sa, $version, $unique.'_code', 12345, 0);
        $buyer = $this->scenario->activeUser('wh_list_'.$unique.'_buyer@example.com');
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
