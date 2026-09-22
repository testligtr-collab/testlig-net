<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OutboundMailCapability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundMailCapabilityTest extends TestCase
{
    #[DataProvider('provideCapabilityCases')]
    public function testCanDeliver(string $dsn, string $appEnv, bool $expected): void
    {
        $capability = new OutboundMailCapability($dsn, $appEnv);
        self::assertSame($expected, $capability->canDeliver());
        self::assertSame('MAILER_DSN', $capability->missingConfigurationKey());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function provideCapabilityCases(): iterable
    {
        yield 'prod_null' => ['null://null', 'prod', false];
        yield 'prod_empty' => ['', 'prod', false];
        yield 'prod_smtp' => ['smtp://user:pass@smtp.example:587', 'prod', true];
        yield 'prod_ses' => ['ses+smtp://ACCESS:SECRET@default', 'prod', true];
        yield 'test_null_still_deliverable_for_message_logger' => ['null://null', 'test', true];
        yield 'dev_null' => ['null://null', 'dev', false];
        yield 'dev_mailpit' => ['smtp://mailpit:1025', 'dev', true];
    }
}
