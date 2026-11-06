<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\HomeworkAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Exception\HomeworkEvaluationException;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * MariaDB concurrency for teacher evaluation (first evaluation and corrections).
 *
 * Every worker takes institution → year → classroom → assignment → recipient → submission locks
 * (HomeworkEvaluationManager). The test holds the classroom row FOR UPDATE on a separate
 * connection until all workers sit at the barrier, so the workers genuinely contend; the winner
 * is whichever gets the institution lock first. Assertions therefore describe invariants that
 * hold in either order, and always compare the stored feedback with the feedback the winning
 * worker reported (never with a fixed expected winner).
 *
 * A worker result is a success only when it says "ok": true. A barrier timeout, a missing or empty
 * result and an unknown loser reason all fail the test instead of counting as a lost race.
 *
 * Workers use the real clock; homework is due 2099-06-01 12:00 Europe/Istanbul.
 */
final class HomeworkEvaluationConcurrencyTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    private const LOSER_REASONS = [
        HomeworkEvaluationException::REASON_STALE,
        HomeworkEvaluationException::REASON_CONFLICT,
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Concurrent homework evaluation requires MariaDB/MySQL row locks.');
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

    public function testConcurrentFirstEvaluationHasOneWinnerOneCurrentPointerAndOneSuccessAudit(): void
    {
        $s = $this->scenario('hec-first');
        $id = $s['submission']->getId();
        $a = $this->payload($s['ctx']['teacher'], $id, 'reviewed', 'Birinci sekmenin geri bildirimi', null);
        $b = $this->payload($s['ctx']['teacher'], $id, 'needs_rework', 'İkinci sekmenin geri bildirimi', null);

        $results = $this->raceWorkers([$a, $b], $s['ctx']['classroom']->getId()->toBinary());

        self::assertSame(1, $this->successCount($results), 'Exactly one first evaluation must win; got '.json_encode($results));
        $winner = $this->winner($results);
        $loser = $this->losers($results)[0];
        $this->assertKnownLoser($loser, $results);

        $this->em->clear();
        $rows = $this->evaluationRows($id);
        self::assertCount(1, $rows, 'one evaluation row');
        self::assertSame($winner['evaluation_id'], $rows[0]['id']);
        self::assertSame($winner['evaluation_id'], $this->currentEvaluationId($id), 'one current pointer = the winner');
        self::assertNull($rows[0]['supersedes']);
        self::assertContains($rows[0]['feedback'], ['Birinci sekmenin geri bildirimi', 'İkinci sekmenin geri bildirimi']);
        self::assertSame($winner['feedback'], $rows[0]['feedback'], 'stored feedback is the winning worker\'s feedback');
        self::assertSame($winner['label'], $rows[0]['label']);
        $loserPayload = $a['feedback'] === $winner['feedback'] ? $b : $a;
        self::assertNotSame($loserPayload['feedback'], $rows[0]['feedback'], 'the losing tab must not leak into storage');

        $audit = $this->evaluationAuditRows();
        self::assertCount(1, $audit, 'one success audit for the one winner');
        $metadata = json_decode($audit[0]['metadata'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame($winner['evaluation_id'], $metadata['resource_id']);
    }

    public function testTwoCorrectionsWithTheSameExpectedEvaluationIdHaveOneWinner(): void
    {
        $s = $this->scenario('hec-corr');
        $id = $s['submission']->getId();
        $first = $this->homeworkEvaluationManager()->record($s['ctx']['teacher'], $id, 'needs_rework', 'İlk değerlendirme', null);
        $firstId = $first->getId();
        $baseline = $this->evaluationRows($id);
        $a = $this->payload($s['ctx']['teacher'], $id, 'reviewed', 'Düzeltme A', $firstId);
        $b = $this->payload($s['ctx']['teacher'], $id, 'needs_rework', 'Düzeltme B', $firstId);

        $results = $this->raceWorkers([$a, $b], $s['ctx']['classroom']->getId()->toBinary());

        self::assertSame(1, $this->successCount($results), 'Exactly one correction must win; got '.json_encode($results));
        $winner = $this->winner($results);
        $this->assertKnownLoser($this->losers($results)[0], $results);
        self::assertSame($firstId->toRfc4122(), $winner['supersedes_evaluation_id']);
        self::assertNotSame($firstId->toRfc4122(), $winner['evaluation_id']);

        $this->em->clear();
        $rows = $this->evaluationRows($id);
        self::assertCount(2, $rows, 'first evaluation + exactly one correction');
        self::assertSame($baseline[0], $rows[0], 'the superseded evaluation is unchanged by the race');
        self::assertSame($winner['evaluation_id'], $rows[1]['id']);
        self::assertSame($firstId->toRfc4122(), $rows[1]['supersedes']);
        self::assertContains($rows[1]['feedback'], ['Düzeltme A', 'Düzeltme B']);
        self::assertSame($winner['feedback'], $rows[1]['feedback']);
        self::assertSame($winner['label'], $rows[1]['label']);
        self::assertSame($winner['evaluation_id'], $this->currentEvaluationId($id));
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM homework_evaluations WHERE supersedes_evaluation_id = :id',
                ['id' => $firstId->toBinary()],
            ),
            'a superseded evaluation has exactly one successor',
        );
        self::assertCount(2, $this->evaluationAuditRows(), 'first evaluation + the winning correction');
    }

    public function testEvaluationThenLateStaleWorkerKeepsTheFirstFeedbackDeterministically(): void
    {
        $s = $this->scenario('hec-order');
        $id = $s['submission']->getId();
        $dir = $this->tempDir('hec_order');
        $doneFile = $dir.\DIRECTORY_SEPARATOR.'first.done';

        $first = $this->runOrderedWorker($dir, 'first', $this->payload($s['ctx']['teacher'], $id, 'reviewed', 'İlk kaydeden kazanır', null, ['done_path' => $doneFile]));
        self::assertTrue(!empty($first['ok']), 'first worker failed: '.json_encode($first));
        self::assertFileExists($doneFile);

        $late = $this->runOrderedWorker($dir, 'late', $this->payload($s['ctx']['teacher'], $id, 'needs_rework', 'Geç gelen eski sekme', null, ['after_path' => $doneFile]));
        self::assertFalse(!empty($late['ok']), 'late stale worker must fail: '.json_encode($late));
        self::assertSame(HomeworkEvaluationException::REASON_STALE, $late['reason'] ?? null, (string) json_encode($late));

        $this->em->clear();
        $rows = $this->evaluationRows($id);
        self::assertCount(1, $rows);
        self::assertSame($first['evaluation_id'], $rows[0]['id']);
        self::assertSame('İlk kaydeden kazanır', $rows[0]['feedback']);
        self::assertSame('reviewed', $rows[0]['label']);
        self::assertSame($first['evaluation_id'], $this->currentEvaluationId($id));
        self::assertCount(1, $this->evaluationAuditRows());
    }

    public function testEvaluationsOfDifferentSubmissionsBothSucceedIndependently(): void
    {
        $s = $this->scenario('hec-two', 2);
        $first = $s['submission'];
        $second = $this->studentSubmit($s['users'][1], $s['assignment'], 'İkinci öğrenci');
        $a = $this->payload($s['ctx']['teacher'], $first->getId(), 'reviewed', 'Birinci öğrenciye', null);
        $b = $this->payload($s['ctx']['teacher'], $second->getId(), 'needs_rework', 'İkinci öğrenciye', null);

        $results = $this->raceWorkers([$a, $b], $s['ctx']['classroom']->getId()->toBinary());

        self::assertSame(2, $this->successCount($results), 'got '.json_encode($results));
        $this->em->clear();
        self::assertSame('Birinci öğrenciye', $this->evaluationRows($first->getId())[0]['feedback']);
        self::assertSame('İkinci öğrenciye', $this->evaluationRows($second->getId())[0]['feedback']);
        self::assertNotNull($this->currentEvaluationId($first->getId()));
        self::assertNotNull($this->currentEvaluationId($second->getId()));
        self::assertNotSame($this->currentEvaluationId($first->getId()), $this->currentEvaluationId($second->getId()));
        self::assertCount(2, $this->evaluationAuditRows());
    }

    public function testWorkerBarrierTimeoutIsAFailureNotASuccessAndWritesNothing(): void
    {
        $s = $this->scenario('hec-timeout');
        $id = $s['submission']->getId();
        $dir = $this->tempDir('hec_timeout');
        $ready = $dir.\DIRECTORY_SEPARATOR.'t.ready';
        // The go file is never created: the worker must give up and report a failure.
        $process = $this->startWorker($dir, 't', $this->payload($s['ctx']['teacher'], $id, 'reviewed', 'Asla yazılmamalı', null, [
            'ready_path' => $ready,
            'go_path' => $dir.\DIRECTORY_SEPARATOR.'never.go',
            'go_timeout_seconds' => 0.3,
        ]));

        $result = $this->awaitWorkerResult($process, $dir.\DIRECTORY_SEPARATOR.'t.out.json', true);

        self::assertFalse(!empty($result['ok']), 'a barrier timeout must never look like a win: '.json_encode($result));
        self::assertSame('go_timeout', $result['error'] ?? null);
        self::assertSame(0, $this->evaluationRowCount());
        self::assertNull($this->currentEvaluationId($id));
        self::assertSame([], $this->evaluationAuditRows());
    }

    public function testWorkerWithAnEmptyOrUnknownPayloadFailsWithoutWriting(): void
    {
        $s = $this->scenario('hec-bad');
        $id = $s['submission']->getId();
        $dir = $this->tempDir('hec_bad');

        // Empty payload file.
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'empty.in.json', '');
        $emptyProcess = $this->startWorkerFile($dir.\DIRECTORY_SEPARATOR.'empty.in.json', $dir.\DIRECTORY_SEPARATOR.'empty.out.json');
        $empty = $this->awaitWorkerResult($emptyProcess, $dir.\DIRECTORY_SEPARATOR.'empty.out.json', true);
        self::assertFalse(!empty($empty['ok']));
        self::assertSame('payload_empty', $empty['error'] ?? null);

        // Teacher that does not exist.
        $ghost = $this->startWorker($dir, 'ghost', [
            'teacher_id' => '0192f000-0000-7000-8000-0000000000aa',
            'submission_id' => $id->toRfc4122(),
            'label' => 'reviewed',
            'feedback' => 'Hayalet öğretmen',
            'expected_evaluation_id' => null,
        ]);
        $missing = $this->awaitWorkerResult($ghost, $dir.\DIRECTORY_SEPARATOR.'ghost.out.json', true);
        self::assertFalse(!empty($missing['ok']));
        self::assertSame('teacher_missing', $missing['error'] ?? null);

        self::assertSame(0, $this->evaluationRowCount());
        self::assertSame([], $this->evaluationAuditRows());
    }

    /**
     * @return array{
     *     ctx: array{owner: User, institution: \App\Entity\Institution, year: \App\Entity\AcademicYear, classroom: \App\Entity\Classroom, teacher: User, teacherMembership: InstitutionMembership},
     *     students: list<InstitutionMembership>,
     *     users: list<User>,
     *     assignment: HomeworkAssignment,
     *     submission: \App\Entity\HomeworkSubmission
     * }
     */
    private function scenario(string $prefix, int $students = 1): array
    {
        $ctx = $this->homeworkContext($prefix);
        $memberships = $this->enrollStudents($ctx, $students);
        // Far-future due date: workers run on the real clock (the shared 2027 date would expire).
        $assignment = $this->assignClassroomHomework($ctx, 'Kesirler çalışma kâğıdı', '2099-06-01', '12:00');
        $users = array_map(static fn (InstitutionMembership $membership): User => $membership->getUser(), $memberships);
        $submission = $this->studentSubmit($users[0], $assignment, 'Eşzamanlı değerlendirilecek yanıt');

        return ['ctx' => $ctx, 'students' => $memberships, 'users' => $users, 'assignment' => $assignment, 'submission' => $submission];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function payload(User $teacher, Uuid $submissionId, string $label, string $feedback, ?Uuid $expected, array $extra = []): array
    {
        return array_merge([
            'teacher_id' => $teacher->getId()->toRfc4122(),
            'submission_id' => $submissionId->toRfc4122(),
            'label' => $label,
            'feedback' => $feedback,
            'expected_evaluation_id' => $expected?->toRfc4122(),
        ], $extra);
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function successCount(array $results): int
    {
        return \count(array_filter($results, static fn (array $row): bool => true === ($row['ok'] ?? false)));
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return list<array<string, mixed>>
     */
    private function losers(array $results): array
    {
        return array_values(array_filter($results, static fn (array $row): bool => true !== ($row['ok'] ?? false)));
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, mixed>
     */
    private function winner(array $results): array
    {
        foreach ($results as $row) {
            if (true === ($row['ok'] ?? false)) {
                self::assertNotEmpty($row['evaluation_id'] ?? null, 'winner must report its evaluation id');
                self::assertIsString($row['evaluation_id']);

                return $row;
            }
        }
        self::fail('No winning worker: '.json_encode($results));
    }

    /**
     * A loser must have lost the race for a domain reason, not crashed or timed out.
     *
     * @param array<string, mixed>       $loser
     * @param list<array<string, mixed>> $results
     */
    private function assertKnownLoser(array $loser, array $results): void
    {
        self::assertContains($loser['reason'] ?? null, self::LOSER_REASONS, 'Loser must surface stale_evaluation or conflict; got '.json_encode($results));
        self::assertSame(HomeworkEvaluationException::class, $loser['error'] ?? null, json_encode($results) ?: '');
    }

    /**
     * Start every payload as its own process, wait at the barrier while this test holds the
     * classroom row, release them together and collect results in payload order.
     *
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<array<string, mixed>>
     */
    private function raceWorkers(array $payloads, string $classroomIdBinary): array
    {
        $dir = $this->tempDir('hec_race');
        $goPath = $dir.\DIRECTORY_SEPARATOR.'go';
        $processes = [];
        $outPaths = [];
        $readyFiles = [];

        foreach ($payloads as $index => $payload) {
            $name = 'race-'.$index;
            $ready = $dir.\DIRECTORY_SEPARATOR.$name.'.ready';
            $readyFiles[] = $ready;
            $payload['ready_path'] = $ready;
            $payload['go_path'] = $goPath;
            $processes[] = $this->startWorker($dir, $name, $payload);
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
    private function runOrderedWorker(string $dir, string $name, array $payload): array
    {
        $ready = $dir.\DIRECTORY_SEPARATOR.$name.'.ready';
        $go = $dir.\DIRECTORY_SEPARATOR.$name.'.go';
        $payload['ready_path'] = $ready;
        $payload['go_path'] = $go;
        $process = $this->startWorker($dir, $name, $payload);
        $this->waitForFiles([$ready], 30.0);
        file_put_contents($go, '1');

        return $this->awaitWorkerResult($process, $dir.\DIRECTORY_SEPARATOR.$name.'.out.json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startWorker(string $dir, string $name, array $payload): Process
    {
        $in = $dir.\DIRECTORY_SEPARATOR.$name.'.in.json';
        $out = $dir.\DIRECTORY_SEPARATOR.$name.'.out.json';
        file_put_contents($in, json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->startWorkerFile($in, $out);
    }

    private function startWorkerFile(string $in, string $out): Process
    {
        $worker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'homework_evaluate_race_worker.php';
        $process = new Process([\PHP_BINARY, $worker, $in, $out], \dirname(__DIR__, 2), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
        ]);
        $process->setTimeout(90);
        $process->start();

        return $process;
    }

    /**
     * Wait for the worker and read its result. A missing, empty or non-JSON result is a test
     * failure; "ok" must be an explicit boolean and agree with the exit code. Unless the caller
     * deliberately provokes a harness failure, a crash or barrier timeout fails the test instead of
     * being read as a lost race.
     *
     * @return array<string, mixed>
     */
    private function awaitWorkerResult(Process $process, string $outPath, bool $allowHarnessFailure = false): array
    {
        $exit = $process->wait();
        self::assertFileExists(
            $outPath,
            'worker result missing (exit='.$exit.'): '.$process->getErrorOutput().$process->getOutput(),
        );
        $raw = (string) file_get_contents($outPath);
        self::assertNotSame('', $raw, 'worker wrote empty result (exit='.$exit.'): '.$process->getErrorOutput());
        try {
            $row = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail('worker result is not JSON: '.$raw.' ('.$e->getMessage().')');
        }
        self::assertIsArray($row);
        self::assertArrayHasKey('ok', $row, 'worker result missing ok: '.$raw);
        self::assertIsBool($row['ok'], 'ok must be an explicit boolean: '.$raw);
        if (true === $row['ok']) {
            self::assertSame(0, $exit, 'successful worker must exit 0; stderr='.$process->getErrorOutput());
            self::assertNotEmpty($row['evaluation_id'] ?? null, 'a success without an evaluation id is not a success: '.$raw);
        } else {
            self::assertNotSame(0, $exit, 'failed worker must exit non-zero; body='.$raw);
            if (!$allowHarnessFailure) {
                self::assertNotSame('worker_aborted', $row['error'] ?? null, 'worker crashed: '.$process->getErrorOutput().$raw);
                self::assertStringEndsNotWith('_timeout', (string) ($row['error'] ?? ''), 'a barrier timeout is a harness failure, not a lost race: '.$raw);
            }
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
            // Brief yield so InnoDB waiters attach after go; not used to decide the winner.
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
}
