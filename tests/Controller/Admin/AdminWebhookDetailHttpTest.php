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

final class AdminWebhookDetailHttpTest extends WebTestCase
{
    use CommerceTestFixtures;

    private const LONG_FAILURE = 'provider_timeout_insufficient_funds_retry_later';

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
        $client->request('GET', '/yonetim/webhook/'.Uuid::v7()->toRfc4122());
        self::assertResponseRedirects('/giris');
    }

    public function testAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'wh_detail_admin@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim/webhook/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'wh_detail_teacher@example.com', UserRole::Teacher);
        $client->request('GET', '/yonetim/webhook/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnverifiedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->loginHttp($client, 'wh_detail_stale_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/webhook/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuspendedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->createAndPersist('wh_detail_susp_sa@example.com', 'Guclu-Parola-123!', 'Ad', 'Min', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        $user->addGlobalRole(UserRole::SuperAdmin);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        $user->transitionTo(UserStatus::Suspended);
        $users->save($user);
        $this->submitLogin($client, 'wh_detail_susp_sa@example.com');

        $client->request('GET', '/yonetim/webhook/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownAndInvalidUuidReturn404(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'wh_detail_404_sa@example.com', UserRole::SuperAdmin);

        $client->request('GET', '/yonetim/webhook/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/yonetim/webhook/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReceivedDetailTurkishLabelsEmptyOptionalsPaymentLinkAndNoWrite(): void
    {
        $this->scenario->superAdmin('wh_detail_recv_sa@example.com');
        $event = $this->persistInbox('recv', PaymentEventType::Authorized, PaymentWebhookInboxStatus::Received);
        $id = $event->getId()->toRfc4122();
        $attemptId = $event->getPaymentAttempt()?->getId()?->toRfc4122();
        self::assertNotNull($attemptId);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'wh_detail_recv_sa@example.com');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $inboxBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events');
        $auditBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');

        $client->request('GET', '/yonetim/webhook/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Webhook detayı');
        self::assertSelectorExists('a.admin-webhook-detail__back[href="/yonetim/webhook"]');
        self::assertSelectorTextContains('.admin-webhook-detail__status', 'Alındı');
        self::assertSelectorTextContains('body', 'Yetkilendirildi');
        self::assertSelectorTextContains('body', 'Test (sandbox)');
        self::assertSelectorTextContains('.admin-webhook-detail__attempts', '0');

        $crawler = $client->getCrawler();
        $nextRetry = $crawler->filterXPath('//dt[contains(normalize-space(.),"Sonraki deneme")]/following-sibling::dd[1]')->text();
        $lease = $crawler->filterXPath('//dt[contains(normalize-space(.),"Lease bitiş")]/following-sibling::dd[1]')->text();
        $failure = $crawler->filter('.admin-webhook-detail__failure')->text();
        self::assertSame('—', trim($nextRetry));
        self::assertSame('—', trim($lease));
        self::assertSame('—', trim($failure));
        self::assertSelectorExists('a.admin-webhook-detail__payment-link[href="/yonetim/odemeler/'.$attemptId.'"]');
        self::assertSelectorNotExists('form[name="admin_webhook_dead_letter_requeue"]');
        self::assertSelectorNotExists('form[action*="yeniden-dene"]');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('payload_hash', $html);
        self::assertStringNotContainsString('signature_fingerprint', $html);
        self::assertStringNotContainsString('provider_event_reference', $html);
        self::assertStringNotContainsStringIgnoringCase('sk_live', $html);
        self::assertStringNotContainsString('wh-detail-recv-ref', $html);

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        self::assertSame($inboxBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events'));
        self::assertSame($auditBefore, (int) $emAfter->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events'));
    }

    public function testDeadLetterShowsRequeueFormProcessedRejectedHideForm(): void
    {
        $this->scenario->superAdmin('wh_detail_forms_sa@example.com');
        $dead = $this->persistInbox('dead', PaymentEventType::Failed, PaymentWebhookInboxStatus::DeadLetter);
        $processed = $this->persistInbox('proc', PaymentEventType::Captured, PaymentWebhookInboxStatus::Processed);
        $rejected = $this->persistInbox('rej', PaymentEventType::Cancelled, PaymentWebhookInboxStatus::Rejected, self::LONG_FAILURE);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'wh_detail_forms_sa@example.com');

        $client->request('GET', '/yonetim/webhook/'.$dead->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-webhook-detail__status', 'Ölü mektup');
        self::assertSelectorExists('form[name="admin_webhook_dead_letter_requeue"]');
        self::assertSelectorExists('form[action$="/yeniden-dene"]');
        self::assertSelectorExists('input[name="admin_webhook_dead_letter_requeue[_token]"]');
        self::assertSelectorTextContains('.admin-webhook-detail__requeue-note', 'doğrudan sağlayıcıya HTTP çağrısı yapmaz');
        self::assertSelectorTextContains('button.admin-webhook-detail__requeue-submit', 'Yeniden kuyruğa al');

        $client->request('GET', '/yonetim/webhook/'.$processed->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-webhook-detail__status', 'İşlendi');
        self::assertSelectorNotExists('form[name="admin_webhook_dead_letter_requeue"]');

        $client->request('GET', '/yonetim/webhook/'.$rejected->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-webhook-detail__status', 'Reddedildi');
        self::assertSelectorTextContains('.admin-webhook-detail__failure', self::LONG_FAILURE);
        self::assertSelectorNotExists('form[name="admin_webhook_dead_letter_requeue"]');
    }

    public function testStaleLeaseNoteAndDetachedPaymentAttempt(): void
    {
        $this->scenario->superAdmin('wh_detail_stale_sa2@example.com');
        $stale = $this->persistStaleLease('stale');
        $orphan = $this->persistInbox('orphan', PaymentEventType::Authorized, PaymentWebhookInboxStatus::Received);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE payment_webhook_inbox_events SET payment_attempt_id = NULL WHERE id = ?',
            [$orphan->getId()->toBinary()],
        );

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->submitLogin($client, 'wh_detail_stale_sa2@example.com');

        $client->request('GET', '/yonetim/webhook/'.$stale->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-webhook-detail__status', 'İşleniyor');
        self::assertSelectorTextContains('.admin-webhook-detail__stale', 'Kilitleme süresi doldu');
        self::assertSelectorNotExists('form[name="admin_webhook_dead_letter_requeue"]');

        $client->request('GET', '/yonetim/webhook/'.$orphan->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a.admin-webhook-detail__payment-link');
        $paymentDd = trim($client->getCrawler()->filter('section[aria-labelledby="webhook-payment-heading"] dd')->text());
        self::assertSame('—', $paymentDd);
    }

    private function persistStaleLease(string $suffix): PaymentWebhookInboxEvent
    {
        $event = $this->persistInbox($suffix, PaymentEventType::Authorized, PaymentWebhookInboxStatus::Received);
        $past = $this->clock->now()->modify('-10 minutes');
        $claim = Uuid::v7();
        $event->applyClaim($claim, $past, 60);
        $this->em->flush();

        return $event;
    }

    private function persistInbox(
        string $suffix,
        PaymentEventType $eventType,
        PaymentWebhookInboxStatus $status,
        ?string $failureCode = null,
    ): PaymentWebhookInboxEvent {
        $attempt = $this->startAttempt($suffix, PaymentProviderEnvironment::Sandbox);
        $ref = 'wh-detail-'.$suffix.'-ref';
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
            $now = $this->clock->now();
            $claim = Uuid::v7();
            $event->applyClaim($claim, $now);
            $this->em->flush();
            $reason = $failureCode ?? 'retry_exhausted';
            if (PaymentWebhookInboxStatus::DeadLetter === $status) {
                $event->markDeadLetter($reason, $now, $claim);
            } elseif (PaymentWebhookInboxStatus::Processed === $status) {
                $event->markProcessed($claim, $now);
            } elseif (PaymentWebhookInboxStatus::Rejected === $status) {
                $event->markRejected($reason, $now, $claim);
            }
            $this->em->flush();
        }

        return $event;
    }

    private function startAttempt(string $suffix, PaymentProviderEnvironment $environment): PaymentAttempt
    {
        $unique = $suffix.'_'.bin2hex(random_bytes(3));
        $sa = $this->scenario->superAdmin('wh_detail_'.$unique.'_pkg_sa@example.com');
        $version = $this->scenario->activeVersion($sa, $unique);
        $offer = $this->scenario->activeOffer($sa, $version, $unique.'_code', 12345, 0);
        $buyer = $this->scenario->activeUser('wh_detail_'.$unique.'_buyer@example.com');
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
