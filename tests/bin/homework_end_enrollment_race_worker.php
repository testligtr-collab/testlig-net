<?php

declare(strict_types=1);

/**
 * Parallel end-enrollment worker for HomeworkAssignmentConcurrencyTest.
 *
 * Usage: php tests/bin/homework_end_enrollment_race_worker.php <payload.json> <result.json>
 *
 * Optional payload keys mirror the assign worker: ready_path/go_path, after_path, done_path.
 */

use App\Entity\ClassroomStudentEnrollment;
use App\Kernel;
use App\Repository\UserRepository;
use App\Service\ClassroomStudentEnrollmentManager;
use Doctrine\ORM\EntityManagerInterface;
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
$ownerId = Uuid::fromString($payload['owner_id']);
$enrollmentId = Uuid::fromString($payload['enrollment_id']);
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

/** @var ClassroomStudentEnrollmentManager $manager */
$manager = $container->get(ClassroomStudentEnrollmentManager::class);
/** @var UserRepository $users */
$users = $container->get(UserRepository::class);
/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);

$owner = $users->findOneById($ownerId);
$enrollment = $em->find(ClassroomStudentEnrollment::class, $enrollmentId);
if (null === $owner || !$enrollment instanceof ClassroomStudentEnrollment) {
    file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'missing'], \JSON_THROW_ON_ERROR));
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
    $manager->endEnrollment($enrollment, $owner, 'left_class');
    if (is_string($donePath) && '' !== $donePath) {
        file_put_contents($donePath, '1');
    }
    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'enrollment_id' => $enrollmentId->toRfc4122(),
    ], \JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $e) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $e::class,
        'message' => $e->getMessage(),
    ], \JSON_THROW_ON_ERROR));
    exit(1);
}
