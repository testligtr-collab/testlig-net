<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsHttpOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertTrue(
            str_starts_with((string) $client->getResponse()->headers->get('content-type'), 'application/json')
        );
    }

    public function testHealthPayloadContainsExpectedFields(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('status', $payload);
        self::assertArrayHasKey('application', $payload);
        self::assertArrayHasKey('environment', $payload);
        self::assertArrayHasKey('checks', $payload);
        self::assertIsArray($payload['checks']);
        self::assertArrayHasKey('database', $payload['checks']);
        self::assertArrayHasKey('redis', $payload['checks']);
        self::assertSame('testlig', $payload['application']);
        self::assertSame('test', $payload['environment']);
    }

    public function testHealthPayloadDoesNotExposeSensitiveData(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        $content = (string) $client->getResponse()->getContent();
        $lower = strtolower($content);

        self::assertStringNotContainsString('password', $lower);
        self::assertStringNotContainsString('secret', $lower);
        self::assertStringNotContainsString('database_url', $lower);
        self::assertStringNotContainsString('redis_url', $lower);
        self::assertStringNotContainsString('127.0.0.1', $content);
        self::assertStringNotContainsString('localhost', $lower);
        self::assertStringNotContainsString('pdo', $lower);
        self::assertStringNotContainsString('exception', $lower);
        self::assertStringNotContainsString('\\', $content);
        self::assertStringNotContainsString('/var/', $content);
        self::assertStringNotContainsString('mysql://', $lower);
        self::assertStringNotContainsString('redis://', $lower);
    }
}
