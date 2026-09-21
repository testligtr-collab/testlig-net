<?php

declare(strict_types=1);

/**
 * Parallel accept worker for ParentStudentLinkConsentManagerConcurrencyTest.
 *
 * Usage: php tests/bin/parent_student_link_accept_race_worker.php <payload.json> <result.json>
 */

use App\Kernel;
use App\Repository\UserRepository;
use App\Service\ParentStudentLinkConsentManager;
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
$invitationId = Uuid::fromString($payload['invitation_id']);
$parentId = Uuid::fromString($payload['parent_id']);
$plainCode = $payload['plain_code'];

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

/** @var ParentStudentLinkConsentManager $manager */
$manager = $container->get(ParentStudentLinkConsentManager::class);
/** @var UserRepository $users */
$users = $container->get(UserRepository::class);

$parent = $users->findOneById($parentId);
if (null === $parent) {
    file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'parent_missing'], \JSON_THROW_ON_ERROR));
    exit(1);
}

try {
    $link = $manager->acceptByParent($parent, $invitationId, $plainCode);
    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'link_id' => $link->getId()->toRfc4122(),
        'verified' => $link->isVerified(),
    ], \JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $e) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $e::class,
    ], \JSON_THROW_ON_ERROR));
    exit(1);
}
