<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\HomeworkDefinitionException;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * MariaDB concurrency: assign races against itself and against enrollment end under the
 * shared institution → year → classroom lock hierarchy.
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

    public function testConcurrentDoubleAssignOnlyOneSucceeds(): void
    {
        $ctx = $this->homeworkContext('hwa-race-dup');
        $this->enrollStudents($ctx, 2);
        // Due must stay future under wall clock in the worker processes (no MockClock there).
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
        $assignmentId = $this->em->getConnection()->fetchOne('SELECT id FROM homework_assignments');
        self::assertNotFalse($assignmentId);
        $recipientCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT recipient_count FROM homework_assignments WHERE id = :id',
            ['id' => $assignmentId],
        );
        $recipientRows = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_recipients WHERE assignment_id = :id',
            ['id' => $assignmentId],
        );
        self::assertSame(2, $recipientCount);
        self::assertSame(2, $recipientRows);
        self::assertCount(1, $this->assignmentAuditRows());

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

    public function testAssignRacingEnrollmentEndKeepsConsistentSnapshot(): void
    {
        $ctx = $this->homeworkContext('hwa-race-end');
        $students = $this->enrollStudents($ctx, 3);
        $definition = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], dueDate: '2027-06-01', dueTime: '12:00'),
        );
        $enrollment = $this->activeEnrollment($ctx['classroom'], $students[0]);

        $dir = $this->tempDir('hwa_end');
        $goPath = $dir.\DIRECTORY_SEPARATOR.'go';
        $assignReady = $dir.\DIRECTORY_SEPARATOR.'assign.ready';
        $endReady = $dir.\DIRECTORY_SEPARATOR.'end.ready';
        $assignIn = $dir.\DIRECTORY_SEPARATOR.'assign.in.json';
        $endIn = $dir.\DIRECTORY_SEPARATOR.'end.in.json';
        $assignOut = $dir.\DIRECTORY_SEPARATOR.'assign.out.json';
        $endOut = $dir.\DIRECTORY_SEPARATOR.'end.out.json';

        file_put_contents($assignIn, json_encode([
            'teacher_id' => $ctx['teacher']->getId()->toRfc4122(),
            'definition_id' => $definition->getId()->toRfc4122(),
            'revision' => 1,
            'confirmed' => true,
            'ready_path' => $assignReady,
            'go_path' => $goPath,
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($endIn, json_encode([
            'owner_id' => $ctx['owner']->getId()->toRfc4122(),
            'enrollment_id' => $enrollment->getId()->toRfc4122(),
            'ready_path' => $endReady,
            'go_path' => $goPath,
        ], \JSON_THROW_ON_ERROR));

        $assignWorker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'homework_assign_race_worker.php';
        $endWorker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'homework_end_enrollment_race_worker.php';
        $env = ['APP_ENV' => 'test', 'APP_DEBUG' => '0'];
        $pAssign = new Process([\PHP_BINARY, $assignWorker, $assignIn, $assignOut], \dirname(__DIR__, 2), $env);
        $pEnd = new Process([\PHP_BINARY, $endWorker, $endIn, $endOut], \dirname(__DIR__, 2), $env);
        $pAssign->setTimeout(90);
        $pEnd->setTimeout(90);

        // Hold the classroom row so both workers block on the shared hierarchy, then release together.
        $gate = $this->openSecondaryConnection();
        try {
            $gate->beginTransaction();
            $gate->executeStatement(
                'SELECT id FROM classrooms WHERE id = ? FOR UPDATE',
                [$ctx['classroom']->getId()->toBinary()],
            );

            $pAssign->start();
            $pEnd->start();
            $this->waitForFiles([$assignReady, $endReady], 30.0);
            // Workers are past ready and waiting on go; also waiting (or about to wait) on classroom.
            file_put_contents($goPath, '1');
            usleep(200_000);
            $gate->commit();
        } finally {
            if ($gate->isTransactionActive()) {
                $gate->rollBack();
            }
            $gate->close();
        }

        $pAssign->wait();
        $pEnd->wait();

        self::assertFileExists($assignOut, 'assign result missing: '.$pAssign->getErrorOutput());
        self::assertFileExists($endOut, 'end result missing: '.$pEnd->getErrorOutput());
        $assignResult = json_decode((string) file_get_contents($assignOut), true, 512, \JSON_THROW_ON_ERROR);
        $endResult = json_decode((string) file_get_contents($endOut), true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue(!empty($assignResult['ok']), 'Assign must succeed under race; got '.json_encode($assignResult));
        self::assertTrue(!empty($endResult['ok']), 'Enrollment end must succeed under race; got '.json_encode($endResult));

        $this->em->clear();
        self::assertSame(1, $this->assignmentRowCount());
        self::assertCount(1, $this->assignmentAuditRows());

        /** @var array{id: string, recipient_count: int|string}|false $row */
        $row = $this->em->getConnection()->fetchAssociative('SELECT id, recipient_count FROM homework_assignments');
        self::assertIsArray($row);
        $recipientRows = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_recipients WHERE assignment_id = :id',
            ['id' => $row['id']],
        );
        $recipientCount = (int) $row['recipient_count'];
        self::assertSame($recipientRows, $recipientCount, 'recipient_count must match rows after race');
        self::assertContains($recipientCount, [2, 3], 'snapshot is either pre-end or post-end cut; got '.$recipientCount);

        $endedStillRecipient = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_recipients WHERE assignment_id = :aid AND student_membership_id = :mid',
            [
                'aid' => $row['id'],
                'mid' => $students[0]->getId()->toBinary(),
            ],
        );
        if (3 === $recipientCount) {
            self::assertSame(1, $endedStillRecipient, 'pre-end snapshot keeps the later-ended student');
        } else {
            self::assertSame(0, $endedStillRecipient, 'post-end snapshot excludes the ended enrollment');
        }

        $enrollmentStatus = (string) $this->em->getConnection()->fetchOne(
            'SELECT status FROM classroom_student_enrollments WHERE id = :id',
            ['id' => $enrollment->getId()->toBinary()],
        );
        self::assertSame('ended', $enrollmentStatus);

        // Snapshot must not be rewritten after the race settles.
        self::assertSame($recipientCount, (int) $this->em->getConnection()->fetchOne(
            'SELECT recipient_count FROM homework_assignments WHERE id = :id',
            ['id' => $row['id']],
        ));
        self::assertSame($recipientRows, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_recipients WHERE assignment_id = :id',
            ['id' => $row['id']],
        ));
    }

    /**
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<array<string, mixed>>
     */
    private function raceAssignWorkers(string $dir, array $payloads, string $classroomIdBinary): array
    {
        $goPath = $dir.\DIRECTORY_SEPARATOR.'go';
        $worker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'homework_assign_race_worker.php';
        $processes = [];
        $outputs = [];
        $readyFiles = [];

        foreach ($payloads as $index => $payload) {
            $ready = $dir.\DIRECTORY_SEPARATOR.'ready-'.$index;
            $readyFiles[] = $ready;
            $in = $dir.\DIRECTORY_SEPARATOR.'in-'.$index.'.json';
            $out = $dir.\DIRECTORY_SEPARATOR.'out-'.$index.'.json';
            $payload['ready_path'] = $ready;
            $payload['go_path'] = $goPath;
            file_put_contents($in, json_encode($payload, \JSON_THROW_ON_ERROR));
            $outputs[] = $out;
            $process = new Process([\PHP_BINARY, $worker, $in, $out], \dirname(__DIR__, 2), [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '0',
            ]);
            $process->setTimeout(90);
            $processes[] = $process;
        }

        $gate = $this->openSecondaryConnection();
        try {
            $gate->beginTransaction();
            $gate->executeStatement('SELECT id FROM classrooms WHERE id = ? FOR UPDATE', [$classroomIdBinary]);

            foreach ($processes as $process) {
                $process->start();
            }
            $this->waitForFiles($readyFiles, 30.0);
            file_put_contents($goPath, '1');
            usleep(200_000);
            $gate->commit();
        } finally {
            if ($gate->isTransactionActive()) {
                $gate->rollBack();
            }
            $gate->close();
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $decoded = [];
        foreach ($outputs as $index => $out) {
            self::assertFileExists($out, $processes[$index]->getErrorOutput().$processes[$index]->getOutput());
            $row = json_decode((string) file_get_contents($out), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($row);
            $decoded[] = $row;
        }

        return $decoded;
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
}
