<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ParentStudentLink;
use App\Entity\PersonalInvitation;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\ParentStudentLinkConsentManager;
use App\Service\UserFactory;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * MariaDB concurrency: two accept workers race the same personal invitation.
 */
final class ParentStudentLinkConsentManagerConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ParentStudentLinkConsentManager $manager;
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
            self::markTestSkipped('Concurrent accept race requires MariaDB/MySQL row locks.');
        }

        $manager = $c->get(ParentStudentLinkConsentManager::class);
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(ParentStudentLinkConsentManager::class, $manager);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->manager = $manager;
        $this->userFactory = $factory;
    }

    public function testConcurrentAcceptOnlyOneSucceeds(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $student = $this->userFactory->createAndPersist(
            email: 'race-student+'.$suffix.'@example.com',
            plainPassword: 'Password1!',
            firstName: 'Race',
            lastName: 'Student',
            initialRole: UserRole::Student,
        );
        $student->markEmailVerified();
        $student->transitionTo(UserStatus::Active);
        $parent = $this->userFactory->createAndPersist(
            email: 'race-parent+'.$suffix.'@example.com',
            plainPassword: 'Password1!',
            firstName: 'Race',
            lastName: 'Parent',
            initialRole: UserRole::Parent,
        );
        $parent->markEmailVerified();
        $parent->transitionTo(UserStatus::Active);
        $this->em->flush();

        $issued = $this->manager->requestFromStudent($student, $parent);
        $invitationId = $issued->invitation->getId()->toRfc4122();
        $parentId = $parent->getId()->toRfc4122();
        $plainCode = $issued->plainCode;

        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'psl_accept_race_'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700));
        $payload = $dir.\DIRECTORY_SEPARATOR.'payload.json';
        $out1 = $dir.\DIRECTORY_SEPARATOR.'out1.json';
        $out2 = $dir.\DIRECTORY_SEPARATOR.'out2.json';
        file_put_contents($payload, json_encode([
            'invitation_id' => $invitationId,
            'parent_id' => $parentId,
            'plain_code' => $plainCode,
        ], \JSON_THROW_ON_ERROR));

        $worker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'parent_student_link_accept_race_worker.php';
        $p1 = new Process([\PHP_BINARY, $worker, $payload, $out1], \dirname(__DIR__, 2), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
        ]);
        $p2 = new Process([\PHP_BINARY, $worker, $payload, $out2], \dirname(__DIR__, 2), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
        ]);
        $p1->setTimeout(90);
        $p2->setTimeout(90);

        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        self::assertFileExists($out1, 'worker1 result missing: '.$p1->getErrorOutput());
        self::assertFileExists($out2, 'worker2 result missing: '.$p2->getErrorOutput());
        $r1 = json_decode((string) file_get_contents($out1), true, 512, \JSON_THROW_ON_ERROR);
        $r2 = json_decode((string) file_get_contents($out2), true, 512, \JSON_THROW_ON_ERROR);

        $successes = (int) (!empty($r1['ok'])) + (int) (!empty($r2['ok']));
        self::assertSame(1, $successes, 'Exactly one concurrent accept must succeed; got '.json_encode([$r1, $r2]));

        $this->em->clear();
        $invite = $this->em->find(PersonalInvitation::class, $issued->invitation->getId());
        self::assertInstanceOf(PersonalInvitation::class, $invite);
        self::assertTrue($invite->isConsumed());

        $verified = $this->em->getRepository(ParentStudentLink::class)->findOneBy([
            'personalInvitation' => $invite,
        ]);
        self::assertInstanceOf(ParentStudentLink::class, $verified);
        self::assertTrue($verified->isVerified());

        @unlink($payload);
        @unlink($out1);
        @unlink($out2);
        @rmdir($dir);
    }
}
