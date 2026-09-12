<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\LearningContentRevisionRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

final class LearningContentDbalIntegrityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testChecksTriggersSealedAndPrimaryGuard(): void
    {
        $sa = $this->superAdmin('lc-dbal-sa@example.com');
        $subjects = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjects);
        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programs);
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $topics);
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);
        $contents = static::getContainer()->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $contents);

        $subject = $subjects->create($sa, 'lc_dbal_sub', 'DBAL Subject', 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade9, 'lc_dbal', 'DBAL', '1.0', 'create_p');
        $unit = $units->create($program, $sa, 'u1', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, 't1', 'T', 1, 'create_t');
        $lo = $outcomes->create($topic, $sa, 'lo_dbal', 'Outcome', 1, 'create_lo');
        $lo2 = $outcomes->create($topic, $sa, 'lo_dbal2', 'Outcome 2', 2, 'create_lo2');

        $content = $contents->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::Document,
            'lc_dbal_doc',
            'DBAL Doc',
            null,
            LearningContentDocument::paragraph('Body'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_c',
        );
        $revisionRepo = static::getContainer()->get(LearningContentRevisionRepository::class);
        self::assertInstanceOf(LearningContentRevisionRepository::class, $revisionRepo);
        $revision = $revisionRepo->findOneByContentAndNumber($content, 1);
        self::assertInstanceOf(LearningContentRevision::class, $revision);
        $conn = $this->em->getConnection();

        // Scope/institution null-pair CHECK
        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET institution_id = ? WHERE id = ?',
                [(new UuidV7())->toBinary(), $content->getId()->toBinary()],
            );
            self::fail('scope institution check');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Published pointer null-pair CHECK
        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET published_revision_id = ?, published_revision_number = NULL WHERE id = ?',
                [$revision->getId()->toBinary(), $content->getId()->toBinary()],
            );
            self::fail('published pair check');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Primary alignment uniqueness via generated column
        try {
            $conn->insert('learning_content_outcome_alignments', [
                'id' => (new UuidV7())->toBinary(),
                'revision_id' => $revision->getId()->toBinary(),
                'curriculum_program_id' => $program->getId()->toBinary(),
                'subject_id' => $subject->getId()->toBinary(),
                'curriculum_topic_id' => $topic->getId()->toBinary(),
                'learning_outcome_id' => $lo2->getId()->toBinary(),
                'is_primary' => 1,
                'position' => 1,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            self::fail('second primary rejected');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Seal then block content mutation / delete
        $contents->submitForReview($content, $sa, 'submit');
        $this->em->refresh($revision);
        self::assertTrue($revision->isSealed());

        try {
            $conn->executeStatement(
                'UPDATE learning_content_revisions SET language = ? WHERE id = ?',
                ['en', $revision->getId()->toBinary()],
            );
            self::fail('sealed update rejected');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('immutable', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'DELETE FROM learning_content_revisions WHERE id = ?',
                [$revision->getId()->toBinary()],
            );
            self::fail('revision delete rejected');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Unsealed publication rejected
        try {
            $conn->insert('learning_content_publications', [
                'id' => (new UuidV7())->toBinary(),
                'content_id' => $content->getId()->toBinary(),
                'revision_id' => $revision->getId()->toBinary(),
                'publication_number' => 1,
                'content_hash' => $revision->getContentHash(),
                'published_by_id' => $sa->getId()->toBinary(),
                'published_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            // revision is sealed so this should succeed for BI check — publish path uses sealed.
            // Force a second insert mutation on publication to verify append-only.
            $conn->executeStatement(
                'UPDATE learning_content_publications SET schema_version = 2 WHERE content_id = ?',
                [$content->getId()->toBinary()],
            );
            self::fail('publication update rejected');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Ready requires clean scan
        try {
            $conn->insert('stored_media_assets', [
                'id' => (new UuidV7())->toBinary(),
                'scope' => 'platform',
                'institution_id' => null,
                'kind' => 'image',
                'status' => 'ready',
                'scan_status' => 'pending',
                'storage_provider' => 'local',
                'storage_key' => 'media/platform/image/x/y',
                'original_filename' => 'x.png',
                'mime_type' => 'image/png',
                'byte_size' => 10,
                'content_sha256' => str_repeat('aa', 32),
                'created_by_id' => $sa->getId()->toBinary(),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ready_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'quarantined_at' => null,
                'archived_at' => null,
            ]);
            self::fail('ready requires clean');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertInstanceOf(LearningContent::class, $this->em->find(LearningContent::class, $content->getId()));
    }

    private function superAdmin(string $email): \App\Entity\User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function rebind(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
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
