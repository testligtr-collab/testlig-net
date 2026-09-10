<?php

declare(strict_types=1);

namespace App\Tests\Time;

use App\Time\UtcInstant;
use PHPUnit\Framework\TestCase;

final class UtcInstantTest extends TestCase
{
    public function testEnsureNormalizesNonUtcToUtcSameInstant(): void
    {
        $istanbul = new \DateTimeImmutable('2026-06-15 15:30:45', new \DateTimeZone('Europe/Istanbul'));
        $utc = UtcInstant::ensure($istanbul);

        self::assertSame('UTC', $utc->getTimezone()->getName());
        self::assertSame($istanbul->getTimestamp(), $utc->getTimestamp());
        self::assertSame('2026-06-15 12:30:45', $utc->format('Y-m-d H:i:s'));
    }

    public function testEnsureLeavesUtcUnchanged(): void
    {
        $source = new \DateTimeImmutable('2026-06-15 12:30:45', new \DateTimeZone('UTC'));
        $utc = UtcInstant::ensure($source);

        self::assertSame('UTC', $utc->getTimezone()->getName());
        self::assertSame('2026-06-15 12:30:45', $utc->format('Y-m-d H:i:s'));
        self::assertSame($source->getTimestamp(), $utc->getTimestamp());
    }

    public function testForPresentationShiftsWallClockWithoutChangingInstant(): void
    {
        $utc = new \DateTimeImmutable('2026-06-15 12:30:45', new \DateTimeZone('UTC'));
        $presented = UtcInstant::forPresentation($utc, 'Europe/Istanbul');

        self::assertSame('Europe/Istanbul', $presented->getTimezone()->getName());
        self::assertSame('2026-06-15 15:30:45', $presented->format('Y-m-d H:i:s'));
        self::assertSame($utc->getTimestamp(), $presented->getTimestamp());
    }

    public function testFormatForUserUsesPresentationTimezone(): void
    {
        $utc = new \DateTimeImmutable('2026-01-10 09:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            '2026-01-10 12:00:00',
            UtcInstant::formatForUser($utc, 'Europe/Istanbul', 'Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-01-10T01:00:00-08:00',
            UtcInstant::formatForUser($utc, 'America/Los_Angeles', \DateTimeInterface::ATOM),
        );
    }

    public function testPositiveAndNegativeOffsetsPreserveInstant(): void
    {
        $unix = 1_778_000_000;
        $la = (new \DateTimeImmutable('@'.$unix))->setTimezone(new \DateTimeZone('America/Los_Angeles'));
        $akl = (new \DateTimeImmutable('@'.$unix))->setTimezone(new \DateTimeZone('Pacific/Auckland'));

        $laUtc = UtcInstant::ensure($la);
        $aklUtc = UtcInstant::ensure($akl);

        self::assertSame($laUtc->format('Y-m-d H:i:s'), $aklUtc->format('Y-m-d H:i:s'));
        self::assertSame($unix, $laUtc->getTimestamp());
        self::assertSame($unix, $aklUtc->getTimestamp());
    }

    public function testZoneConstantIsUtc(): void
    {
        self::assertSame('UTC', UtcInstant::ZONE);
        self::assertSame('UTC', UtcInstant::zone()->getName());
    }
}
