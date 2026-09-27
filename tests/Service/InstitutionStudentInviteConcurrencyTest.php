<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\StudentProfileRequest;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\InstitutionStudentInvitationManager;
use App\Service\InvitationCodeDigestHasher;
use App\Service\StudentProfileManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class InstitutionStudentInviteConcurrencyTest extends KernelTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function testParallelAcceptCreatesOneMembershipAndOneEnrollment(): void
    {
        [$student, $token] = $this->prepare('tek', 24, ['ece@example.com']);
        $results = $this->race([
            ['user_id' => $student[0]->getId()->toRfc4122(), 'plain_token' => $token[0]],
            ['user_id' => $student[0]->getId()->toRfc4122(), 'plain_token' => $token[0]],
        ]);
        $successes = (int) (!empty($results[0]['ok'])) + (int) (!empty($results[1]['ok']));
        self::assertSame(1, $successes, json_encode($results, \JSON_THROW_ON_ERROR));
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $memberships = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM institution_memberships WHERE role = ?',
            [InstitutionMembershipRole::Student->value],
        );
        $enrollments = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM classroom_student_enrollments');
        self::assertSame(1, $memberships);
        self::assertSame(1, $enrollments);
    }

    public function testParallelAcceptDoesNotExceedCapacity(): void
    {
        [$students, $tokens] = $this->prepare('dolu', 1, ['bir@example.com', 'iki@example.com']);
        $results = $this->race([
            ['user_id' => $students[0]->getId()->toRfc4122(), 'plain_token' => $tokens[0]],
            ['user_id' => $students[1]->getId()->toRfc4122(), 'plain_token' => $tokens[1]],
        ]);
        $successes = (int) (!empty($results[0]['ok'])) + (int) (!empty($results[1]['ok']));
        self::assertSame(1, $successes, json_encode($results, \JSON_THROW_ON_ERROR));
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $enrollments = (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM classroom_student_enrollments WHERE status = 'active'");
        self::assertSame(1, $enrollments);
    }

    /**
     * @param list<string> $emails
     *
     * @return array{0: list<\App\Entity\User>, 1: list<string>}
     */
    private function prepare(string $suffix, int $capacity, array $emails): array
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $platform = $em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Concurrent accept requires MariaDB/MySQL row locks.');
        }
        $tag = $suffix.'-'.bin2hex(random_bytes(3));
        $super = $this->user('race-sa-'.$tag.'@example.com', UserRole::SuperAdmin);
        $owner = $this->user('race-owner-'.$tag.'@example.com', UserRole::User);
        $creator = static::getContainer()->get(InstitutionCreator::class);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        $years = static::getContainer()->get(AcademicYearManager::class);
        $rooms = static::getContainer()->get(ClassroomManager::class);
        $invites = static::getContainer()->get(InstitutionStudentInvitationManager::class);
        $hasher = static::getContainer()->get(InvitationCodeDigestHasher::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        self::assertInstanceOf(AcademicYearManager::class, $years);
        self::assertInstanceOf(ClassroomManager::class, $rooms);
        self::assertInstanceOf(InstitutionStudentInvitationManager::class, $invites);
        self::assertInstanceOf(InvitationCodeDigestHasher::class, $hasher);
        $institution = $creator->create($super, $owner, 'Yaris '.$tag, InstitutionType::School, 'setup');
        $status->activate($institution, $super, 'activate');
        $year = $years->createPlanned($institution, $owner, 'Yil '.$tag, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'create_year');
        $classroom = $rooms->create($year, $owner, 'Sinif '.$tag, GradeLevel::Grade5, 'panel_create', 'A', $capacity);
        $years->activate($year, $owner, 'open_year');
        $reference = $hasher->workspaceReference('classroom', $classroom->getId());
        $users = [];
        $tokens = [];
        foreach ($emails as $email) {
            $address = str_replace('@', '-'.$tag.'@', $email);
            $user = $this->user($address, UserRole::Student, 'Ece', 'Ak');
            $profiles = static::getContainer()->get(StudentProfileManager::class);
            self::assertInstanceOf(StudentProfileManager::class, $profiles);
            $request = new StudentProfileRequest();
            $request->gradeLevel = GradeLevel::Grade5;
            $profiles->completeOnboarding($user, $request);
            $dispatch = $invites->issue($owner, $institution, $reference, $address, '');
            $users[] = $user;
            $tokens[] = $dispatch->plainToken;
        }
        self::ensureKernelShutdown();

        return [$users, $tokens];
    }

    /**
     * @param list<array{user_id: string, plain_token: string}> $payloads
     *
     * @return list<array<string, mixed>>
     */
    private function race(array $payloads): array
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'student_invite_race_'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700));
        $worker = \dirname(__DIR__).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'institution_student_invite_accept_race_worker.php';
        $processes = [];
        $outputs = [];
        foreach ($payloads as $index => $payload) {
            $in = $dir.\DIRECTORY_SEPARATOR.'in-'.$index.'.json';
            $out = $dir.\DIRECTORY_SEPARATOR.'out-'.$index.'.json';
            file_put_contents($in, json_encode($payload, \JSON_THROW_ON_ERROR));
            $outputs[] = $out;
            $process = new Process([\PHP_BINARY, $worker, $in, $out], \dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '0']);
            $process->setTimeout(90);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
        }
        $decoded = [];
        foreach ($outputs as $index => $out) {
            self::assertFileExists($out, $processes[$index]->getErrorOutput().$processes[$index]->getOutput());
            $row = json_decode((string) file_get_contents($out), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($row);
            $decoded[] = $row;
        }

        return $decoded;
    }

    protected function tearDown(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            if ($em instanceof EntityManagerInterface) {
                QuestionBankDbCleanup::deleteTables($em->getConnection(), [
                    'academic_year_student_enrollment_guards',
                    'classroom_student_enrollments',
                    'classrooms',
                    'institution_active_academic_year_guards',
                    'academic_years',
                    'institution_memberships',
                    'institutions',
                    'student_profiles',
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
