<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicLesson;
use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\CatalogException;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\UserRepository;
use App\Service\CatalogTopicLessonManager;
use App\Service\CatalogWriteService;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\LearningContentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogTopicLessonDomainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CatalogWriteService $catalog;
    private CatalogTopicLessonManager $placements;
    private LearningContentManager $contents;
    private SubjectManager $subjects;
    private UserFactory $factory;
    private UserRepository $users;

    private function rebind(): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $catalog = $c->get(CatalogWriteService::class);
        self::assertInstanceOf(CatalogWriteService::class, $catalog);
        $this->catalog = $catalog;
        $placements = $c->get(CatalogTopicLessonManager::class);
        self::assertInstanceOf(CatalogTopicLessonManager::class, $placements);
        $this->placements = $placements;
        $contents = $c->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $contents);
        $this->contents = $contents;
        $subjects = $c->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjects);
        $this->subjects = $subjects;
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
    }

    private function reopenIfClosed(): void
    {
        if ($this->em->isOpen()) {
            return;
        }
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebind();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testUniqueSlugPositionAndContentBinding(): void
    {
        [$admin, $topic, $content] = $this->seedPublishedContentBundle('uniq');

        $this->placements->create($admin, $topic->getId(), $content->getId(), 'Adım 1', null, 0, 'create_1', 'adim-1');

        try {
            $this->placements->create($admin, $topic->getId(), $content->getId(), 'Adım 1b', null, 1, 'dup_content', 'adim-1b');
            self::fail('Expected duplicate content binding rejection');
        } catch (CatalogException) {
            $this->reopenIfClosed();
        }

        // Second content on the same canonical subject (cannot clear mapping while placements exist).
        $other = $this->createDraftContent($admin, $content->getSubject(), 'uniq_other', 'Other');
        try {
            $this->placements->create($admin, $topic->getId(), $other->getId(), 'Adım 2', null, 0, 'dup_pos', 'adim-2');
            self::fail('Expected duplicate position rejection');
        } catch (CatalogException) {
            $this->reopenIfClosed();
        }

        try {
            $this->placements->create($admin, $topic->getId(), $other->getId(), 'Adım 3', null, 2, 'dup_slug', 'adim-1');
            self::fail('Expected duplicate slug rejection');
        } catch (CatalogException) {
            $this->reopenIfClosed();
        }
    }

    public function testPublishRequiresPublishedLearningContentAndArchiveIsIrreversible(): void
    {
        [$admin, $topic, $draftContent] = $this->seedDraftContentBundle('pub');
        $lesson = $this->placements->create(
            $admin,
            $topic->getId(),
            $draftContent->getId(),
            'Taslak adım',
            null,
            0,
            'create',
            'taslak-adim',
        );
        self::assertSame(CatalogPublicationStatus::Draft, $lesson->getVisibilityStatus());

        try {
            $this->placements->publish($admin, $lesson->getId(), 'publish_early');
            self::fail('Expected unpublished LC rejection');
        } catch (CatalogException $e) {
            self::assertStringContainsString('yayımlanmadan', $e->getMessage());
            $this->reopenIfClosed();
        }

        $this->publishContent($admin, $draftContent);
        $this->em->clear();
        $draftContent = $this->em->find(LearningContent::class, $draftContent->getId());
        self::assertInstanceOf(LearningContent::class, $draftContent);
        $published = $this->placements->publish($admin, $lesson->getId(), 'publish_ok');
        self::assertSame(CatalogPublicationStatus::Published, $published->getVisibilityStatus());
        self::assertNotNull($published->getPublishedAt());

        $archived = $this->placements->archive($admin, $published->getId(), 'archive_ok');
        self::assertSame(CatalogPublicationStatus::Archived, $archived->getVisibilityStatus());

        try {
            $this->placements->publish($admin, $archived->getId(), 'reopen');
            self::fail('Expected archived reopen rejection');
        } catch (CatalogException) {
            $this->reopenIfClosed();
        }

        try {
            $this->placements->updateDraft($admin, $archived->getId(), 'X Title', null, 9, 'edit_archived');
            self::fail('Expected archived edit rejection');
        } catch (CatalogException) {
            $this->reopenIfClosed();
        }
    }

    public function testCanonicalSubjectMismatchRejectedAndNameSlugNotAutoMapped(): void
    {
        $sa = $this->superAdmin('map-sa@example.com');
        $admin = $this->activeUser('map-admin@example.com', UserRole::Admin);
        $math = $this->subjects->create($sa, 'ctl_math_a', 'Math A', 'create_s');
        $other = $this->subjects->create($sa, 'ctl_math_b', 'Math B', 'create_s2');

        $catalogSubject = $this->catalog->createSubject(GradeLevel::Grade1, 'Matematik Katalog', null, 1);
        // Same display name as Subject must NOT auto-map.
        self::assertNull($catalogSubject->getCanonicalSubject());
        $this->catalog->assignCanonicalSubject($admin, $catalogSubject->getId(), $math->getId());
        $this->em->refresh($catalogSubject);
        self::assertTrue($math->getId()->equals($catalogSubject->getCanonicalSubject()?->getId()));

        $unit = $this->catalog->createUnit($catalogSubject->getId(), 'Tema', null, 0);
        $topic = $this->catalog->createTopic($unit->getId(), 'Konu', null, 0, 10);
        $wrongContent = $this->createDraftContent($admin, $other, 'ctl_wrong', 'Wrong Subject Content');

        try {
            $this->placements->create($admin, $topic->getId(), $wrongContent->getId(), 'Adım', null, 0, 'bad_map');
            self::fail('Expected subject mapping mismatch');
        } catch (CatalogException $e) {
            self::assertStringContainsString('canonical', $e->getMessage());
            $this->reopenIfClosed();
        }

        // Clearing mapping allows bind without subject match enforcement.
        $this->catalog->assignCanonicalSubject($admin, $catalogSubject->getId(), null);
        $this->em->clear();
        $catalogSubject = $this->em->find(\App\Entity\CatalogSubject::class, $catalogSubject->getId());
        self::assertNotNull($catalogSubject);
        self::assertNull($catalogSubject->getCanonicalSubject());
        $lesson = $this->placements->create($admin, $topic->getId(), $wrongContent->getId(), 'Adım', null, 0, 'ok_clear');
        self::assertInstanceOf(CatalogTopicLesson::class, $lesson);
    }

    public function testTeacherCannotPublishPlacement(): void
    {
        [$admin, $topic, $content] = $this->seedPublishedContentBundle('teach');
        $teacher = $this->activeUser('ctl-teacher@example.com', UserRole::Teacher);
        $lesson = $this->placements->create($teacher, $topic->getId(), $content->getId(), 'Öğretmen adımı', null, 0, 't_create');

        try {
            $this->placements->publish($teacher, $lesson->getId(), 't_publish');
            self::fail('Teacher must not publish placement');
        } catch (CatalogException) {
            $this->reopenIfClosed();
        }

        $this->placements->publish($admin, $lesson->getId(), 'admin_publish');
        $fresh = $this->em->find(CatalogTopicLesson::class, $lesson->getId());
        self::assertInstanceOf(CatalogTopicLesson::class, $fresh);
        self::assertTrue($fresh->isPublished());
    }

    public function testHardDeleteNotExposedAndRollbackOnConflict(): void
    {
        $publicNames = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(CatalogTopicLessonManager::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        self::assertNotContains('delete', $publicNames);
        self::assertNotContains('remove', $publicNames);

        [$admin, $topic, $content] = $this->seedPublishedContentBundle('rb');
        $this->placements->create($admin, $topic->getId(), $content->getId(), 'Adim A', null, 0, 'a', 'a');
        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_topic_lessons');

        try {
            $this->placements->create($admin, $topic->getId(), $content->getId(), 'Adim B', null, 0, 'b', 'b');
            self::fail('Expected conflict');
        } catch (CatalogException) {
            $this->reopenIfClosed();
        }

        $after = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_topic_lessons');
        self::assertSame($before, $after);
    }

    /**
     * @return array{0: User, 1: CatalogTopic, 2: LearningContent}
     */
    private function seedPublishedContentBundle(string $prefix): array
    {
        [$admin, $topic, $content] = $this->seedDraftContentBundle($prefix);
        $this->publishContent($admin, $content);
        $this->em->refresh($content);

        return [$admin, $topic, $content];
    }

    /**
     * @return array{0: User, 1: CatalogTopic, 2: LearningContent}
     */
    private function seedDraftContentBundle(string $prefix): array
    {
        $sa = $this->superAdmin($prefix.'-sa@example.com');
        $admin = $this->activeUser($prefix.'-admin@example.com', UserRole::Admin);
        $subject = $this->subjects->create($sa, $prefix.'_subj', 'Subject '.$prefix, 'create_s');
        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programs);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        self::assertInstanceOf(CurriculumTopicManager::class, $topics);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);

        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_prog', 'Prog', '1.0', 'create_p');
        $cUnit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'create_u');
        $cTopic = $topics->createRoot($cUnit, $sa, $prefix.'_t', 'T', 1, 'create_t');
        $lo = $outcomes->create($cTopic, $sa, $prefix.'_lo', 'Outcome', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');

        $catalogSubject = $this->catalog->createSubject(GradeLevel::Grade1, 'Ders '.$prefix, null, 1);
        $this->catalog->assignCanonicalSubject($admin, $catalogSubject->getId(), $subject->getId());
        $unit = $this->catalog->createUnit($catalogSubject->getId(), 'Tema '.$prefix, null, 0);
        $topic = $this->catalog->createTopic($unit->getId(), 'Konu '.$prefix, null, 0, 15);
        $this->catalog->publishSubject($catalogSubject->getId());
        $this->catalog->publishUnit($unit->getId());
        $this->catalog->publishTopic($topic->getId());

        $content = $this->contents->createDraft(
            $admin,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            $prefix.'_lc',
            'Content '.$prefix,
            null,
            LearningContentDocument::paragraph('Body'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_lc',
        );

        return [$admin, $topic, $content];
    }

    private function createDraftContent(User $actor, Subject $subject, string $code, string $title): LearningContent
    {
        /** @var \App\Repository\CurriculumProgramRepository $programRepo */
        $programRepo = static::getContainer()->get(\App\Repository\CurriculumProgramRepository::class);
        /** @var \App\Repository\CurriculumLearningOutcomeRepository $outcomeRepo */
        $outcomeRepo = static::getContainer()->get(\App\Repository\CurriculumLearningOutcomeRepository::class);
        $published = $programRepo->findPublishedForSubjectAndGrade($subject, GradeLevel::Grade1);
        if ([] === $published) {
            $sa = $this->superAdmin($code.'-sa2@example.com');
            $programs = static::getContainer()->get(CurriculumProgramManager::class);
            $units = static::getContainer()->get(CurriculumUnitManager::class);
            $topics = static::getContainer()->get(CurriculumTopicManager::class);
            $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
            self::assertInstanceOf(CurriculumProgramManager::class, $programs);
            self::assertInstanceOf(CurriculumUnitManager::class, $units);
            self::assertInstanceOf(CurriculumTopicManager::class, $topics);
            self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);
            $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $code.'_p', 'P', '1.0', 'cp');
            $cUnit = $units->create($program, $sa, $code.'_u', 'U', 1, 'cu');
            $cTopic = $topics->createRoot($cUnit, $sa, $code.'_t', 'T', 1, 'ct');
            $lo = $outcomes->create($cTopic, $sa, $code.'_lo', 'O', 1, 'clo');
            $programs->publish($program, $sa, 'pp');
        } else {
            $los = $outcomeRepo->findByProgram($published[0]);
            self::assertNotEmpty($los);
            $lo = $los[0];
        }

        return $this->contents->createDraft(
            $actor,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            $code,
            $title,
            null,
            LearningContentDocument::paragraph('Body'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create',
        );
    }

    private function publishContent(User $author, LearningContent $content): void
    {
        $reviewer = $this->activeUser($content->getCode().'-rev@example.com', UserRole::HeadTeacher);
        $this->contents->submitForReview($content, $author, 'submit');
        $this->contents->publish($content, $reviewer, 'publish');
    }

    private function superAdmin(string $email): User
    {
        $user = $this->activeUser($email, UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function activeUser(string $email, UserRole $role): User
    {
        $createAs = \in_array($role, [UserRole::Admin, UserRole::Moderator, UserRole::SuperAdmin], true)
            ? UserRole::Student
            : $role;
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $createAs);
        if ($createAs !== $role) {
            $user->addGlobalRole($role);
        }
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $sm = $conn->createSchemaManager();
        if ($sm->tablesExist(['catalog_topic_lessons'])) {
            $conn->executeStatement('DELETE FROM catalog_topic_lessons');
        }
        if ($sm->tablesExist(['catalog_topics'])) {
            $conn->executeStatement('DELETE FROM catalog_topics');
        }
        if ($sm->tablesExist(['catalog_units'])) {
            $conn->executeStatement('DELETE FROM catalog_units');
        }
        if ($sm->tablesExist(['catalog_subjects'])) {
            $conn->executeStatement('DELETE FROM catalog_subjects');
        }
        LearningContentDbCleanup::deleteLearningContents($conn);
        if ($sm->tablesExist(['curriculum_topics'])) {
            $conn->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($conn, [
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
