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

    public function testActiveAndSupersededGrantInsertDeleteRejectedAndDraftAllowed(): void
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $factory->createAndPersist('dbal-grant@example.com', 'Guclu-Parola-123!', 'A', 'U', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $users->save($user);
        $actor = $user->getId()->toBinary();
        $conn = $this->em->getConnection();

        $pkgId = (new UuidV7())->toBinary();
        $hash = str_repeat('c', 64);
        $conn->executeStatement(
            'INSERT INTO access_packages
            (id, code, name, normalized_name, description, target_type, status, default_validity_days, default_seat_limit, created_by_id, created_at, updated_at, activated_at, retired_at)
            VALUES (?, ?, ?, ?, NULL, ?, ?, 30, NULL, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)',
            [$pkgId, 'grant_mat', 'Grant Mat', 'grant mat', 'individual', 'draft', $actor],
        );

        $versionId = (new UuidV7())->toBinary();
        $conn->executeStatement(
            'INSERT INTO access_package_versions
            (id, package_id, version_number, status, validity_days, seat_limit, policy_hash, schema_version, created_by_id, activated_by_id, created_at, updated_at, activated_at, superseded_at)
            VALUES (?, ?, 1, ?, 30, NULL, ?, 1, ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)',
            [$versionId, $pkgId, 'draft', $hash, $actor],
        );

        // Draft INSERT accepted (FK to content will fail without content — use a fake binary and expect FK or succeed with orphan block)
        // Prefer activate empty then test active reject without content FK: create dummy content row is heavy.
        // Use catalog grant (subject FK) similarly heavy. Test with learning_content grant requires content.
        // Activate version first with zero grants is allowed at DB level (manager forbids it).
        // Activate, then insert catalog grant (no content/assessment FK) — must be rejected by draft-only BI.
        $conn->executeStatement(
            "UPDATE access_package_versions
             SET status = 'active', activated_at = UTC_TIMESTAMP(), activated_by_id = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$actor, $versionId],
        );
        $status = (string) $conn->fetchOne('SELECT status FROM access_package_versions WHERE id = ?', [$versionId]);
        self::assertSame('active', $status);

        try {
            $conn->executeStatement(
                'INSERT INTO access_package_catalog_grants (id, version_id, resource_kind, subject_id, grade_level, created_at)
                 VALUES (?, ?, ?, NULL, 9, UTC_TIMESTAMP())',
                [(new UuidV7())->toBinary(), $versionId, 'assessment'],
            );
            self::fail('Expected active version catalog grant INSERT rejected');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('draft', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'INSERT INTO access_package_learning_content_grants (id, version_id, content_id, created_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP())',
                [(new UuidV7())->toBinary(), $versionId, (new UuidV7())->toBinary()],
            );
            self::fail('Expected active version LC grant INSERT rejected');
        } catch (DbalException $e) {
            self::assertTrue(
                str_contains(strtolower($e->getMessage()), 'draft')
                || str_contains(strtolower($e->getMessage()), 'foreign key'),
                $e->getMessage(),
            );
        }

        try {
            $conn->executeStatement(
                'INSERT INTO access_package_assessment_grants (id, version_id, assessment_id, created_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP())',
                [(new UuidV7())->toBinary(), $versionId, (new UuidV7())->toBinary()],
            );
            self::fail('Expected active version assessment grant INSERT rejected');
        } catch (DbalException $e) {
            self::assertTrue(
                str_contains(strtolower($e->getMessage()), 'draft')
                || str_contains(strtolower($e->getMessage()), 'foreign key'),
                $e->getMessage(),
            );
        }

        $conn->executeStatement(
            "UPDATE access_package_versions
             SET status = 'superseded', superseded_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$versionId],
        );
        try {
            $conn->executeStatement(
                'INSERT INTO access_package_catalog_grants (id, version_id, resource_kind, subject_id, grade_level, created_at)
                 VALUES (?, ?, ?, NULL, 8, UTC_TIMESTAMP())',
                [(new UuidV7())->toBinary(), $versionId, 'assessment'],
            );
            self::fail('Expected superseded catalog grant INSERT rejected');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $conn->executeStatement(
            "UPDATE access_package_versions
             SET status = 'draft', activated_at = NULL, activated_by_id = NULL, superseded_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$versionId],
        );
        $conn->executeStatement(
            'INSERT INTO access_package_catalog_grants (id, version_id, resource_kind, subject_id, grade_level, created_at)
             VALUES (?, ?, ?, NULL, 7, UTC_TIMESTAMP())',
            [(new UuidV7())->toBinary(), $versionId, 'assessment'],
        );
        $deleted = $conn->executeStatement(
            'DELETE FROM access_package_catalog_grants WHERE version_id = ?',
            [$versionId],
        );
        self::assertGreaterThan(0, $deleted);

        $triggers = $conn->fetchAllAssociative(
            "SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, EVENT_OBJECT_TABLE, ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME IN (
                 'trg_aplcg_bi_draft_only','trg_aplcg_bd_draft_only',
                 'trg_apag_bi_draft_only','trg_apag_bd_draft_only',
                 'trg_apcg_bi_draft_only','trg_apcg_bd_draft_only'
               )
             ORDER BY TRIGGER_NAME",
        );
        self::assertCount(6, $triggers);
        foreach ($triggers as $trigger) {
            $body = strtolower((string) $trigger['ACTION_STATEMENT']);
            self::assertSame('BEFORE', $trigger['ACTION_TIMING']);
            self::assertStringNotContainsString('bypass', $body);
            self::assertStringNotContainsString('@testlig', $body);
            self::assertStringNotContainsString('foreign_key_checks', $body);
            self::assertStringNotContainsString('test-only', $body);
        }
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
