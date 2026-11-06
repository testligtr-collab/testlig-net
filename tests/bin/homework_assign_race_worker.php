<?php

declare(strict_types=1);

/**
 * Parallel assign worker for HomeworkAssignmentConcurrencyTest.
 *
 * Usage: php tests/bin/homework_assign_race_worker.php <payload.json> <result.json>
 *
 * Optional payload keys:
 * - ready_path / go_path: barrier before the write
 * - after_path: wait until this file exists (deterministic serialization with another worker)
 * - done_path: written after a successful assign
 */

use App\Kernel;
use App\Repository\UserRepository;
use App\Service\Homework\HomeworkAssignmentManager;
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
$teacherId = Uuid::fromString($payload['teacher_id']);
$definitionId = Uuid::fromString($payload['definition_id']);
$revision = (int) $payload['revision'];
$confirmed = (bool) ($payload['confirmed'] ?? true);
$readyPath = $payload['ready_path'] ?? null;
$goPath = $payload['go_path'] ?? null;
$afterPath = $payload['after_path'] ?? null;
$donePath = $payload['done_path'] ?? null;

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

/** @var HomeworkAssignmentManager $manager */
$manager = $container->get(HomeworkAssignmentManager::class);
/** @var UserRepository $users */
$users = $container->get(UserRepository::class);

$teacher = $users->findOneById($teacherId);
if (null === $teacher) {
    file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'teacher_missing'], \JSON_THROW_ON_ERROR));
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
    $assignment = $manager->assignToClassroom($teacher, $definitionId, $revision, $confirmed);
    if (is_string($donePath) && '' !== $donePath) {
        file_put_contents($donePath, '1');
    }
    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'assignment_id' => $assignment->getId()->toRfc4122(),
        'recipient_count' => $assignment->getRecipientCount(),
    ], \JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $e) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $e::class,
        'reason' => method_exists($e, 'getReason') ? $e->getReason() : null,
        'message' => $e->getMessage(),
    ], \JSON_THROW_ON_ERROR));
    exit(1);
}
