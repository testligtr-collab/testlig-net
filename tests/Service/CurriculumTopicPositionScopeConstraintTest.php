<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Curriculum\CurriculumTopicPositionScope;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumUnitManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * MariaDB-level sibling position uniqueness via generated position_scope_id.
 */
final class CurriculumTopicPositionScopeConstraintTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $this->cleanup();
    }

    public function testInformationSchemaGeneratedColumnAndUniqueIndex(): void
    {
        $connection = $this->em->getConnection();
        $schema = $connection->fetchOne('SELECT DATABASE()');
        self::assertIsString($schema);

        $column = $connection->fetchAssociative(
            'SELECT COLUMN_NAME, GENERATION_EXPRESSION, IS_GENERATED, EXTRA, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$schema, 'curriculum_topics', CurriculumTopicPositionScope::COLUMN_NAME],
        );
        self::assertIsArray($column);
        self::assertSame('ALWAYS', $column['IS_GENERATED']);
        self::assertStringContainsString('STORED GENERATED', (string) $column['EXTRA']);
        $expression = strtolower(preg_replace('/\s+/', '', (string) $column['GENERATION_EXPRESSION']) ?? '');
        self::assertSame(CurriculumTopicPositionScope::GENERATION_EXPRESSION, $expression);

        $indexRows = $connection->fetchAllAssociative(
            'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
             ORDER BY SEQ_IN_INDEX',
            [$schema, 'curriculum_topics', CurriculumTopicPositionScope::UNIQUE_INDEX_NAME],
        );
        self::assertCount(3, $indexRows);
        self::assertSame(0, (int) $indexRows[0]['NON_UNIQUE']);
        self::assertSame(['unit_id', 'position_scope_id', 'position'], array_column($indexRows, 'COLUMN_NAME'));

        $legacy = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$schema, 'curriculum_topics', 'uniq_curriculum_topic_unit_parent_position'],
        );
        self::assertSame(0, $legacy);
    }

    public function testDbalRootAndChildSiblingPositionUniqueness(): void
    {
        [$unitA, $unitB] = $this->twoUnits();
        $connection = $this->em->getConnection();

        $root1 = $this->insertTopic($unitA, null, 'r1', 1);
        $this->insertTopic($unitA, null, 'r2', 2);

        $rejected = false;
        try {
            $this->insertTopic($unitA, null, 'r_dup', 1);
        } catch (UniqueConstraintViolationException $e) {
            $rejected = true;
            self::assertStringContainsString(
                CurriculumTopicPositionScope::UNIQUE_INDEX_NAME,
                $e->getMessage(),
            );
        }
        self::assertTrue($rejected, 'duplicate root position must hit uniq_curriculum_topic_unit_scope_position');

        $scopeRoot = $connection->fetchOne(
            'SELECT position_scope_id FROM curriculum_topics WHERE id = ?',
            [$root1],
            [ParameterType::BINARY],
        );
        self::assertSame(CurriculumTopicPositionScope::rootSentinelBinary(), $scopeRoot);

        $child1 = $this->insertTopic($unitA, $root1, 'c1', 1);
        $this->insertTopic($unitA, $root1, 'c2', 2);

        $rejectedChild = false;
        try {
            $this->insertTopic($unitA, $root1, 'c_dup', 1);
        } catch (UniqueConstraintViolationException $e) {
            $rejectedChild = true;
            self::assertStringContainsString(
                CurriculumTopicPositionScope::UNIQUE_INDEX_NAME,
                $e->getMessage(),
            );
        }
        self::assertTrue($rejectedChild);

        $scopeChild = $connection->fetchOne(
            'SELECT position_scope_id FROM curriculum_topics WHERE id = ?',
            [$child1],
            [ParameterType::BINARY],
        );
        self::assertSame($root1, $scopeChild);

        $rootOther = $this->insertTopic($unitA, null, 'r3', 3);
        $this->insertTopic($unitA, $rootOther, 'c_other', 1);

        $this->insertTopic($unitB, null, 'r_b', 1);
    }

    /**
     * @return array{0: string, 1: string} unit binaries
     */
    private function twoUnits(): array
    {
        $sa = $this->sa();
        $subjectMgr = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjectMgr);
        $programMgr = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programMgr);
        $unitMgr = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $unitMgr);

        $subject = $subjectMgr->create($sa, 'scope_subj', 'Scope', 'create');
        $program = $programMgr->createDraft($subject, $sa, GradeLevel::Grade4, 'scope_p', 'P', '1.0', 'draft');
        $u1 = $unitMgr->create($program, $sa, 'u1', 'U1', 1, 'u1');
        $u2 = $unitMgr->create($program, $sa, 'u2', 'U2', 2, 'u2');

        return [$u1->getId()->toBinary(), $u2->getId()->toBinary()];
    }

    private function insertTopic(string $unitId, ?string $parentId, string $code, int $position): string
    {
        $id = (new UuidV7())->toBinary();
        CurriculumTopicPositionScope::assertNotRootSentinel(Uuid::fromBinary($id));
        $this->em->getConnection()->insert('curriculum_topics', [
            'id' => $id,
            'unit_id' => $unitId,
            'parent_id' => $parentId,
            'code' => $code,
            'title' => $code,
            'normalized_title' => $code,
            'position' => $position,
            'estimated_minutes' => null,
            'status' => 'active',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ]);

        return $id;
    }

    private function sa(): User
    {
        $user = $this->activeUser('scope-sa@example.com');
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function activeUser(string $email): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        foreach ([
            'course_teacher_active_guards',
            'course_teacher_assignments',
            'classroom_course_active_guards',
            'classroom_courses',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'academic_year_student_enrollment_guards',
            'classroom_student_enrollments',
            'classroom_teacher_active_guards',
            'classroom_homeroom_guards',
            'classroom_teacher_assignments',
            'classrooms',
            'institution_active_academic_year_guards',
            'academic_years',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'security_bootstrap_guards',
            'reset_password_requests',
            'users',
        ] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}
