<?php

declare(strict_types=1);

/**
 * Parallel accept worker for InstitutionStudentInviteConcurrencyTest.
 *
 * Usage: php tests/bin/institution_student_invite_accept_race_worker.php <payload.json> <result.json>
 */

use App\Kernel;
use App\Repository\UserRepository;
use App\Service\InstitutionStudentInvitationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/tests/bootstrap.php';

$payloadPath = $argv[1] ?? null;
$resultPath = $argv[2] ?? null;
if (!is_string($payloadPath) || !is_string($resultPath)) {
    fwrite(\STDERR, "usage: worker <payload.json> <result.json>\n");
    exit(2);
}

$payload = json_decode((string) file_get_contents($payloadPath), true, 512, \JSON_THROW_ON_ERROR);
$userId = Uuid::fromString($payload['user_id']);
$plain = $payload['plain_token'];

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

/** @var InstitutionStudentInvitationManager $manager */
$manager = $container->get(InstitutionStudentInvitationManager::class);
/** @var UserRepository $users */
$users = $container->get(UserRepository::class);
$user = $users->findOneById($userId);
if (null === $user) {
    file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'user_missing'], \JSON_THROW_ON_ERROR));
    exit(1);
}

try {
    $manager->accept($user, $plain);
    file_put_contents($resultPath, json_encode(['ok' => true], \JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $e) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $e::class,
    ], \JSON_THROW_ON_ERROR));
    exit(1);
}
