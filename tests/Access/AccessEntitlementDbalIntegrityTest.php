<?php

declare(strict_types=1);

namespace App\Tests\Access;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

final class AccessEntitlementDbalIntegrityTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $connection = $this->em->getConnection();
        AccessEntitlementDbCleanup::deleteAll($connection);
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'security_bootstrap_guards',
            'reset_password_requests',
            'users',
        ]);
    }

    public function testChecksTriggersAndImmutability(): void
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $factory->createAndPersist('dbal-ae@example.com', 'Guclu-Parola-123!', 'A', 'U', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $users->save($user);
        $actor = $user->getId()->toBinary();

        $conn = $this->em->getConnection();
        $pkgId = (new UuidV7())->toBinary();

        try {
            $conn->executeStatement(
                'INSERT INTO access_packages
                (id, code, name, normalized_name, description, target_type, status, default_validity_days, default_seat_limit, created_by_id, created_at, updated_at, activated_at, retired_at)
                VALUES (?, ?, ?, ?, NULL, ?, ?, 30, 5, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)',
                [$pkgId, 'bad_indiv', 'Bad', 'bad', 'individual', 'draft', $actor],
            );
            self::fail('Expected CHECK seat_limit failure');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $conn->executeStatement(
            'INSERT INTO access_packages
            (id, code, name, normalized_name, description, target_type, status, default_validity_days, default_seat_limit, created_by_id, created_at, updated_at, activated_at, retired_at)
            VALUES (?, ?, ?, ?, NULL, ?, ?, 30, NULL, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)',
            [$pkgId, 'ok_indiv', 'Ok', 'ok', 'individual', 'draft', $actor],
        );

        $versionId = (new UuidV7())->toBinary();
        $hash = str_repeat('a', 64);
        $conn->executeStatement(
            'INSERT INTO access_package_versions
            (id, package_id, version_number, status, validity_days, seat_limit, policy_hash, schema_version, created_by_id, activated_by_id, created_at, updated_at, activated_at, superseded_at)
            VALUES (?, ?, 1, ?, 30, NULL, ?, 1, ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)',
            [$versionId, $pkgId, 'draft', $hash, $actor],
        );

        $licenseId = (new UuidV7())->toBinary();
        try {
            $conn->executeStatement(
                'INSERT INTO access_licenses
                (id, package_id, package_version_id, licensee_type, user_id, institution_id, status, source_type, external_reference, valid_from, valid_until, seat_limit, policy_snapshot_hash, created_by_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, NULL, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), NULL, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$licenseId, $pkgId, $versionId, 'user', 'pending', 'manual', $hash, $actor],
            );
            self::fail('Expected null-pair CHECK');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'INSERT INTO access_package_versions
                (id, package_id, version_number, status, validity_days, seat_limit, policy_hash, schema_version, created_by_id, activated_by_id, created_at, updated_at, activated_at, superseded_at)
                VALUES (?, ?, 3, ?, 30, NULL, ?, 1, ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)',
                [(new UuidV7())->toBinary(), $pkgId, 'draft', $hash, $actor],
            );
            self::fail('Expected sequential version_number trigger');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement('UPDATE access_packages SET code = ? WHERE id = ?', ['changed_code', $pkgId]);
            self::fail('Expected immutable code trigger');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $conn->executeStatement('DELETE FROM access_package_versions WHERE id = ?', [$versionId]);
        $conn->executeStatement('DELETE FROM access_packages WHERE id = ?', [$pkgId]);
        AccessEntitlementDbCleanup::deleteAll($conn);
        if ($conn->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $conn->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($conn, [
            'security_audit_events',
            'security_bootstrap_guards',
            'reset_password_requests',
            'users',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $connection = $this->em->getConnection();
            AccessEntitlementDbCleanup::deleteAll($connection);
            QuestionBankDbCleanup::deleteTables($connection, [
                'security_audit_events',
                'security_bootstrap_guards',
                'reset_password_requests',
                'users',
            ]);
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}
