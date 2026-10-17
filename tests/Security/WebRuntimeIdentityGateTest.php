<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\WebRuntimeIdentityGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WebRuntimeIdentityGateTest extends TestCase
{
    private const NOW = '2026-10-17 12:00:00 UTC';

    public function testClosedFlagStaysClosedInsideAValidWindow(): void
    {
        $now = $this->now();
        $gate = $this->gate(false, (string) ($now + 60), $now);

        self::assertFalse($gate->isOpen());
    }

    #[DataProvider('closedExpiryProvider')]
    public function testMissingMalformedPastExactAndTooFarExpiriesStayClosed(string $expiresAt): void
    {
        $gate = $this->gate(true, $expiresAt, $this->now());

        self::assertFalse($gate->isOpen());
    }

    public function testValidWindowIsOpenAtBothEdges(): void
    {
        $now = $this->now();
        self::assertTrue($this->gate(true, (string) ($now + 1), $now)->isOpen());
        self::assertTrue($this->gate(true, (string) ($now + WebRuntimeIdentityGate::MAX_WINDOW_SECONDS), $now)->isOpen());
    }

    public function testSameGateClosesAgainAtTheExactEndInstant(): void
    {
        $now = $this->now();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $gate = new WebRuntimeIdentityGate(true, (string) ($now + 60), $clock);

        self::assertTrue($gate->isOpen());
        $clock->modify('+60 seconds');
        self::assertFalse($gate->isOpen());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function closedExpiryProvider(): iterable
    {
        $now = (new \DateTimeImmutable(self::NOW))->getTimestamp();

        yield 'missing' => [''];
        yield 'whitespace' => [' 1700000000'];
        yield 'decimal' => ['1700000000.0'];
        yield 'signed' => ['+1700000000'];
        yield 'negative' => ['-1'];
        yield 'scientific' => ['1e9'];
        yield 'leading zero' => ['01700000000'];
        yield 'letters' => ['1700000000abc'];
        yield 'past' => [(string) ($now - 1)];
        yield 'exact end' => [(string) $now];
        yield 'too far' => [(string) ($now + WebRuntimeIdentityGate::MAX_WINDOW_SECONDS + 1)];
    }

    private function gate(bool $enabled, string $expiresAt, int $now): WebRuntimeIdentityGate
    {
        return new WebRuntimeIdentityGate($enabled, $expiresAt, new MockClock('@'.$now));
    }

    private function now(): int
    {
        return (new \DateTimeImmutable(self::NOW))->getTimestamp();
    }
}
