<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ParentStudentLink;
use App\Entity\ParentStudentLinkCode;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\ParentLinkCodeCodec;
use App\Service\ParentStudentLinkCodeManager;
use App\Service\UserFactory;
use App\Tests\Support\ParentStudentLinkDbCleanup;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class ParentStudentLinkCodeConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ParentStudentLinkCodeManager $manager;
    private UserFactory $userFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Concurrent redeem race requires MariaDB/MySQL row locks.');
        }
        $manager = $c->get(ParentStudentLinkCodeManager::class);
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(ParentStudentLinkCodeManager::class, $manager);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->manager = $manager;
        $this->userFactory = $factory;
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    public function testConcurrentRedeemOnlyOneSucceeds(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $student = $this->user('race-student+'.$suffix.'@example.com', UserRole::Student);
        $parentA = $this->user('race-parent-a+'.$suffix.'@example.com', UserRole::Parent);
        $parentB = $this->user('race-parent-b+'.$suffix.'@example.com', UserRole::Parent);
        $issued = $this->manager->issue($student);
        $raw = str_replace('-', '', $issued->displayCode);
        self::assertSame(ParentLinkCodeCodec::LENGTH, \strlen($raw));

        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'psl_code_race_'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700));
        $payloadA = $dir.\DIRECTORY_SEPARATOR.'a.json';
        $payloadB = $dir.\DIRECTORY_SEPARATOR.'b.json';
        $outA = $dir.\DIRECTORY_SEPARATOR.'out-a.json';
        $outB = $dir.\DIRECTORY_SEPARATOR.'out-b.json';
        file_put_contents($payloadA, json_encode([
            'parent_id' => $parentA->getId()->toRfc4122(),
            'plain_code' => $issued->displayCode,
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($payloadB, json_encode([
            'parent_id' => $parentB->getId()->toRfc4122(),
            'plain_code' => $issued->displayCode,
        ], \JSON_THROW_ON_ERROR));

        $worker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'parent_link_code_redeem_race_worker.php';
        $p1 = new Process([\PHP_BINARY, $worker, $payloadA, $outA], \dirname(__DIR__, 2), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
        ]);
        $p2 = new Process([\PHP_BINARY, $worker, $payloadB, $outB], \dirname(__DIR__, 2), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
        ]);
        $p1->setTimeout(90);
        $p2->setTimeout(90);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        self::assertFileExists($outA, $p1->getErrorOutput());
        self::assertFileExists($outB, $p2->getErrorOutput());
        $r1 = json_decode((string) file_get_contents($outA), true, 512, \JSON_THROW_ON_ERROR);
        $r2 = json_decode((string) file_get_contents($outB), true, 512, \JSON_THROW_ON_ERROR);
        $successes = (int) (!empty($r1['ok'])) + (int) (!empty($r2['ok']));
        self::assertSame(1, $successes, json_encode([$r1, $r2], \JSON_THROW_ON_ERROR));

        $this->em->clear();
        $codes = $this->em->getRepository(ParentStudentLinkCode::class)->findAll();
        self::assertCount(1, $codes);
        self::assertFalse($codes[0]->isUsable(new \DateTimeImmutable('now')));
        $links = $this->em->getRepository(ParentStudentLink::class)->findBy(['student' => $student]);
        $verified = array_filter($links, static fn (ParentStudentLink $link): bool => $link->isVerified());
        self::assertCount(1, $verified);

        @unlink($payloadA);
        @unlink($payloadB);
        @unlink($outA);
        @unlink($outB);
        @rmdir($dir);
    }

    private function user(string $email, UserRole $role): \App\Entity\User
    {
        $user = $this->userFactory->createAndPersist(
            email: $email,
            plainPassword: 'Password1!',
            firstName: 'Race',
            lastName: 'User',
            initialRole: $role,
        );
        $user->markEmailVerified();
        $user->transitionTo(UserStatus::Active);
        $this->em->flush();

        return $user;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        ParentStudentLinkDbCleanup::deleteAll($connection);
        foreach (['security_audit_events', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
        $this->em->clear();
    }
}
