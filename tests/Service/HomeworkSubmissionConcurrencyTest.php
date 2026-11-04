<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\HomeworkAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Exception\HomeworkSubmissionException;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * MariaDB concurrency for student draft + one-shot submit.
 *
 * Every worker takes institution → academic year → classroom → assignment → recipient →
 * submission → enrollment → membership locks (HomeworkSubmissionManager). The test holds the
 * classroom row FOR UPDATE on a separate connection until all workers sit at the barrier, so the
 * workers genuinely contend; the winner is whichever gets the institution lock first. Assertions
 * therefore describe invariants that hold in either order.
 *
 * Workers use the real clock; homework is due 2027-06-01 12:00 Europe/Istanbul.
 */
final class HomeworkSubmissionConcurrencyTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Concurrent homework submit requires MariaDB/MySQL row locks.');
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

    public function testConcurrentDoubleSubmitOnlyOneSucceedsAndWritesOneAudit(): void
    {
        $s = $this->scenario('hsc-dup');
        $payload = $this->payload($s['users'][0], $s['assignment'], 'submit', 'Aynı anda gönderilen yanıt', 0);

        $results = $this->raceWorkers([$payload, $payload], $s['ctx']['classroom']->getId()->toBinary());

        self::assertSame(1, $this->successCount($results), 'Exactly one concurrent submit must succeed; got '.json_encode($results));
        $loser = $this->losers($results)[0];
        self::assertContains(
            $loser['reason'] ?? $loser['error'] ?? null,
            [HomeworkSubmissionException::REASON_ALREADY_SUBMITTED, HomeworkSubmissionException::REASON_CONFLICT],
            'Loser should surface alreadySubmitted or conflict; got '.json_encode($results),
        );

        $this->em->clear();
        self::assertSame(1, $this->submissionRowCount($s['assignment']->getId()));
        $row = $this->submissionRow($s['assignment']->getId());
        self::assertSame('submitted', $row['status']);
        self::assertSame('Aynı anda gönderilen yanıt', $row['body']);
        self::assertSame(2, $row['revision_number']);
        self::assertSame(1, $this->auditCount('homework_submission_submitted'));
        self::assertSame(0, $this->auditCount('homework_submission_draft_saved'));
    }

    public function testConcurrentFirstDraftCreatesExactlyOneRow(): void
    {
        $s = $this->scenario('hsc-first');
        $a = $this->payload($s['users'][0], $s['assignment'], 'draft', 'Birinci sekme', 0);
        $b = $this->payload($s['users'][0], $s['assignment'], 'draft', 'İkinci sekme', 0);

        $results = $this->raceWorkers([$a, $b], $s['ctx']['classroom']->getId()->toBinary());

        self::assertSame(1, $this->successCount($results), 'Exactly one first draft must win; got '.json_encode($results));
        $loser = $this->losers($results)[0];
        self::assertContains(
            $loser['reason'] ?? $loser['error'] ?? null,
            [HomeworkSubmissionException::REASON_STALE_REVISION, HomeworkSubmissionException::REASON_CONFLICT],
            'got '.json_encode($results),
        );

        $this->em->clear();
        self::assertSame(1, $this->submissionRowCount($s['assignment']->getId()));
        $row = $this->submissionRow($s['assignment']->getId());
        self::assertSame('draft', $row['status']);
        self::assertSame(1, $row['revision_number']);
        self::assertContains($row['body'], ['Birinci sekme', 'İkinci sekme']);
        self::assertSame(1, $this->auditCount('homework_submission_draft_saved'));
    }

    public function testConcurrentDraftsWithTheSameRevisionDoNotLoseTheRevisionCheck(): void
    {
        $s = $this->scenario('hsc-stale');
        $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $s['assignment']->getId(), 'başlangıç', 0);
        $a = $this->payload($s['users'][0], $s['assignment'], 'draft', 'sekme A', 1);
        $b = $this->payload($s['users'][0], $s['assignment'], 'draft', 'sekme B', 1);

        $results = $this->raceWorkers([$a, $b], $s['ctx']['classroom']->getId()->toBinary());

        self::assertSame(1, $this->successCount($results), 'got '.json_encode($results));
        self::assertContains(
            $this->losers($results)[0]['reason'] ?? null,
            [HomeworkSubmissionException::REASON_STALE_REVISION, HomeworkSubmissionException::REASON_CONFLICT],
        );
        $this->em->clear();
        $row = $this->submissionRow($s['assignment']->getId());
        self::assertSame(2, $row['revision_number'], 'only one of the two writes may bump the revision');
        self::assertContains($row['body'], ['sekme A', 'sekme B']);
        self::assertSame(2, $this->auditCount('homework_submission_draft_saved'), 'seed draft + winner');
    }

    public function testConcurrentDraftAndSubmitNeverRevertTheSubmittedBodyToADraft(): void
    {
        $s = $this->scenario('hsc-mix');
        $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $s['assignment']->getId(), 'seed taslak', 0);
        $draft = $this->payload($s['users'][0], $s['assignment'], 'draft', 'geç gelen taslak', 1);
        $submit = $this->payload($s['users'][0], $s['assignment'], 'submit', 'nihai yanıt', 1);

        $results = $this->raceWorkers([$draft, $submit], $s['ctx']['classroom']->getId()->toBinary());

        // Both carry revision 1: whichever commits first makes the other stale/already submitted.
        self::assertSame(1, $this->successCount($results), 'Exactly one write may win; got '.json_encode($results));
        $loser = $this->losers($results)[0];
        $this->em->clear();
        $row = $this->submissionRow($s['assignment']->getId());

        if ('submit' === ($this->winner($results)['mode'] ?? null)) {
            // Submit serialized first: the late draft is refused and the body is never reverted.
            self::assertSame(HomeworkSubmissionException::REASON_ALREADY_SUBMITTED, $loser['reason'] ?? null, json_encode($results));
            self::assertSame('submitted', $row['status']);
            self::assertSame('nihai yanıt', $row['body']);
            self::assertNotSame('geç gelen taslak', $row['body']);
            self::assertSame(2, $row['revision_number']);
            self::assertSame(1, $this->auditCount('homework_submission_submitted'));
        } else {
            // Draft serialized first: the submit carried an outdated revision and was refused.
            self::assertSame(HomeworkSubmissionException::REASON_STALE_REVISION, $loser['reason'] ?? null, json_encode($results));
            self::assertSame('draft', $row['status']);
            self::assertSame('geç gelen taslak', $row['body']);
            self::assertSame(2, $row['revision_number']);
            self::assertSame(0, $this->auditCount('homework_submission_submitted'));
        }
        self::assertSame('submitted' === $row['status'], null !== $row['submitted_at'], 'submitted_at is set exactly when the row is submitted');
    }

    public function testSubmitThenLateDraftKeepsSubmittedBodyDeterministically(): void
    {
        $s = $this->scenario('hsc-order');
        $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $s['assignment']->getId(), 'seed taslak', 0);

        $dir = $this->tempDir('hsc_order');
        $submitDone = $dir.\DIRECTORY_SEPARATOR.'submit.done';
        $submitResult = $this->runOrderedWorker($dir, 'submit', $this->payload(
            $s['users'][0],
            $s['assignment'],
            'submit',
            'nihai yanıt',
            1,
            ['done_path' => $submitDone],
        ));
        self::assertTrue(!empty($submitResult['ok']), 'submit worker failed: '.json_encode($submitResult));
        self::assertFileExists($submitDone);

        foreach ([2, 1] as $revision) {
            $lateResult = $this->runOrderedWorker($dir, 'late-'.$revision, $this->payload(
                $s['users'][0],
                $s['assignment'],
                'draft',
                'taslağa dönüş',
                $revision,
                ['after_path' => $submitDone],
            ));
            self::assertFalse(!empty($lateResult['ok']), 'late draft must fail: '.json_encode($lateResult));
            self::assertSame(HomeworkSubmissionException::REASON_ALREADY_SUBMITTED, $lateResult['reason'] ?? null, json_encode($lateResult));
        }

        $this->em->clear();
        $row = $this->submissionRow($s['assignment']->getId());
        self::assertSame('submitted', $row['status']);
        self::assertSame('nihai yanıt', $row['body']);
        self::assertSame(2, $row['revision_number']);
        self::assertSame(1, $this->auditCount('homework_submission_submitted'));
        self::assertSame(1, $this->auditCount('homework_submission_draft_saved'), 'only the seed draft');
    }

    public function testConcurrentSubmitsFromDifferentStudentsBothSucceedIndependently(): void
    {
        $s = $this->scenario('hsc-two', 2);
        $a = $this->payload($s['users'][0], $s['assignment'], 'submit', 'Öğrenci bir', 0);
        $b = $this->payload($s['users'][1], $s['assignment'], 'submit', 'Öğrenci iki', 0);

        $results = $this->raceWorkers([$a, $b], $s['ctx']['classroom']->getId()->toBinary());

        self::assertSame(2, $this->successCount($results), 'got '.json_encode($results));
        $this->em->clear();
        self::assertSame(2, $this->submissionRowCount($s['assignment']->getId()));
        self::assertSame('Öğrenci bir', $this->submissionRow($s['assignment']->getId(), $s['users'][0])['body']);
        self::assertSame('Öğrenci iki', $this->submissionRow($s['assignment']->getId(), $s['users'][1])['body']);
        self::assertSame(2, $this->auditCount('homework_submission_submitted'));
    }

    /**
     * @return array{
     *     ctx: array{owner: User, institution: \App\Entity\Institution, year: \App\Entity\AcademicYear, classroom: \App\Entity\Classroom, teacher: User},
     *     students: list<InstitutionMembership>,
     *     users: list<User>,
     *     assignment: HomeworkAssignment
     * }
     */
    private function scenario(string $prefix, int $students = 1): array
    {
        $ctx = $this->homeworkContext($prefix);
        $memberships = $this->enrollStudents($ctx, $students);
        $definition = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], dueDate: '2027-06-01', dueTime: '12:00'),
        );
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $users = array_map(static fn (InstitutionMembership $membership): User => $membership->getUser(), $memberships);

        return ['ctx' => $ctx, 'students' => $memberships, 'users' => $users, 'assignment' => $assignment];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function payload(User $student, HomeworkAssignment $assignment, string $mode, string $body, int $revision, array $extra = []): array
    {
        return array_merge([
            'student_id' => $student->getId()->toRfc4122(),
            'assignment_id' => $assignment->getId()->toRfc4122(),
            'mode' => $mode,
            'body' => $body,
            'revision' => $revision,
            'confirmed' => true,
        ], $extra);
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function successCount(array $results): int
    {
        return \count(array_filter($results, static fn (array $row): bool => !empty($row['ok'])));
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return list<array<string, mixed>>
     */
    private function losers(array $results): array
    {
        return array_values(array_filter($results, static fn (array $row): bool => empty($row['ok'])));
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, mixed>
     */
    private function winner(array $results): array
    {
        foreach ($results as $row) {
            if (!empty($row['ok'])) {
                return $row;
            }
        }
        self::fail('No winning worker: '.json_encode($results));
    }

    /**
     * @return array{body: string, status: string, revision_number: int, submitted_at: ?string}
     */
    private function submissionRow(Uuid $assignmentId, ?User $student = null): array
    {
        $sql = 'SELECT body, status, revision_number, submitted_at FROM homework_submissions WHERE assignment_id = :id';
        $params = ['id' => $assignmentId->toBinary()];
        if (null !== $student) {
            $sql .= ' AND student_user_id = :student';
            $params['student'] = $student->getId()->toBinary();
        }
        $row = $this->em->getConnection()->fetchAssociative($sql, $params);
        self::assertIsArray($row, 'submission row expected');

        return [
            'body' => (string) $row['body'],
            'status' => (string) $row['status'],
            'revision_number' => (int) $row['revision_number'],
            'submitted_at' => null === $row['submitted_at'] ? null : (string) $row['submitted_at'],
        ];
    }

    private function auditCount(string $action): int
    {
        return \count(array_filter(
            $this->submissionAuditRows(),
            static fn (array $row): bool => $action === $row['action'],
        ));
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
        $dir = $this->tempDir('hsc_race');
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
        $worker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'homework_submit_race_worker.php';
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
