<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Commerce\VerifiedPaymentWebhook;
use App\Dto\AdminWebhookDeadLetterRequeueRequest;
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
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\CommerceDbCleanup;
use App\Tests\Support\CommerceScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Dead-letter requeue HTTP controls + leak scan for admin HTML.
 */
final class AdminWebhookDeadLetterRetryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private CommerceScenario $scenario;

    protected function setUp(): void
    {
        self::createClient();
        $this->rebindEm();
        CommerceDbCleanup::deleteAll($this->em->getConnection());
        AccessEntitlementDbCleanup::deleteAll($this->em->getConnection());

        $sa = $this->scenario->superAdmin('admin_dl_bootstrap_sa@example.com');
        $this->bindSettlementActor($sa);
    }

    protected function tearDown(): void
    {
        try {
            $this->rebindEm();
            $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
            self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
            $override->setActor(null);
            CommerceDbCleanup::deleteAll($this->em->getConnection());
            AccessEntitlementDbCleanup::deleteAll($this->em->getConnection());
            $this->em->getConnection()->executeStatement(
                "DELETE FROM users WHERE normalized_email LIKE 'admin_dl_%@example.com'",
            );
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function testGetRetryReturnsMethodNotAllowed(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $this->loginAs($client, 'admin_dl_get_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/webhook/'.Uuid::v7()->toRfc4122().'/yeniden-dene');
        self::assertResponseStatusCodeSame(405);
    }

    public function testRetryRequiresCsrf(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $sa = $this->loginAs($client, 'admin_dl_csrf_sa@example.com', UserRole::SuperAdmin);
        $this->bindSettlementActor($sa);
        $event = $this->persistDeadLetter('csrf');

        $client->request('POST', '/yonetim/webhook/'.$event->getId()->toRfc4122().'/yeniden-dene', [
            'admin_webhook_dead_letter_requeue' => [
                'reasonCode' => AdminWebhookDeadLetterRequeueRequest::REASON_MANUAL_REQUEUE_REVIEW,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'geçersiz');
    }

    public function testRetryRejectsInvalidReason(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $sa = $this->loginAs($client, 'admin_dl_extra_sa@example.com', UserRole::SuperAdmin);
        $this->bindSettlementActor($sa);
        $event = $this->persistDeadLetter('extra');

        $crawler = $client->request('GET', '/yonetim/webhook/'.$event->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Dead-letter yeniden dene');
        $form = $crawler->selectButton('Yeniden kuyruğa al')->form();
        $values = $form->getPhpValues();
        self::assertArrayHasKey('admin_webhook_dead_letter_requeue', $values);
        $values['admin_webhook_dead_letter_requeue']['reasonCode'] = 'not_allowed_reason';
        $values['admin_webhook_dead_letter_requeue']['confirm'] = '1';
        $client->request('POST', '/yonetim/webhook/'.$event->getId()->toRfc4122().'/yeniden-dene', $values);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'geçersiz');
    }

    public function testSuccessfulRequeueAndDuplicateSafeFlash(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $sa = $this->loginAs($client, 'admin_dl_ok_sa@example.com', UserRole::SuperAdmin);
        $this->bindSettlementActor($sa);
        $event = $this->persistDeadLetter('ok');

        $crawler = $client->request('GET', '/yonetim/webhook/'.$event->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Yeniden kuyruğa al')->form([
            'admin_webhook_dead_letter_requeue[reasonCode]' => AdminWebhookDeadLetterRequeueRequest::REASON_OPERATOR_RETRY_AFTER_FIX,
            'admin_webhook_dead_letter_requeue[confirm]' => '1',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/yonetim/webhook/'.$event->getId()->toRfc4122());
        $client->followRedirect();
        self::assertSelectorExists('.admin-flash');
        self::assertSelectorTextContains('.admin-flash', 'yeniden kuyruğa alındı');
        self::assertSelectorNotExists('.page.flash');

        $client->request('POST', '/yonetim/webhook/'.$event->getId()->toRfc4122().'/yeniden-dene', [
            'admin_webhook_dead_letter_requeue' => [
                'reasonCode' => AdminWebhookDeadLetterRequeueRequest::REASON_OPERATOR_RETRY_AFTER_FIX,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        $body = (string) $client->getResponse()->getContent();
        self::assertTrue(
            str_contains($body, 'dead-letter durumunda değil') || str_contains($body, 'geçersiz'),
            'Expected duplicate-safe flash',
        );
        self::assertSelectorExists('.admin-flash');
    }

    public function testPlainAdminCannotPostDeadLetterRetry(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $sa = $this->loginAs($client, 'admin_dl_gate_sa@example.com', UserRole::SuperAdmin);
        $this->bindSettlementActor($sa);
        $event = $this->persistDeadLetter('gate');
        $eventId = $event->getId()->toRfc4122();

        $client->restart();
        $this->loginAs($client, 'admin_dl_gate_admin@example.com', UserRole::Admin);
        $client->request('POST', '/yonetim/webhook/'.$eventId.'/yeniden-dene', [
            'admin_webhook_dead_letter_requeue' => [
                'reasonCode' => AdminWebhookDeadLetterRequeueRequest::REASON_MANUAL_REQUEUE_REVIEW,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminHtmlOmitsSecretsAndHashes(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $sa = $this->loginAs($client, 'admin_dl_leak_sa@example.com', UserRole::SuperAdmin);
        $this->bindSettlementActor($sa);
        $event = $this->persistDeadLetter('leak');
        $attempt = $event->getPaymentAttempt();

        $paths = [
            '/yonetim/webhook/'.$event->getId()->toRfc4122(),
            '/yonetim/denetim',
        ];
        if ($attempt instanceof PaymentAttempt) {
            $paths[] = '/yonetim/odemeler/'.$attempt->getId()->toRfc4122();
        }

        $needles = [
            'payloadHash',
            'payload_hash',
            'signatureFingerprint',
            'signature_fingerprint',
            'sk_live',
            'ciphertext',
            '@gmail.com',
        ];

        foreach ($paths as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful(\sprintf('%s should render', $path));
            $html = (string) $client->getResponse()->getContent();
            foreach ($needles as $needle) {
                self::assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $html,
                    \sprintf('%s must not leak %s', $path, $needle),
                );
            }
        }
    }

    public function testRateLimitAfterBurstOnSameEvent(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $sa = $this->loginAs($client, 'admin_dl_rl_sa@example.com', UserRole::SuperAdmin);
        $this->bindSettlementActor($sa);
        $event = $this->persistDeadLetter('rl');
        $path = '/yonetim/webhook/'.$event->getId()->toRfc4122().'/yeniden-dene';

        // Limiter runs before form validation — invalid CSRF still consumes the budget.
        $saw429 = false;
        for ($i = 0; $i < 6; ++$i) {
            $client->request('POST', $path, [
                'admin_webhook_dead_letter_requeue' => [
                    'reasonCode' => AdminWebhookDeadLetterRequeueRequest::REASON_MANUAL_REQUEUE_REVIEW,
                    'confirm' => '1',
                ],
            ]);
            if (429 === $client->getResponse()->getStatusCode()) {
                $saw429 = true;
                break;
            }
        }
        self::assertTrue($saw429);
    }

    private function bindSettlementActor(User $sa): void
    {
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($sa);
    }

    private function rebindEm(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->scenario = new CommerceScenario(static::getContainer(), $this->em);
    }

    private function persistDeadLetter(string $suffix): PaymentWebhookInboxEvent
    {
        $this->rebindEm();
        $attempt = $this->initiatedAttempt($suffix);
        $attemptId = $attempt->getId();
        $this->em->clear();
        $attempt = $this->em->find(PaymentAttempt::class, $attemptId);
        self::assertInstanceOf(PaymentAttempt::class, $attempt);

        $ref = 'admin-dl-'.$suffix.'-'.bin2hex(random_bytes(4));
        $verified = new VerifiedPaymentWebhook(
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
        $this->em->persist($event);
        $this->em->flush();

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
                4,
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
                4,
                'conflict',
                $bin,
            ],
        );
        $this->em->clear();

        $fresh = $this->em->find(PaymentWebhookInboxEvent::class, $event->getId());
        self::assertInstanceOf(PaymentWebhookInboxEvent::class, $fresh);
        self::assertSame(PaymentWebhookInboxStatus::DeadLetter, $fresh->getProcessingStatus());

        return $fresh;
    }

    private function initiatedAttempt(string $suffix): PaymentAttempt
    {
        $unique = $suffix.'_'.bin2hex(random_bytes(4));
        $sa = $this->scenario->superAdmin('admin_dl_'.$unique.'_pkg_sa@example.com');
        $version = $this->scenario->activeVersion($sa, $unique);
        $offer = $this->scenario->activeOffer($sa, $version, $unique.'_code', 10000, 2000);
        $buyer = $this->scenario->activeUser('admin_dl_'.$unique.'_buyer@example.com');
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

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): User
    {
        $user = $this->createPrivilegedUser($email, $role);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();

        return $user;
    }

    private function createPrivilegedUser(string $email, UserRole $role): User
    {
        $this->rebindEm();
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

        return $user;
    }
}
