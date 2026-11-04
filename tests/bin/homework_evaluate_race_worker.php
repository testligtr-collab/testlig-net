<?php

declare(strict_types=1);

/**
 * Parallel teacher evaluation worker for HomeworkEvaluationConcurrencyTest.
 *
 * Usage: php tests/bin/homework_evaluate_race_worker.php <payload.json> <result.json>
 *
 * Payload keys:
 * - teacher_id, submission_id, label, feedback
 * - expected_evaluation_id: current evaluation id the tab last saw, or null for a first evaluation
 * Optional payload keys:
 * - ready_path / go_path: barrier before the write
 * - go_timeout_seconds: how long to wait for go_path (default 45)
 * - after_path: wait until this file exists (deterministic serialization with another worker)
 * - done_path: written after a successful write
 *
 * The result file is the only success signal. "ok": true is written exclusively after
 * HomeworkEvaluationManager::record() returned a persisted evaluation. A barrier timeout, a missing
 * teacher, a bad payload, an exception or a crash before the write all produce "ok": false (and a
 * non-zero exit code); a crash that never reached the explicit write is turned into
 * "error": "worker_aborted" by the shutdown handler. An empty or missing result is therefore
 * never a success for the test.
 */

use App\Kernel;
use App\Repository\UserRepository;
use App\Service\Homework\HomeworkEvaluationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/tests/bootstrap.php';

$payloadPath = $argv[1] ?? null;
$resultPath = $argv[2] ?? null;
if (!is_string($payloadPath) || !is_string($resultPath)) {
    fwrite(\STDERR, "usage: worker <payload.json> <result.json>\n");
    exit(2);
}

$resultWritten = false;
$writeResult = static function (array $result) use ($resultPath, &$resultWritten): void {
    file_put_contents($resultPath, json_encode($result, \JSON_THROW_ON_ERROR));
    $resultWritten = true;
};
register_shutdown_function(static function () use ($resultPath, &$resultWritten): void {
    if (!$resultWritten) {
        // Never leave an empty or absent result that could be mistaken for a pass.
        file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'worker_aborted'], \JSON_THROW_ON_ERROR));
    }
});

$fail = static function (string $error, int $exit = 1) use ($writeResult): never {
    $writeResult(['ok' => false, 'error' => $error]);
    exit($exit);
};

$waitForFile = static function (string $path, float $seconds, string $label) use ($fail): void {
    $deadline = microtime(true) + $seconds;
    while (!is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (!is_file($path)) {
        $fail($label.'_timeout');
    }
};

$raw = is_file($payloadPath) ? (string) file_get_contents($payloadPath) : '';
if ('' === trim($raw)) {
    $fail('payload_empty', 2);
}
try {
    $payload = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
} catch (JsonException) {
    $fail('payload_invalid', 2);
}
if (!is_array($payload)
    || !is_string($payload['teacher_id'] ?? null)
    || !is_string($payload['submission_id'] ?? null)
    || !is_string($payload['label'] ?? null)
    || !is_string($payload['feedback'] ?? null)
    || !Uuid::isValid($payload['teacher_id'])
    || !Uuid::isValid($payload['submission_id'])
) {
    $fail('payload_invalid', 2);
}

$expectedRaw = $payload['expected_evaluation_id'] ?? null;
if (null !== $expectedRaw && (!is_string($expectedRaw) || !Uuid::isValid($expectedRaw))) {
    $fail('payload_invalid', 2);
}
$teacherId = Uuid::fromString($payload['teacher_id']);
$submissionId = Uuid::fromString($payload['submission_id']);
$expected = null === $expectedRaw ? null : Uuid::fromString($expectedRaw);
$readyPath = $payload['ready_path'] ?? null;
$goPath = $payload['go_path'] ?? null;
$afterPath = $payload['after_path'] ?? null;
$donePath = $payload['done_path'] ?? null;
$goTimeout = (float) ($payload['go_timeout_seconds'] ?? 45.0);

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();
if ($container->has('test.service_container')) {
    $testContainer = $container->get('test.service_container');
    if (!$testContainer instanceof ContainerInterface) {
        fwrite(\STDERR, "test.service_container missing\n");
        $fail('container_missing', 2);
    }
    $container = $testContainer;
}

/** @var HomeworkEvaluationManager $manager */
$manager = $container->get(HomeworkEvaluationManager::class);
/** @var UserRepository $users */
$users = $container->get(UserRepository::class);

$teacher = $users->findOneById($teacherId);
if (null === $teacher) {
    $fail('teacher_missing');
}

if (is_string($readyPath) && is_string($goPath)) {
    file_put_contents($readyPath, '1');
    $waitForFile($goPath, $goTimeout, 'go');
}

if (is_string($afterPath) && '' !== $afterPath) {
    $waitForFile($afterPath, 60.0, 'after');
}

try {
    $evaluation = $manager->record($teacher, $submissionId, $payload['label'], $payload['feedback'], $expected);
    if (is_string($donePath) && '' !== $donePath) {
        file_put_contents($donePath, '1');
    }
    $writeResult([
        'ok' => true,
        'evaluation_id' => $evaluation->getId()->toRfc4122(),
        'submission_id' => $evaluation->getSubmission()->getId()->toRfc4122(),
        'label' => $evaluation->getLabel()->value,
        'feedback' => $evaluation->getFeedback(),
        'supersedes_evaluation_id' => $evaluation->getSupersedes()?->getId()->toRfc4122(),
    ]);
    exit(0);
} catch (Throwable $e) {
    $writeResult([
        'ok' => false,
        'error' => $e::class,
        'reason' => method_exists($e, 'getReason') ? $e->getReason() : null,
        'message' => $e->getMessage(),
    ]);
    exit(1);
}
