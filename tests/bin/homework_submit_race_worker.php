<?php

declare(strict_types=1);

/**
 * Parallel student draft/submit worker for HomeworkSubmissionConcurrencyTest.
 *
 * Usage: php tests/bin/homework_submit_race_worker.php <payload.json> <result.json>
 *
 * Payload keys:
 * - student_id, assignment_id, body, revision (expected revision; 0 for the first write)
 * - mode: "draft" (default) or "submit"; confirmed (submit only, default true)
 * Optional payload keys:
 * - ready_path / go_path: barrier before the write
 * - after_path: wait until this file exists (deterministic serialization with another worker)
 * - done_path: written after a successful write
 */

use App\Kernel;
use App\Repository\UserRepository;
use App\Service\Homework\HomeworkSubmissionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/tests/bootstrap.php';

$payloadPath = $argv[1] ?? null;
$resultPath = $argv[2] ?? null;
if (!is_string($payloadPath) || !is_string($resultPath)) {
    fwrite(\STDERR, "usage: worker <payload.json> <result.json>\n");
    exit(2);
}

$waitForFile = static function (string $path, float $seconds, string $label) use ($resultPath): void {
    $deadline = microtime(true) + $seconds;
    while (!is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (!is_file($path)) {
        file_put_contents($resultPath, json_encode(['ok' => false, 'error' => $label.'_timeout'], \JSON_THROW_ON_ERROR));
        exit(1);
    }
};

$payload = json_decode((string) file_get_contents($payloadPath), true, 512, \JSON_THROW_ON_ERROR);
$studentId = Uuid::fromString($payload['student_id']);
$assignmentId = Uuid::fromString($payload['assignment_id']);
$body = (string) ($payload['body'] ?? '');
$revision = (int) ($payload['revision'] ?? 0);
$mode = (string) ($payload['mode'] ?? 'draft');
$confirmed = (bool) ($payload['confirmed'] ?? true);
$readyPath = $payload['ready_path'] ?? null;
$goPath = $payload['go_path'] ?? null;
$afterPath = $payload['after_path'] ?? null;
$donePath = $payload['done_path'] ?? null;

if (!in_array($mode, ['draft', 'submit'], true)) {
    file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'bad_mode'], \JSON_THROW_ON_ERROR));
    exit(2);
}

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();
if ($container->has('test.service_container')) {
    $testContainer = $container->get('test.service_container');
    if (!$testContainer instanceof ContainerInterface) {
        fwrite(\STDERR, "test.service_container missing\n");
        exit(2);
    }
    $container = $testContainer;
}

/** @var HomeworkSubmissionManager $manager */
$manager = $container->get(HomeworkSubmissionManager::class);
/** @var UserRepository $users */
$users = $container->get(UserRepository::class);

$student = $users->findOneById($studentId);
if (null === $student) {
    file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'student_missing'], \JSON_THROW_ON_ERROR));
    exit(1);
}

if (is_string($readyPath) && is_string($goPath)) {
    file_put_contents($readyPath, '1');
    $waitForFile($goPath, 45.0, 'go');
}

if (is_string($afterPath) && '' !== $afterPath) {
    $waitForFile($afterPath, 60.0, 'after');
}

try {
    $submission = 'submit' === $mode
        ? $manager->submit($student, $assignmentId, $body, $revision, $confirmed)
        : $manager->saveDraft($student, $assignmentId, $body, $revision);
    if (is_string($donePath) && '' !== $donePath) {
        file_put_contents($donePath, '1');
    }
    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'mode' => $mode,
        'submission_id' => $submission->getId()->toRfc4122(),
        'status' => $submission->getStatus()->value,
        'revision_number' => $submission->getRevisionNumber(),
    ], \JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $e) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'mode' => $mode,
        'error' => $e::class,
        'reason' => method_exists($e, 'getReason') ? $e->getReason() : null,
        'message' => $e->getMessage(),
    ], \JSON_THROW_ON_ERROR));
    exit(1);
}
