<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\InstitutionTeacherInvitationManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class InstitutionTeacherInviteConcurrencyTest extends KernelTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function testParallelAcceptCreatesOneMembership(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $platform = $em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Concurrent accept requires MariaDB/MySQL row locks.');
        }

        $suffix = bin2hex(random_bytes(4));
        $super = $this->user('race-sa-'.$suffix.'@example.com', UserRole::SuperAdmin);
        $owner = $this->user('race-owner-'.$suffix.'@example.com', UserRole::User);
        $teacher = $this->user('race-teacher-'.$suffix.'@example.com', UserRole::User, 'Deniz', 'Kaya');
        $creator = static::getContainer()->get(InstitutionCreator::class);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        $invites = static::getContainer()->get(InstitutionTeacherInvitationManager::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        self::assertInstanceOf(InstitutionTeacherInvitationManager::class, $invites);
        $institution = $creator->create($super, $owner, 'Yaris Okulu '.$suffix, InstitutionType::School, 'setup');
        $status->activate($institution, $super, 'activate');
        $dispatch = $invites->issue($owner, $institution, 'race-teacher-'.$suffix.'@example.com', '');
        self::assertNotSame('', $dispatch->plainToken);

        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'teacher_invite_race_'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700));
        $payload = $dir.\DIRECTORY_SEPARATOR.'payload.json';
        $outA = $dir.\DIRECTORY_SEPARATOR.'out-a.json';
        $outB = $dir.\DIRECTORY_SEPARATOR.'out-b.json';
        file_put_contents($payload, json_encode([
            'user_id' => $teacher->getId()->toRfc4122(),
            'plain_token' => $dispatch->plainToken,
        ], \JSON_THROW_ON_ERROR));
        $worker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'institution_teacher_invite_accept_race_worker.php';
        $p1 = new Process([\PHP_BINARY, $worker, $payload, $outA], \dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '0']);
        $p2 = new Process([\PHP_BINARY, $worker, $payload, $outB], \dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '0']);
        $p1->setTimeout(90);
        $p2->setTimeout(90);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();
        self::assertFileExists($outA, $p1->getErrorOutput().$p1->getOutput());
        self::assertFileExists($outB, $p2->getErrorOutput().$p2->getOutput());
        $r1 = json_decode((string) file_get_contents($outA), true, 512, \JSON_THROW_ON_ERROR);
        $r2 = json_decode((string) file_get_contents($outB), true, 512, \JSON_THROW_ON_ERROR);
        $successes = (int) (!empty($r1['ok'])) + (int) (!empty($r2['ok']));
        self::assertSame(1, $successes, json_encode([$r1, $r2], \JSON_THROW_ON_ERROR));
        $em->clear();
        $count = (int) $em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(\App\Entity\InstitutionMembership::class, 'm')
            ->andWhere('m.user = :user')
            ->andWhere('m.role = :role')
            ->setParameter('user', $teacher->getId(), 'uuid')
            ->setParameter('role', \App\Enum\InstitutionMembershipRole::Teacher)
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(1, $count);
    }

    protected function tearDown(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            if ($em instanceof EntityManagerInterface) {
                QuestionBankDbCleanup::deleteTables($em->getConnection(), [
                    'institution_memberships',
                    'institutions',
                    'security_audit_events',
                    'users',
                ]);
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function user(string $email, UserRole $role, string $first = 'Ada', string $last = 'Yılmaz'): \App\Entity\User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $lifecycle->markEmailVerifiedAndActivate($user);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
            $users = static::getContainer()->get(\App\Repository\UserRepository::class);
            self::assertInstanceOf(\App\Repository\UserRepository::class, $users);
            $users->save($user);
        }

        return $user;
    }
}
