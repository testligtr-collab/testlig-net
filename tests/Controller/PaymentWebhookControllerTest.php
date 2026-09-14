<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Commerce\Sandbox\SandboxWebhookSignatureVerifier;
use App\Entity\CommerceOrder;
use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Service\CommerceOrderManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentPlatformSettlementActorOverride;
use App\Service\PaymentWebhookIngress;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * HTTP ingress for signed sandbox payment webhooks.
 */
final class PaymentWebhookControllerTest extends WebTestCase
{
    use CommerceTestFixtures;

    private const NOW = '2026-09-13 12:00:00';

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
            self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
            $override->setActor(null);
            $this->rebindCommerce();
            $this->cleanupCommerce();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testPostValidSignedSandboxWebhookReturns200(): void
    {
        $fixture = $this->prepareInitiatedAttempt('whc1', 10000, 2000);
        self::ensureKernelShutdown();

        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));
        $this->bindSettlementActor($fixture['saEmail']);

        $body = $this->webhookJson(
            $fixture['attempt'],
            'whc1-auth-0000000001',
            PaymentEventType::Authorized,
        );
        $this->postWebhook($client, $body);

        self::assertResponseStatusCodeSame(200);
        self::assertJson($client->getResponse()->getContent() ?: '');
        /** @var array{status?: string} $payload */
        $payload = json_decode($client->getResponse()->getContent() ?: '', true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status'] ?? null);

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindCommerce();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events'));
        self::assertSame(PaymentAttemptStatus::Authorized->value, $this->em->getConnection()->fetchOne(
            'SELECT status FROM payment_attempts WHERE id = ?',
            [$fixture['attempt']->getId()->toBinary()],
        ));
    }

    public function testWrongSignatureReturns401(): void
    {
        $fixture = $this->prepareInitiatedAttempt('whc2');
        self::ensureKernelShutdown();

        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));
        $body = $this->webhookJson($fixture['attempt'], 'whc2-auth-0000000002', PaymentEventType::Authorized);
        $this->postWebhook($client, $body, signature: 't='.(new \DateTimeImmutable(self::NOW))->getTimestamp().',v1='.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(401);
    }

    public function testMissingSignatureReturns401(): void
    {
        $fixture = $this->prepareInitiatedAttempt('whc3');
        self::ensureKernelShutdown();

        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));
        $body = $this->webhookJson($fixture['attempt'], 'whc3-auth-0000000003', PaymentEventType::Authorized);
        $ts = (new MockClock(self::NOW))->now()->getTimestamp();
        $client->request('POST', '/webhook/odeme/sandbox_provider', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TESTLIG_WEBHOOK_TIMESTAMP' => (string) $ts,
        ], $body);

        self::assertResponseStatusCodeSame(401);
    }

    public function testOldTimestampReturns401(): void
    {
        $fixture = $this->prepareInitiatedAttempt('whc4');
        self::ensureKernelShutdown();

        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));
        $body = $this->webhookJson($fixture['attempt'], 'whc4-auth-0000000004', PaymentEventType::Authorized);
        $nowTs = (new MockClock(self::NOW))->now()->getTimestamp();
        $this->postWebhook($client, $body, timestamp: $nowTs - 400);

        self::assertResponseStatusCodeSame(401);
    }

    public function testFutureTimestampReturns401(): void
    {
        $fixture = $this->prepareInitiatedAttempt('whc5');
        self::ensureKernelShutdown();

        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));
        $body = $this->webhookJson($fixture['attempt'], 'whc5-auth-0000000005', PaymentEventType::Authorized);
        $nowTs = (new MockClock(self::NOW))->now()->getTimestamp();
        $this->postWebhook($client, $body, timestamp: $nowTs + 400);

        self::assertResponseStatusCodeSame(401);
    }

    public function testBodyTamperAfterSignReturns401(): void
    {
        $fixture = $this->prepareInitiatedAttempt('whc6', 10000, 2000);
        self::ensureKernelShutdown();

        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));
        $original = $this->webhookJson($fixture['attempt'], 'whc6-auth-0000000006', PaymentEventType::Authorized);
        $ts = (new MockClock(self::NOW))->now()->getTimestamp();
        /** @var SandboxWebhookSignatureVerifier $verifier */
        $verifier = static::getContainer()->get(SandboxWebhookSignatureVerifier::class);
        $signature = $verifier->sign($original, $ts);
        $amount = $fixture['attempt']->getAmountMinor();
        $tampered = str_replace('"amount_minor":'.$amount, '"amount_minor":'.($amount - 1), $original);
        self::assertNotSame($original, $tampered);
        $this->postWebhook($client, $tampered, timestamp: $ts, signature: $signature);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownProviderReturns404(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));

        $body = '{"provider_event_reference":"whc7-unknown-00000001","event_type":"authorized","occurred_at":"2026-09-13T12:00:00+00:00","payment_attempt_id":"018f0000-0000-7000-8000-000000000001","amount_minor":12000,"currency":"TRY"}';
        $this->postWebhook($client, $body, path: '/webhook/odeme/not_registered');

        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongContentTypeReturns415(): void
    {
        $fixture = $this->prepareInitiatedAttempt('whc8');
        self::ensureKernelShutdown();

        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));
        $body = $this->webhookJson($fixture['attempt'], 'whc8-auth-0000000008', PaymentEventType::Authorized);
        $this->postWebhook($client, $body, contentType: 'text/plain');

        self::assertResponseStatusCodeSame(415);
    }

    public function testOversizedBodyReturns413(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));

        $body = '{"padding":"'.str_repeat('x', PaymentWebhookIngress::MAX_BODY_BYTES).'"}';
        $this->postWebhook($client, $body);

        self::assertResponseStatusCodeSame(413);
    }

    public function testNonPostMethodsReturn405(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $client->request($method, '/webhook/odeme/sandbox_provider');
            self::assertSame(405, $client->getResponse()->getStatusCode(), $method.' must be rejected.');
        }
    }

    public function testWebhookPrefixDoesNotOpenUnrelatedPaths(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        Clock::set(new MockClock(self::NOW));

        $client->request('POST', '/webhook/odeme-extra');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('GET', '/webhook/odeme-extra');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('POST', '/webhook/other/sandbox_provider');
        self::assertTrue(
            \in_array($client->getResponse()->getStatusCode(), [404, 401, 302, 405], true),
            'Neighbouring /webhook paths must not grant anonymous success.',
        );

        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
    }

    /**
     * @return array{order: CommerceOrder, buyer: User, attempt: PaymentAttempt, saEmail: string}
     */
    private function prepareInitiatedAttempt(string $suffix, int $price = 19999, int $taxRateBasisPoints = 2000): array
    {
        $this->bootCommerce(self::NOW);
        $saEmail = $suffix.'-sa@example.com';
        $sa = $this->scenario->superAdmin($saEmail);
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($sa);
        $version = $this->scenario->activeVersion($sa, $suffix);
        $offer = $this->scenario->activeOffer($sa, $version, $suffix.'_code', $price, $taxRateBasisPoints);
        $buyer = $this->scenario->activeUser($suffix.'-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $attempt = $this->attempts()->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $suffix.'-key-0000000000000000',
            'start_payment',
        );
        self::assertSame(PaymentAttemptStatus::Initiated, $attempt->getStatus());

        return ['order' => $order, 'buyer' => $buyer, 'attempt' => $attempt, 'saEmail' => $saEmail];
    }

    private function bindSettlementActor(string $saEmail): void
    {
        $users = static::getContainer()->get(\App\Repository\UserRepository::class);
        self::assertInstanceOf(\App\Repository\UserRepository::class, $users);
        $normalizer = static::getContainer()->get(\App\Service\EmailNormalizer::class);
        self::assertInstanceOf(\App\Service\EmailNormalizer::class, $normalizer);
        $normalized = $normalizer->normalize($saEmail);
        $sa = $users->findOneByNormalizedEmail($normalized);
        self::assertInstanceOf(User::class, $sa);
        $override = static::getContainer()->get(PaymentPlatformSettlementActorOverride::class);
        self::assertInstanceOf(PaymentPlatformSettlementActorOverride::class, $override);
        $override->setActor($sa);
    }

    private function webhookJson(
        PaymentAttempt $attempt,
        string $providerEventReference,
        PaymentEventType $eventType,
        ?int $amountMinor = null,
        ?string $currency = null,
    ): string {
        $payload = [
            'provider_event_reference' => $providerEventReference,
            'event_type' => $eventType->value,
            'occurred_at' => (new MockClock(self::NOW))->now()->format(\DateTimeInterface::ATOM),
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

    private function postWebhook(
        KernelBrowser $client,
        string $body,
        ?int $timestamp = null,
        ?string $signature = null,
        string $contentType = 'application/json',
        string $path = '/webhook/odeme/sandbox_provider',
    ): void {
        $ts = $timestamp ?? (new MockClock(self::NOW))->now()->getTimestamp();
        /** @var SandboxWebhookSignatureVerifier $verifier */
        $verifier = static::getContainer()->get(SandboxWebhookSignatureVerifier::class);
        $client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_TESTLIG_WEBHOOK_SIGNATURE' => $signature ?? $verifier->sign($body, $ts),
            'HTTP_X_TESTLIG_WEBHOOK_TIMESTAMP' => (string) $ts,
        ], $body);
    }

    private function orders(): CommerceOrderManager
    {
        return $this->scenario->service(CommerceOrderManager::class);
    }

    private function attempts(): PaymentAttemptManager
    {
        return $this->scenario->service(PaymentAttemptManager::class);
    }
}
