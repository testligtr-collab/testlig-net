<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Webhook ingress is public only on the exact POST route; neighbouring paths stay protected.
 */
final class PaymentWebhookAccessControlTest extends WebTestCase
{
    public function testSecurityConfigDeclaresExactWebhookPublicAccess(): void
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/security.yaml');
        self::assertIsArray($parsed);
        self::assertArrayHasKey('security', $parsed);

        /** @var list<array{path?: string, roles?: string|list<string>, methods?: string|list<string>}> $rules */
        $rules = $parsed['security']['access_control'] ?? [];
        self::assertNotEmpty($rules);

        $webhookRules = array_values(array_filter(
            $rules,
            static fn (array $rule): bool => str_contains((string) ($rule['path'] ?? ''), '/webhook/odeme'),
        ));
        self::assertCount(1, $webhookRules, 'Exactly one access_control rule may mention /webhook/odeme.');
        self::assertSame('^/webhook/odeme/[a-z][a-z0-9_]{1,31}$', $webhookRules[0]['path'] ?? null);

        $roles = $webhookRules[0]['roles'] ?? [];
        $roleList = \is_array($roles) ? $roles : [$roles];
        self::assertSame(['PUBLIC_ACCESS'], $roleList);

        $methods = $webhookRules[0]['methods'] ?? [];
        $methodList = \is_array($methods) ? $methods : [$methods];
        self::assertSame(['POST'], $methodList);

        foreach ($rules as $rule) {
            $path = (string) ($rule['path'] ?? '');
            if ('^/webhook/odeme/[a-z][a-z0-9_]{1,31}$' === $path) {
                continue;
            }
            if (!str_starts_with($path, '^/webhook')) {
                continue;
            }
            $roles = $rule['roles'] ?? [];
            $roleList = \is_array($roles) ? $roles : [$roles];
            self::assertNotContains(
                'PUBLIC_ACCESS',
                $roleList,
                'No broad /webhook PUBLIC_ACCESS rule is allowed besides the exact odeme POST path.',
            );
        }
    }

    public function testRouterExposesTheWebhookPostRoute(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $route = $router->getRouteCollection()->get('app_payment_webhook');
        self::assertNotNull($route);
        self::assertSame('/webhook/odeme/{providerCode}', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::ensureKernelShutdown();
    }

    public function testNonWebhookPathsStillRequireAuthentication(): void
    {
        $client = static::createClient();
        foreach (['/hesabim', '/hesabim/sifre-degistir'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('/giris', message: \sprintf('%s must stay authenticated.', $path));
        }
    }

    public function testWebhookPrefixDoesNotGrantUnrelatedPaths(): void
    {
        $client = static::createClient();

        $client->request('GET', '/webhook/odeme-extra');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('POST', '/webhook/odeme-extra');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('POST', '/webhook/other/sandbox_provider');
        self::assertTrue(
            \in_array($client->getResponse()->getStatusCode(), [404, 401, 302, 405], true),
            'Unrelated /webhook paths must not succeed anonymously.',
        );

        $client->request('GET', '/webhook/odeme/sandbox_provider');
        self::assertSame(405, $client->getResponse()->getStatusCode());
    }
}
