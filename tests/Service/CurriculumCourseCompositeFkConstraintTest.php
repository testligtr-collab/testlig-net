<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\CurriculumProgramManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

final class CurriculumCourseCompositeFkConstraintTest extends KernelTestCase
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

    public function testProgramSubjectCompositeFkRejectsMismatch(): void
    {
        [$classroomId, $yearId, $institutionId, $subjectA, $subjectB, $programId] = $this->seed();

        $courseId = (new UuidV7())->toBinary();
        $rejected = false;
        try {
            $this->em->getConnection()->insert('classroom_courses', [
                'id' => $courseId,
                'institution_id' => $institutionId,
                'academic_year_id' => $yearId,
                'classroom_id' => $classroomId,
                'subject_id' => $subjectB,
                'curriculum_program_id' => $programId,
                'status' => 'active',
                'weekly_lesson_hours' => null,
                'archived_at' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);
        } catch (ForeignKeyConstraintViolationException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'subject/program mismatch must fail FK');
        unset($subjectA);
    }

    public function testInformationSchemaListsRequiredCompositeFks(): void
    {
        $required = [
            'FK_TOPIC_PARENT_SAME_UNIT',
            'FK_CC_CLASSROOM_YEAR_INSTITUTION',
            'FK_CC_CLASSROOM_INSTITUTION',
            'FK_CC_YEAR_INSTITUTION',
            'FK_CC_PROGRAM_SUBJECT',
            'FK_CC_ACTIVE_GUARD_COURSE_KEYS',
            'FK_CTEACH_COURSE_INSTITUTION',
            'FK_CTEACH_MEMBERSHIP_INSTITUTION',
            'FK_CTEACH_ACTIVE_GUARD_ASSIGNMENT_KEYS',
        ];

        $connection = $this->em->getConnection();
        $database = $connection->getDatabase();
        self::assertNotFalse($database);
        self::assertNotEmpty($database);

        $placeholders = [];
        $params = ['db' => $database];
        foreach ($required as $i => $name) {
            $key = 'c'.$i;
            $placeholders[] = ':'.$key;
            $params[$key] = $name;
        }

        $found = $connection->fetchFirstColumn(
            'SELECT DISTINCT tc.CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS tc
             INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND tc.TABLE_NAME = kcu.TABLE_NAME
             WHERE tc.CONSTRAINT_SCHEMA = :db
               AND tc.CONSTRAINT_TYPE = \'FOREIGN KEY\'
               AND tc.CONSTRAINT_NAME IN ('.implode(',', $placeholders).')',
            $params,
        );

        sort($required);
        $found = array_values(array_unique(array_map('strval', $found)));
        sort($found);
        self::assertSame($required, $found, 'All required composite FKs must exist in information_schema');
    }

    public function testTopicParentMustShareUnit(): void
    {
        $sa = $this->sa();
        $subjectMgr = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjectMgr);
        $programMgr = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programMgr);
        $unitRepo = $this->em->getRepository(\App\Entity\CurriculumUnit::class);

        $subject = $subjectMgr->create($sa, 'fk_subj', 'FK Subject', 'create_subj');
        $program = $programMgr->createDraft($subject, $sa, GradeLevel::Grade3, 'fk_p', 'P', '1.0', 'create_draft');
        $unitMgr = static::getContainer()->get(\App\Service\CurriculumUnitManager::class);
        self::assertInstanceOf(\App\Service\CurriculumUnitManager::class, $unitMgr);
        $u1 = $unitMgr->create($program, $sa, 'u1', 'U1', 1, 'create_u1');
        $u2 = $unitMgr->create($program, $sa, 'u2', 'U2', 2, 'create_u2');
        $topicMgr = static::getContainer()->get(\App\Service\CurriculumTopicManager::class);
        self::assertInstanceOf(\App\Service\CurriculumTopicManager::class, $topicMgr);
        $root = $topicMgr->createRoot($u1, $sa, 'r1', 'R1', 1, 'create_root');

        $badId = (new UuidV7())->toBinary();
        $rejected = false;
        try {
            $this->em->getConnection()->insert('curriculum_topics', [
                'id' => $badId,
                'unit_id' => $u2->getId()->toBinary(),
                'parent_id' => $root->getId()->toBinary(),
                'code' => 'bad',
                'title' => 'Bad',
                'normalized_title' => 'bad',
                'position' => 1,
                'estimated_minutes' => null,
                'status' => 'active',
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);
        } catch (ForeignKeyConstraintViolationException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'cross-unit parent FK must fail');
        unset($unitRepo);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    private function seed(): array
    {
        $sa = $this->sa();
        $owner = $this->activeUser('fk-owner@example.com');
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $institution = $creator->create($sa, $owner, 'FK School', InstitutionType::School, 'create_inst');
        $status->activate($institution, $sa, 'activate');
        $yearMgr = static::getContainer()->get(AcademicYearManager::class);
        self::assertInstanceOf(AcademicYearManager::class, $yearMgr);
        $year = $yearMgr->createPlanned(
            $institution,
            $owner,
            'FK Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'create_year',
        );
        $yearMgr->activate($year, $owner, 'activate_year');
        $clsMgr = static::getContainer()->get(ClassroomManager::class);
        self::assertInstanceOf(ClassroomManager::class, $clsMgr);
        $classroom = $clsMgr->create($year, $owner, 'FK 9A', GradeLevel::Grade9, 'create_cls', 'A', 20);

        $subjectMgr = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjectMgr);
        $programMgr = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programMgr);
        $subjectA = $subjectMgr->create($sa, 'fk_a', 'A', 'create_a');
        $subjectB = $subjectMgr->create($sa, 'fk_b', 'B', 'create_b');
        $program = $programMgr->createDraft($subjectA, $sa, GradeLevel::Grade9, 'fkpa', 'PA', '1.0', 'create_prog');
        $programMgr->publish($program, $sa, 'publish');

        return [
            $classroom->getId()->toBinary(),
            $year->getId()->toBinary(),
            $institution->getId()->toBinary(),
            $subjectA->getId()->toBinary(),
            $subjectB->getId()->toBinary(),
            $program->getId()->toBinary(),
        ];
    }

    private function sa(): User
    {
        $user = $this->activeUser('fk-sa@example.com');
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
        QuestionBankDbCleanup::deleteTables($connection, [
            'course_teacher_active_guards',
            'course_teacher_assignments',
            'classroom_course_active_guards',
            'classroom_courses',
            'question_revision_primary_alignment_guards',
            'question_revision_alignments',
            'question_answer_keys',
            'question_revision_options',
            'question_revisions',
            'questions',
            'curriculum_learning_outcomes',
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
        ]);
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
