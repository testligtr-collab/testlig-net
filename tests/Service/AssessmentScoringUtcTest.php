<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Tests\Support\AssessmentScoringTestFixtures;
use App\Time\UtcInstant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Stage 2.12: UTC timezone contract for scoring / result timestamps.
 */
final class AssessmentScoringUtcTest extends KernelTestCase
{
    use AssessmentScoringTestFixtures;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();

        self::assertSame('UTC', date_default_timezone_get());
        $conn = $this->em->getConnection();
        self::assertSame('+00:00', (string) $conn->fetchOne('SELECT @@session.time_zone'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testScoringTimestampsStoredAsUtcDatetime(): void
    {
        $fixed = $this->utcSecondPrecisionNow();
        Clock::set(new MockClock($fixed));

        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('asutc1');
        $expected = $fixed->format('Y-m-d H:i:s');

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT started_at, completed_at, created_at, updated_at FROM assessment_scoring_runs WHERE id = ?',
            [$run->getId()->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame($expected, (string) $row['started_at']);
        self::assertSame($expected, (string) $row['completed_at']);
        self::assertSame($expected, (string) $row['created_at']);

        $release = $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release_utc',
        );
        $releasedAt = (string) $this->em->getConnection()->fetchOne(
            'SELECT released_at FROM assessment_result_releases WHERE id = ?',
            [$release->getId()->toBinary()],
        );
        self::assertSame($expected, $releasedAt);
        unset($attempt);
    }

    public function testOffsetClockNormalizesToUtcInstant(): void
    {
        $unix = $this->utcSecondPrecisionNow()->getTimestamp();
        $expected = (new \DateTimeImmutable('@'.$unix))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $istanbul = (new \DateTimeImmutable('@'.$unix))->setTimezone(new \DateTimeZone('Europe/Istanbul'));
        self::assertNotSame('UTC', $istanbul->format('P'));

        Clock::set(new MockClock($istanbul));
        [, $run] = $this->submitAndScoreClassroomAttempt('asutc2');
        $started = (string) $this->em->getConnection()->fetchOne(
            'SELECT started_at FROM assessment_scoring_runs WHERE id = ?',
            [$run->getId()->toBinary()],
        );
        self::assertSame($expected, $started);
        self::assertSame($expected, UtcInstant::ensure($istanbul)->format('Y-m-d H:i:s'));
    }

    public function testDstObservingZonePreservesInstant(): void
    {
        $baseUtc = $this->utcSecondPrecisionNow();
        $newYork = $baseUtc->setTimezone(new \DateTimeZone('America/New_York'));
        self::assertSame(-4 * 3600, $newYork->getOffset(), 'Expected EDT offset in September.');

        Clock::set(new MockClock($newYork));
        [, $run] = $this->submitAndScoreClassroomAttempt('asutc3');
        $started = (string) $this->em->getConnection()->fetchOne(
            'SELECT started_at FROM assessment_scoring_runs WHERE id = ?',
            [$run->getId()->toBinary()],
        );
        self::assertSame($baseUtc->format('Y-m-d H:i:s'), $started);
    }

    public function testReconnectReappliesUtcSessionOffset(): void
    {
        $conn = $this->em->getConnection();
        self::assertSame('+00:00', (string) $conn->fetchOne('SELECT @@session.time_zone'));
        $conn->close();
        self::assertSame(
            '+00:00',
            (string) $conn->fetchOne('SELECT @@session.time_zone'),
            'Reconnect must re-apply MariaDbUtcSessionTimezoneMiddleware (+00:00).',
        );

        $skew = (int) $conn->fetchOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())');
        self::assertLessThanOrEqual(1, abs($skew));
    }

    private function utcSecondPrecisionNow(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $truncated = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $truncated);

        return $truncated;
    }
}
