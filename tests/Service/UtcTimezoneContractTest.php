<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentPublication;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Tests\Support\AssessmentAttemptTestFixtures;
use App\Time\UtcInstant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Stage 2.11 merge-pre: UTC timezone contract (PHP runtime, Doctrine session, attempt clock).
 */
final class UtcTimezoneContractTest extends KernelTestCase
{
    use AssessmentAttemptTestFixtures;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();

        self::assertSame('UTC', date_default_timezone_get(), 'PHP date.timezone must be UTC (phpunit.dist.xml).');

        $conn = $this->em->getConnection();
        $sessionTz = (string) $conn->fetchOne('SELECT @@session.time_zone');
        self::assertSame('+00:00', $sessionTz, 'Doctrine session time_zone must be +00:00 after middleware/INIT_COMMAND.');

        $skew = (int) $conn->fetchOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())');
        self::assertLessThanOrEqual(1, abs($skew), 'NOW() must align with UTC_TIMESTAMP() within 1s under session UTC.');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testPhpRuntimeIsUtc(): void
    {
        self::assertSame('UTC', date_default_timezone_get());
        self::assertSame('UTC', \ini_get('date.timezone') ?: date_default_timezone_get());
    }

    public function testDoctrineSessionTimezoneIsUtcOffset(): void
    {
        $conn = $this->em->getConnection();
        self::assertSame('+00:00', (string) $conn->fetchOne('SELECT @@session.time_zone'));

        $conn->close();
        // DBAL Connection::connect() is protected; the next query reopens the session.
        self::assertSame(
            '+00:00',
            (string) $conn->fetchOne('SELECT @@session.time_zone'),
            'Reconnect must re-apply MariaDbUtcSessionTimezoneMiddleware (+00:00).',
        );
    }

    public function testNowAlignedWithUtcTimestamp(): void
    {
        $skew = (int) $this->em->getConnection()->fetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())',
        );
        self::assertLessThanOrEqual(1, abs($skew));
    }

    public function testFutureExpiresAtAllowsAnswer(): void
    {
        $fx = $this->activatedClassroomDelivery('utc_fut');
        $fixed = $this->utcSecondPrecisionNow();
        $mock = new MockClock($fixed);
        Clock::set($mock);

        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_utc_fut',
        );

        self::assertTrue($attempt->getExpiresAt() > UtcInstant::ensure($mock->now()));

        $item = $this->firstAttemptItem($attempt);
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload(),
            0,
            'save_utc_fut',
        );

        self::assertSame(1, $answer->getClientRevision());
    }

    public function testPastExpiresAtRejectsAnswerInsert(): void
    {
        $fx = $this->activatedClassroomDelivery('utc_past');
        $attemptId = Uuid::v7();
        $row = $this->validAttemptInsertRow($fx, $attemptId, 1);
        $started = (new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $expired = (new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $row['started_at'] = $started;
        $row['expires_at'] = $expired;
        $row['last_activity_at'] = $started;
        $row['created_at'] = $started;
        $row['updated_at'] = $started;
        $this->em->getConnection()->insert('assessment_attempts', $row);

        $graph = $this->loadPublicationGraph($fx['publication']);
        $itemId = Uuid::v7();
        $this->insertAttemptItemRow($attemptId, $graph, ['id' => $itemId->toBinary()]);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        try {
            $this->em->getConnection()->insert('assessment_attempt_answers', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attemptId->toBinary(),
                'attempt_item_id' => $itemId->toBinary(),
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(24),
                'encryption_version' => 1,
                'client_revision' => 1,
                'answered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected answer insert on past expires_at denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testExpiresAtBoundaryEqualsUtcTimestampRejectsAnswer(): void
    {
        $fx = $this->activatedClassroomDelivery('utc_bnd');
        $conn = $this->em->getConnection();
        $utcNow = (string) $conn->fetchOne('SELECT UTC_TIMESTAMP()');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utcNow);

        $attemptId = Uuid::v7();
        $startedAt = (new \DateTimeImmutable($utcNow, new \DateTimeZone('UTC')))->modify('-1 minute');
        $row = $this->validAttemptInsertRow(
            $fx,
            $attemptId,
            1,
            null,
            null,
            null,
            $startedAt,
            new \DateTimeImmutable($utcNow, new \DateTimeZone('UTC')),
        );
        $conn->insert('assessment_attempts', $row);

        $graph = $this->loadPublicationGraph($fx['publication']);
        $itemId = Uuid::v7();
        $this->insertAttemptItemRow($attemptId, $graph, ['id' => $itemId->toBinary()]);

        try {
            $conn->insert('assessment_attempt_answers', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attemptId->toBinary(),
                'attempt_item_id' => $itemId->toBinary(),
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(24),
                'encryption_version' => 1,
                'client_revision' => 1,
                'answered_at' => $utcNow,
                'created_at' => $utcNow,
                'updated_at' => $utcNow,
            ]);
            self::fail('Expected answer insert at expires_at = UTC_TIMESTAMP() boundary denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testClockInterfaceIstanbulNormalizesToSameUtcInstantInDb(): void
    {
        $fx = $this->activatedClassroomDelivery('utc_ist');
        $baseUtc = $this->utcSecondPrecisionNow();
        $istanbul = $baseUtc->setTimezone(new \DateTimeZone('Europe/Istanbul'));
        self::assertSame('Europe/Istanbul', $istanbul->getTimezone()->getName());
        self::assertNotSame('UTC', $istanbul->format('P'));

        Clock::set(new MockClock($istanbul));

        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $this->reloadUser($fx['student']->getId()),
            'start_utc_ist',
        );

        $startedUtc = UtcInstant::ensure($istanbul);
        $expectedStarted = $startedUtc->format('Y-m-d H:i:s');
        $durationSeconds = $fx['publication']->getAssessmentRevision()->getDurationSeconds();
        self::assertNotNull($durationSeconds);
        $candidateExpires = $startedUtc->modify(\sprintf('+%d seconds', $durationSeconds));
        $closesUtc = UtcInstant::ensure($fx['delivery']->getClosesAt());
        $expectedExpires = $candidateExpires->getTimestamp() <= $closesUtc->getTimestamp()
            ? $candidateExpires
            : $closesUtc;

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT started_at, expires_at FROM assessment_attempts WHERE id = ?',
            [$attempt->getId()->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame($expectedStarted, (string) $row['started_at']);
        self::assertSame($expectedExpires->format('Y-m-d H:i:s'), (string) $row['expires_at']);

        $this->em->clear();
        $reloaded = $this->reloadAttempt($attempt->getId());
        self::assertSame('UTC', $reloaded->getStartedAt()->getTimezone()->getName());
        self::assertSame($expectedStarted, $reloaded->getStartedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $reloaded->getExpiresAt()->getTimezone()->getName());
        self::assertSame($expectedExpires->format('Y-m-d H:i:s'), $reloaded->getExpiresAt()->format('Y-m-d H:i:s'));
    }

    public function testPositiveAndNegativeOffsetClocksPreserveInstant(): void
    {
        $unix = $this->utcSecondPrecisionNow()->getTimestamp();
        $expected = (new \DateTimeImmutable('@'.$unix))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $la = (new \DateTimeImmutable('@'.$unix))->setTimezone(new \DateTimeZone('America/Los_Angeles'));
        $akl = (new \DateTimeImmutable('@'.$unix))->setTimezone(new \DateTimeZone('Pacific/Auckland'));
        self::assertLessThan(0, $la->getOffset());
        self::assertGreaterThan(0, $akl->getOffset());

        $fxLa = $this->activatedClassroomDelivery('utc_la');
        Clock::set(new MockClock($la));
        $attemptLa = $this->attempts()->startAttempt(
            $this->reloadDelivery($fxLa['delivery']->getId()),
            $this->reloadUser($fxLa['student']->getId()),
            'start_utc_la',
        );
        $startedLa = (string) $this->em->getConnection()->fetchOne(
            'SELECT started_at FROM assessment_attempts WHERE id = ?',
            [$attemptLa->getId()->toBinary()],
        );

        Clock::set(new NativeClock());
        $fxAkl = $this->activatedClassroomDelivery('utc_akl');
        Clock::set(new MockClock($akl));
        $attemptAkl = $this->attempts()->startAttempt(
            $this->reloadDelivery($fxAkl['delivery']->getId()),
            $this->reloadUser($fxAkl['student']->getId()),
            'start_utc_akl',
        );
        $startedAkl = (string) $this->em->getConnection()->fetchOne(
            'SELECT started_at FROM assessment_attempts WHERE id = ?',
            [$attemptAkl->getId()->toBinary()],
        );

        self::assertSame($expected, $startedLa);
        self::assertSame($expected, $startedAkl);
        self::assertSame($startedLa, $startedAkl);
    }

    public function testDstTransitionTimezonePreservesInstant(): void
    {
        // September is EDT (UTC-4) for America/New_York — DST-observing zone with known offset.
        $fx = $this->activatedClassroomDelivery('utc_dst');
        $baseUtc = $this->utcSecondPrecisionNow();
        $newYork = $baseUtc->setTimezone(new \DateTimeZone('America/New_York'));
        self::assertSame('America/New_York', $newYork->getTimezone()->getName());
        self::assertSame(-4 * 3600, $newYork->getOffset(), 'Expected EDT offset in September.');

        Clock::set(new MockClock($newYork));
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $this->reloadUser($fx['student']->getId()),
            'start_utc_dst',
        );

        $expectedUtc = $baseUtc->format('Y-m-d H:i:s');
        $startedAt = (string) $this->em->getConnection()->fetchOne(
            'SELECT started_at FROM assessment_attempts WHERE id = ?',
            [$attempt->getId()->toBinary()],
        );
        self::assertSame($expectedUtc, $startedAt);
        self::assertSame($expectedUtc, UtcInstant::ensure($newYork)->format('Y-m-d H:i:s'));
    }

    public function testUserTimezonePresentationDoesNotChangeDbValue(): void
    {
        $fx = $this->activatedClassroomDelivery('utc_pres');
        $student = $this->reloadUser($fx['student']->getId());
        self::assertSame('Europe/Istanbul', $student->getTimezone());

        $fixed = $this->utcSecondPrecisionNow();
        Clock::set(new MockClock($fixed));

        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_utc_pres',
        );

        $rawBefore = (string) $this->em->getConnection()->fetchOne(
            'SELECT started_at FROM assessment_attempts WHERE id = ?',
            [$attempt->getId()->toBinary()],
        );

        $presented = UtcInstant::forPresentation($attempt->getStartedAt(), $student->getTimezone());
        $formatted = UtcInstant::formatForUser(
            $attempt->getStartedAt(),
            $student->getTimezone(),
            'Y-m-d H:i:s',
        );

        self::assertSame('Europe/Istanbul', $presented->getTimezone()->getName());
        self::assertSame($formatted, $presented->format('Y-m-d H:i:s'));
        self::assertNotSame($rawBefore, $formatted);
        self::assertSame($attempt->getStartedAt()->getTimestamp(), $presented->getTimestamp());

        $rawAfter = (string) $this->em->getConnection()->fetchOne(
            'SELECT started_at FROM assessment_attempts WHERE id = ?',
            [$attempt->getId()->toBinary()],
        );
        self::assertSame($rawBefore, $rawAfter);
        self::assertSame($fixed->format('Y-m-d H:i:s'), $rawAfter);
    }

    public function testCiFriendlyContractAssertions(): void
    {
        // CI contract documented in phpunit.dist.xml + MariaDbUtcSessionTimezoneMiddleware / doctrine INIT_COMMAND.
        self::assertSame('UTC', date_default_timezone_get(), 'CI: PHP runtime timezone');
        $tzEnv = $_ENV['TZ'] ?? $_SERVER['TZ'] ?? getenv('TZ') ?: null;
        self::assertSame('UTC', $tzEnv, 'CI: TZ env');

        $conn = $this->em->getConnection();
        self::assertSame('+00:00', (string) $conn->fetchOne('SELECT @@session.time_zone'), 'CI: session +00:00');

        $skew = (int) $conn->fetchOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())');
        self::assertLessThanOrEqual(1, abs($skew), 'CI: NOW() ≈ UTC_TIMESTAMP()');

        $ensured = UtcInstant::ensure(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')));
        self::assertSame('UTC', $ensured->getTimezone()->getName());
    }

    private function utcSecondPrecisionNow(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $truncated = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $truncated);

        return $truncated;
    }

    /**
     * @param array{
     *     owner: User,
     *     sa: User,
     *     reviewer: User,
     *     institution: \App\Entity\Institution,
     *     classroom: \App\Entity\Classroom,
     *     teacher: User,
     *     teacherMembership: InstitutionMembership,
     *     student: User,
     *     studentMembership: InstitutionMembership,
     *     assessment: \App\Entity\Assessment,
     *     publication: AssessmentPublication,
     *     delivery: AssessmentDelivery,
     *     recipient: AssessmentDeliveryRecipient
     * } $fx
     *
     * @return array<string, mixed>
     */
    private function validAttemptInsertRow(
        array $fx,
        Uuid $attemptId,
        int $attemptNumber,
        ?AssessmentDeliveryRecipient $recipient = null,
        ?User $student = null,
        ?InstitutionMembership $membership = null,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $recipient ??= $fx['recipient'];
        $student ??= $fx['student'];
        $membership ??= $fx['studentMembership'];
        $startedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt ??= $startedAt->modify('+1 hour');
        $now = $startedAt->format('Y-m-d H:i:s');

        return [
            'id' => $attemptId->toBinary(),
            'delivery_id' => $fx['delivery']->getId()->toBinary(),
            'recipient_id' => $recipient->getId()->toBinary(),
            'institution_id' => $fx['institution']->getId()->toBinary(),
            'student_membership_id' => $membership->getId()->toBinary(),
            'user_id' => $student->getId()->toBinary(),
            'assessment_id' => $fx['assessment']->getId()->toBinary(),
            'assessment_publication_id' => $fx['publication']->getId()->toBinary(),
            'assessment_revision_id' => $fx['publication']->getAssessmentRevision()->getId()->toBinary(),
            'publication_number' => $fx['publication']->getPublicationNumber(),
            'attempt_number' => $attemptNumber,
            'status' => AssessmentAttemptStatus::InProgress->value,
            'started_at' => $now,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'submitted_at' => null,
            'expired_at' => null,
            'cancelled_at' => null,
            'cancelled_by_id' => null,
            'cancellation_reason_code' => null,
            'last_activity_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array{
     *     revision_id: string,
     *     section_id: string,
     *     item_id: string,
     *     question_id: string,
     *     question_revision_id: string,
     *     section_position: int,
     *     item_position: int,
     *     points: string,
     *     penalty_points: string,
     *     required: int,
     *     public_content_hash: string,
     *     option_order_json: string
     * }
     */
    private function loadPublicationGraph(AssessmentPublication $publication): array
    {
        $revisionId = $publication->getAssessmentRevision()->getId()->toBinary();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT s.id AS section_id, s.position AS section_position,
                    i.id AS item_id, i.position AS item_position,
                    i.question_id, i.question_revision_id, i.points, i.penalty_points, i.required,
                    qr.content_hash AS public_content_hash
               FROM assessment_sections s
               INNER JOIN assessment_items i ON i.section_id = s.id
               INNER JOIN question_revisions qr ON qr.id = i.question_revision_id
              WHERE s.revision_id = ?
              ORDER BY s.position ASC, i.position ASC
              LIMIT 1',
            [$revisionId],
        );
        self::assertIsArray($row);

        return [
            'revision_id' => $revisionId,
            'section_id' => $this->blobToString($row['section_id']),
            'item_id' => $this->blobToString($row['item_id']),
            'question_id' => $this->blobToString($row['question_id']),
            'question_revision_id' => $this->blobToString($row['question_revision_id']),
            'section_position' => (int) $row['section_position'],
            'item_position' => (int) $row['item_position'],
            'points' => (string) $row['points'],
            'penalty_points' => (string) $row['penalty_points'],
            'required' => (int) $row['required'],
            'public_content_hash' => (string) $row['public_content_hash'],
            'option_order_json' => json_encode(['opt_a', 'opt_b'], \JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $overrides
     */
    private function insertAttemptItemRow(Uuid $attemptId, array $graph, array $overrides = []): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $row = array_merge([
            'id' => Uuid::v7()->toBinary(),
            'attempt_id' => $attemptId->toBinary(),
            'assessment_revision_id' => $graph['revision_id'],
            'assessment_section_id' => $graph['section_id'],
            'assessment_item_id' => $graph['item_id'],
            'question_id' => $graph['question_id'],
            'question_revision_id' => $graph['question_revision_id'],
            'section_position' => $graph['section_position'],
            'item_position' => $graph['item_position'],
            'presentation_position' => 1,
            'option_order_json' => $graph['option_order_json'],
            'required' => $graph['required'],
            'points' => $graph['points'],
            'penalty_points' => $graph['penalty_points'],
            'public_content_hash' => $graph['public_content_hash'],
            'created_at' => $now,
        ], $overrides);

        $this->em->getConnection()->insert('assessment_attempt_items', $row);
    }

    private function sqlState(\Throwable $e): ?string
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof \Doctrine\DBAL\Driver\Exception) {
                return $current->getSQLState();
            }
        }

        return null;
    }

    private function blobToString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_resource($value)) {
            $contents = stream_get_contents($value);
            self::assertNotFalse($contents);

            return $contents;
        }

        self::fail('Unexpected blob type.');
    }
}
