<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InstitutionMembership;
use App\Exception\HomeworkDefinitionException;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * MariaDB concurrency for classroom assign + recipient snapshot.
 *
 * Shared serialization with enrollment writes: institution → year → classroom (then definition /
 * enrollments / memberships on the assign path). Membership end takes institution then membership;
 * while assign holds institution WRITE, membership end waits — so the eligibility re-check under
 * membership locks still sees a consistent cut. Snapshot validity is the set committed under those
 * locks at flush/commit time; later enrollment end does not rewrite rows.
 */
final class HomeworkAssignmentConcurrencyTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Concurrent homework assign requires MariaDB/MySQL row locks.');
        }
        $this->cleanupHomework();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        if (isset($this->em) && $this->em->isOpen()) {
            $this->cleanupHomework();
        }
        parent::tearDown();
    }

    public function testConcurrentDoubleAssignOnlyOneSucceedsWithUniqueRecipientSet(): void
    {
        $ctx = $this->homeworkContext('hwa-race-dup');
        $students = $this->enrollStudents($ctx, 2);
        $expectedUsers = $this->studentUserIds($students);
        $definition = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], dueDate: '2027-06-01', dueTime: '12:00'),
        );

        $dir = $this->tempDir('hwa_dup');
        $payload = [
            'teacher_id' => $ctx['teacher']->getId()->toRfc4122(),
            'definition_id' => $definition->getId()->toRfc4122(),
            'revision' => 1,
            'confirmed' => true,
        ];
        $results = $this->raceAssignWorkers($dir, [$payload, $payload], $ctx['classroom']->getId()->toBinary());

        $successes = (int) (!empty($results[0]['ok'])) + (int) (!empty($results[1]['ok']));
        self::assertSame(1, $successes, 'Exactly one concurrent assign must succeed; got '.json_encode($results));

        $this->em->clear();
        self::assertSame(1, $this->assignmentRowCount());
        self::assertCount(1, $this->assignmentAuditRows());
        $assignmentId = $this->em->getConnection()->fetchOne('SELECT id FROM homework_assignments');
        self::assertNotFalse($assignmentId);
        $this->assertRecipientIdentitySet(
            (string) $assignmentId,
            $expectedUsers,
            $ctx['institution']->getId(),
            $ctx['classroom']->getId(),
            $ctx['year']->getId(),
        );

        $loserReasons = [];
        foreach ($results as $row) {
            if (empty($row['ok'])) {
                $loserReasons[] = $row['reason'] ?? $row['error'] ?? null;
            }
        }
        $acceptedLoser = array_intersect($loserReasons, [
            HomeworkDefinitionException::REASON_ALREADY_ASSIGNED,
            HomeworkDefinitionException::REASON_CONFLICT,
        ]);
        self::assertNotSame(
            [],
            $acceptedLoser,
            'Loser should surface alreadyAssigned or conflict; got '.json_encode($results),
        );
    }

    public function testEndEnrollmentBeforeAssignExcludesEndedStudentDeterministically(): void
    {
        $ctx = $this->homeworkContext('hwa-end-first');
        $students = $this->enrollStudents($ctx, 3);
        $ended = $students[0];
        $kept = [$students[1], $students[2]];
        $definition = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], dueDate: '2027-06-01', dueTime: '12:00'),
        );
        $enrollment = $this->activeEnrollment($ctx['classroom'], $ended);

        $dir = $this->tempDir('hwa_end_first');
        $endDone = $dir.\DIRECTORY_SEPARATOR.'end.done';
        $endResult = $this->runEndWorker($dir, 'end', [
            'owner_id' => $ctx['owner']->getId()->toRfc4122(),
            'enrollment_id' => $enrollment->getId()->toRfc4122(),
            'done_path' => $endDone,
        ], null);
        self::assertTrue(!empty($endResult['ok']), 'end-first end worker failed: '.json_encode($endResult));
        self::assertFileExists($endDone);

        $assignResult = $this->runAssignWorker($dir, 'assign', [
            'teacher_id' => $ctx['teacher']->getId()->toRfc4122(),
            'definition_id' => $definition->getId()->toRfc4122(),
            'revision' => 1,
            'confirmed' => true,
            'after_path' => $endDone,
        ], null);
        self::assertTrue(!empty($assignResult['ok']), 'end-first assign worker failed: '.json_encode($assignResult));

        $this->em->clear();
        $assignmentId = $this->requireSingleAssignmentId();
        $this->assertRecipientIdentitySet(
            $assignmentId,
            $this->studentUserIds($kept),
            $ctx['institution']->getId(),
            $ctx['classroom']->getId(),
            $ctx['year']->getId(),
        );
        self::assertSame(0, $this->recipientCountForMembership($assignmentId, $ended->getId()));
        self::assertCount(1, $this->assignmentAuditRows());
    }

    public function testAssignBeforeEndEnrollmentKeepsEndedStudentInFrozenSnapshot(): void
    {
        $ctx = $this->homeworkContext('hwa-assign-first');
        $students = $this->enrollStudents($ctx, 3);
        $ended = $students[0];
        $definition = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], dueDate: '2027-06-01', dueTime: '12:00'),
        );
        $enrollment = $this->activeEnrollment($ctx['classroom'], $ended);

        $dir = $this->tempDir('hwa_assign_first');
        $assignDone = $dir.\DIRECTORY_SEPARATOR.'assign.done';
        $assignResult = $this->runAssignWorker($dir, 'assign', [
            'teacher_id' => $ctx['teacher']->getId()->toRfc4122(),
            'definition_id' => $definition->getId()->toRfc4122(),
            'revision' => 1,
            'confirmed' => true,
            'done_path' => $assignDone,
        ], null);
        self::assertTrue(!empty($assignResult['ok']), 'assign-first assign failed: '.json_encode($assignResult));
        self::assertFileExists($assignDone);

        $before = $this->recipientStudentUserIds($this->requireSingleAssignmentId());
        self::assertSame($this->studentUserIds($students), $before);

        $endResult = $this->runEndWorker($dir, 'end', [
            'owner_id' => $ctx['owner']->getId()->toRfc4122(),
            'enrollment_id' => $enrollment->getId()->toRfc4122(),
            'after_path' => $assignDone,
        ], null);
        self::assertTrue(!empty($endResult['ok']), 'assign-first end failed: '.json_encode($endResult));

        $this->em->clear();
        $assignmentId = $this->requireSingleAssignmentId();
        $this->assertRecipientIdentitySet(
            $assignmentId,
            $this->studentUserIds($students),
            $ctx['institution']->getId(),
            $ctx['classroom']->getId(),
            $ctx['year']->getId(),
        );
        self::assertSame(1, $this->recipientCountForMembership($assignmentId, $ended->getId()));
        self::assertSame('ended', (string) $this->em->getConnection()->fetchOne(
            'SELECT status FROM classroom_student_enrollments WHERE id = :id',
            ['id' => $enrollment->getId()->toBinary()],
        ));
        self::assertSame($before, $this->recipientStudentUserIds($assignmentId), 'post-assign end must not rewrite snapshot');
        self::assertCount(1, $this->assignmentAuditRows());
    }

    public function testConcurrentAssignAndEndEnrollmentSnapshotMatchesObservedOrder(): void
    {
        $ctx = $this->homeworkContext('hwa-race-end');
        $students = $this->enrollStudents($ctx, 3);
        $ended = $students[0];
        $kept = [$students[1], $students[2]];
        $allUsers = $this->studentUserIds($students);
        $keptUsers = $this->studentUserIds($kept);
        $definition = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], dueDate: '2027-06-01', dueTime: '12:00'),
        );
        $enrollment = $this->activeEnrollment($ctx['classroom'], $ended);

        $dir = $this->tempDir('hwa_race');
        $goPath = $dir.\DIRECTORY_SEPARATOR.'go';
        $assignReady = $dir.\DIRECTORY_SEPARATOR.'assign.ready';
        $endReady = $dir.\DIRECTORY_SEPARATOR.'end.ready';

        $pAssign = $this->startWorker(
            'homework_assign_race_worker.php',
            $dir,
            'assign',
            [
                'teacher_id' => $ctx['teacher']->getId()->toRfc4122(),
                'definition_id' => $definition->getId()->toRfc4122(),
                'revision' => 1,
                'confirmed' => true,
                'ready_path' => $assignReady,
                'go_path' => $goPath,
            ],
        );
        $pEnd = $this->startWorker(
            'homework_end_enrollment_race_worker.php',
            $dir,
            'end',
            [
                'owner_id' => $ctx['owner']->getId()->toRfc4122(),
                'enrollment_id' => $enrollment->getId()->toRfc4122(),
                'ready_path' => $endReady,
                'go_path' => $goPath,
            ],
        );

        $gate = $this->openSecondaryConnection();
        try {
            $gate->beginTransaction();
            $gate->executeStatement(
                'SELECT id FROM classrooms WHERE id = ? FOR UPDATE',
                [$ctx['classroom']->getId()->toBinary()],
            );
            $this->waitForFiles([$assignReady, $endReady], 30.0);
            file_put_contents($goPath, '1');
            // Both workers are past go and contending on the classroom row held by $gate.
            $this->waitUntilProcessesBlockedOrFinished([$pAssign, $pEnd], 5.0);
            $gate->commit();
        } finally {
            if ($gate->isTransactionActive()) {
                $gate->rollBack();
            }
            $gate->close();
        }

        $assignResult = $this->awaitWorkerResult($pAssign, $dir.\DIRECTORY_SEPARATOR.'assign.out.json');
        $endResult = $this->awaitWorkerResult($pEnd, $dir.\DIRECTORY_SEPARATOR.'end.out.json');
        self::assertTrue(!empty($assignResult['ok']), 'race assign failed: '.json_encode($assignResult));
        self::assertTrue(!empty($endResult['ok']), 'race end failed: '.json_encode($endResult));

        $this->em->clear();
        $assignmentId = $this->requireSingleAssignmentId();
        $actual = $this->recipientStudentUserIds($assignmentId);
        $endedInSnapshot = \in_array($ended->getUser()->getId()->toRfc4122(), $actual, true);

        if ($endedInSnapshot) {
            // Assign serialized before end: full eligible set frozen; later end does not rewrite.
            self::assertSame($allUsers, $actual, 'assign-first order must keep all three student ids');
            $this->assertRecipientIdentitySet(
                $assignmentId,
                $allUsers,
                $ctx['institution']->getId(),
                $ctx['classroom']->getId(),
                $ctx['year']->getId(),
            );
        } else {
            // End serialized before assign: ended student excluded; other eligibles remain.
            self::assertSame($keptUsers, $actual, 'end-first order must keep only the two still-eligible students');
            $this->assertRecipientIdentitySet(
                $assignmentId,
                $keptUsers,
                $ctx['institution']->getId(),
                $ctx['classroom']->getId(),
                $ctx['year']->getId(),
            );
        }

        self::assertSame('ended', (string) $this->em->getConnection()->fetchOne(
            'SELECT status FROM classroom_student_enrollments WHERE id = :id',
            ['id' => $enrollment->getId()->toBinary()],
        ));
        self::assertCount(1, $this->assignmentAuditRows());
        self::assertSame($actual, $this->recipientStudentUserIds($assignmentId));
    }

    /**
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<array<string, mixed>>
     */
    private function raceAssignWorkers(string $dir, array $payloads, string $classroomIdBinary): array
    {
        $goPath = $dir.\DIRECTORY_SEPARATOR.'go';
        $processes = [];
        $outPaths = [];
        $readyFiles = [];

        foreach ($payloads as $index => $payload) {
            $name = 'dup-'.$index;
            $ready = $dir.\DIRECTORY_SEPARATOR.$name.'.ready';
            $readyFiles[] = $ready;
            $payload['ready_path'] = $ready;
            $payload['go_path'] = $goPath;
            $processes[] = $this->startWorker('homework_assign_race_worker.php', $dir, $name, $payload);
            $outPaths[] = $dir.\DIRECTORY_SEPARATOR.$name.'.out.json';
        }

        $gate = $this->openSecondaryConnection();
        try {
            $gate->beginTransaction();
            $gate->executeStatement('SELECT id FROM classrooms WHERE id = ? FOR UPDATE', [$classroomIdBinary]);
            $this->waitForFiles($readyFiles, 30.0);
            file_put_contents($goPath, '1');
            $this->waitUntilProcessesBlockedOrFinished($processes, 5.0);
            $gate->commit();
        } finally {
            if ($gate->isTransactionActive()) {
                $gate->rollBack();
            }
            $gate->close();
        }

        $decoded = [];
        foreach ($processes as $index => $process) {
            $decoded[] = $this->awaitWorkerResult($process, $outPaths[$index]);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function runAssignWorker(string $dir, string $name, array $payload, ?string $classroomGateBinary): array
    {
        return $this->runOrderedWorker('homework_assign_race_worker.php', $dir, $name, $payload, $classroomGateBinary);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function runEndWorker(string $dir, string $name, array $payload, ?string $classroomGateBinary): array
    {
        return $this->runOrderedWorker('homework_end_enrollment_race_worker.php', $dir, $name, $payload, $classroomGateBinary);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function runOrderedWorker(
        string $script,
        string $dir,
        string $name,
        array $payload,
        ?string $classroomGateBinary,
    ): array {
        unset($classroomGateBinary);
        $ready = $dir.\DIRECTORY_SEPARATOR.$name.'.ready';
        $go = $dir.\DIRECTORY_SEPARATOR.$name.'.go';
        $payload['ready_path'] = $ready;
        $payload['go_path'] = $go;
        $process = $this->startWorker($script, $dir, $name, $payload);
        $this->waitForFiles([$ready], 30.0);
        file_put_contents($go, '1');

        return $this->awaitWorkerResult($process, $dir.\DIRECTORY_SEPARATOR.$name.'.out.json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startWorker(string $script, string $dir, string $name, array $payload): Process
    {
        $in = $dir.\DIRECTORY_SEPARATOR.$name.'.in.json';
        $out = $dir.\DIRECTORY_SEPARATOR.$name.'.out.json';
        file_put_contents($in, json_encode($payload, \JSON_THROW_ON_ERROR));
        $worker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.$script;
        $process = new Process([\PHP_BINARY, $worker, $in, $out], \dirname(__DIR__, 2), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
        ]);
        $process->setTimeout(90);
        $process->start();

        return $process;
    }

    /**
     * @return array<string, mixed>
     */
    private function awaitWorkerResult(Process $process, string $outPath): array
    {
        $exit = $process->wait();
        self::assertFileExists(
            $outPath,
            'worker result missing (exit='.$exit.'): '.$process->getErrorOutput().$process->getOutput(),
        );
        $raw = (string) file_get_contents($outPath);
        self::assertNotSame('', $raw, 'worker wrote empty result: '.$process->getErrorOutput());
        try {
            $row = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail('worker result is not JSON: '.$raw.' ('.$e->getMessage().')');
        }
        self::assertIsArray($row);
        self::assertArrayHasKey('ok', $row, 'worker result missing ok: '.$raw);
        if (!empty($row['ok'])) {
            self::assertSame(0, $exit, 'successful worker must exit 0; stderr='.$process->getErrorOutput());
        } else {
            self::assertNotSame(0, $exit, 'failed worker must exit non-zero; body='.$raw);
        }

        return $row;
    }

    /**
     * @param list<Process> $processes
     */
    private function waitUntilProcessesBlockedOrFinished(array $processes, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $allSettled = true;
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $allSettled = false;
                    break;
                }
            }
            if ($allSettled) {
                return;
            }
            // Brief yield so InnoDB waiters attach after go; not used to decide snapshot order.
            usleep(50_000);
        }
    }

    /**
     * @param list<string> $paths
     */
    private function waitForFiles(array $paths, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $missing = false;
            foreach ($paths as $path) {
                if (!is_file($path)) {
                    $missing = true;
                    break;
                }
            }
            if (!$missing) {
                return;
            }
            usleep(10_000);
        }
        self::fail('Workers did not signal ready in time: '.implode(', ', $paths));
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.$prefix.'_'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));

        return $dir;
    }

    private function openSecondaryConnection(): Connection
    {
        $params = $this->em->getConnection()->getParams();

        return DriverManager::getConnection($params);
    }

    private function requireSingleAssignmentId(): string
    {
        self::assertSame(1, $this->assignmentRowCount());
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM homework_assignments');
        self::assertNotFalse($id);

        return (string) $id;
    }

    /**
     * @param list<InstitutionMembership> $memberships
     *
     * @return list<string> sorted RFC4122 student user ids
     */
    private function studentUserIds(array $memberships): array
    {
        $ids = [];
        foreach ($memberships as $membership) {
            $ids[] = $membership->getUser()->getId()->toRfc4122();
        }
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string> sorted RFC4122
     */
    private function recipientStudentUserIds(string $assignmentIdBinary): array
    {
        /** @var list<string> $bins */
        $bins = $this->em->getConnection()->fetchFirstColumn(
            'SELECT student_user_id FROM homework_recipients WHERE assignment_id = :id ORDER BY student_user_id ASC',
            ['id' => $assignmentIdBinary],
        );
        $ids = [];
        foreach ($bins as $bin) {
            $ids[] = Uuid::fromBinary($bin)->toRfc4122();
        }
        sort($ids);

        return $ids;
    }

    private function recipientCountForMembership(string $assignmentIdBinary, Uuid $membershipId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_recipients WHERE assignment_id = :aid AND student_membership_id = :mid',
            ['aid' => $assignmentIdBinary, 'mid' => $membershipId->toBinary()],
        );
    }

    /**
     * @param list<string> $expectedStudentUserIds sorted RFC4122
     */
    private function assertRecipientIdentitySet(
        string $assignmentIdBinary,
        array $expectedStudentUserIds,
        Uuid $institutionId,
        Uuid $classroomId,
        Uuid $yearId,
    ): void {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT r.student_user_id AS student_user_id,
                    r.student_membership_id AS student_membership_id,
                    r.enrollment_id AS enrollment_id,
                    m.user_id AS membership_user_id,
                    m.institution_id AS membership_institution_id,
                    e.classroom_id AS enrollment_classroom_id,
                    e.academic_year_id AS enrollment_year_id,
                    a.institution_id AS assignment_institution_id,
                    a.classroom_id AS assignment_classroom_id,
                    a.academic_year_id AS assignment_year_id,
                    a.recipient_count AS recipient_count
             FROM homework_recipients r
             INNER JOIN homework_assignments a ON a.id = r.assignment_id
             INNER JOIN institution_memberships m ON m.id = r.student_membership_id
             INNER JOIN classroom_student_enrollments e ON e.id = r.enrollment_id
             WHERE r.assignment_id = :id
             ORDER BY r.student_user_id ASC',
            ['id' => $assignmentIdBinary],
        );

        self::assertCount(\count($expectedStudentUserIds), $rows, 'recipient row count');
        self::assertSame(\count($expectedStudentUserIds), (int) ($rows[0]['recipient_count'] ?? -1));

        $actualUsers = [];
        foreach ($rows as $row) {
            $studentUser = Uuid::fromBinary((string) $row['student_user_id'])->toRfc4122();
            $membershipUser = Uuid::fromBinary((string) $row['membership_user_id'])->toRfc4122();
            self::assertSame($studentUser, $membershipUser, 'recipient.user must match membership.user');
            self::assertSame($institutionId->toBinary(), $row['membership_institution_id']);
            self::assertSame($institutionId->toBinary(), $row['assignment_institution_id']);
            self::assertSame($classroomId->toBinary(), $row['enrollment_classroom_id']);
            self::assertSame($classroomId->toBinary(), $row['assignment_classroom_id']);
            self::assertSame($yearId->toBinary(), $row['enrollment_year_id']);
            self::assertSame($yearId->toBinary(), $row['assignment_year_id']);
            $actualUsers[] = $studentUser;
        }
        sort($actualUsers);
        self::assertSame($expectedStudentUserIds, $actualUsers, 'exact student identity set');
        self::assertSame(\count($actualUsers), \count(array_unique($actualUsers)), 'no duplicate students');
    }
}
