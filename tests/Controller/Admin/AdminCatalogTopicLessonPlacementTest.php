<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Dto\StudentProfileRequest;
use App\Entity\CatalogTopic;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\ResourceAccessClass;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\CatalogTopicLessonManager;
use App\Service\CatalogWriteService;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\StudentProfileManager;
use App\Service\SubjectManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\LearningContentDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminCatalogTopicLessonPlacementTest extends WebTestCase
{
    private const SECRET_BODY = 'SECRET_PLACEMENT_BODY_MARKER_a1b2';
    private const SECRET_STORAGE = 'platform/media/SECRET_PLACEMENT_KEY_c3d4.bin';

    public function testCreatePublishArchiveAndDuplicateRejects(): void
    {
        [$topicId, $publishedContentId, $draftContentId, $mismatchContentId] = $this->seedPlacementBundle('plc');
        $this->createPrivileged('plc-admin@example.com', UserRole::Admin);

        $client = $this->newClient();
        $this->login($client, 'plc-admin@example.com');

        $client->request('POST', '/yonetim/mufredat/konu/'.$topicId->toRfc4122().'/yerlesim', [
            '_token' => 'bad',
            'learning_content_id' => $publishedContentId->toRfc4122(),
            'display_title' => 'Adım 1',
            'slug' => 'adim-1',
            'position' => '0',
            'note' => 'admin_placement_create',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action$="/yerlesim"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $client->request('POST', '/yonetim/mufredat/konu/'.$topicId->toRfc4122().'/yerlesim', [
            '_token' => $token,
            'learning_content_id' => $draftContentId->toRfc4122(),
            'display_title' => 'Draft bind',
            'slug' => 'draft-bind',
            'position' => '0',
            'note' => 'admin_placement_create',
        ]);
        // Draft LC is bindable as draft placement; publish of placement will fail later.
        // Prefer published list in UI — draft id should not be in select; posting it still hits manager.
        // Unpublished(not archived) is allowed on create; publish rejects.
        self::assertResponseRedirects();
        $client->followRedirect();

        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        $token = $crawler->filter('form[action$="/yerlesim"] input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/mufredat/konu/'.$topicId->toRfc4122().'/yerlesim', [
            '_token' => $token,
            'learning_content_id' => $mismatchContentId->toRfc4122(),
            'display_title' => 'Mismatch',
            'slug' => 'mismatch',
            'position' => '1',
            'note' => 'admin_placement_create',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'eşmuyor');

        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        $token = $crawler->filter('form[action$="/yerlesim"] input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/mufredat/konu/'.$topicId->toRfc4122().'/yerlesim', [
            '_token' => $token,
            'learning_content_id' => $publishedContentId->toRfc4122(),
            'display_title' => 'Görünür Adım',
            'slug' => 'gorunur-adim',
            'summary' => 'Kısa özet',
            'position' => '2',
            'note' => 'admin_placement_create',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Görünür Adım');

        // Duplicate slug
        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        $token = $crawler->filter('form[action$="/yerlesim"] input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/mufredat/konu/'.$topicId->toRfc4122().'/yerlesim', [
            '_token' => $token,
            'learning_content_id' => $publishedContentId->toRfc4122(),
            'display_title' => 'Dup slug',
            'slug' => 'gorunur-adim',
            'position' => '3',
            'note' => 'admin_placement_create',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'kısa adres');

        // Duplicate content (same LC again with new slug/pos)
        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        $token = $crawler->filter('form[action$="/yerlesim"] input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/mufredat/konu/'.$topicId->toRfc4122().'/yerlesim', [
            '_token' => $token,
            'learning_content_id' => $publishedContentId->toRfc4122(),
            'display_title' => 'Dup content',
            'slug' => 'dup-content',
            'position' => '3',
            'note' => 'admin_placement_create',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'zaten bağlı');

        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        $publishForm = $crawler->filter('form[action*="/yerlesim/"][action$="/yayimla"]')->first();
        self::assertGreaterThan(0, $publishForm->count());
        $publishAction = $publishForm->attr('action');
        self::assertNotNull($publishAction);
        $publishToken = $publishForm->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($publishToken);

        // Publish without confirm
        $client->request('POST', $publishAction, [
            '_token' => $publishToken,
            'note' => 'admin_placement_publish',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'onay kutusu');

        // Publish draft LC placement (from earlier bind) should fail
        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        $draftRow = $crawler->filter('tr:contains("Draft bind")');
        if ($draftRow->count() > 0) {
            $draftForm = $draftRow->filter('form[action$="/yayimla"]');
            if ($draftForm->count() > 0) {
                $client->request('POST', (string) $draftForm->attr('action'), [
                    '_token' => (string) $draftForm->filter('input[name="_token"]')->attr('value'),
                    'confirm_publish' => '1',
                    'note' => 'admin_placement_publish',
                ]);
                self::assertResponseRedirects();
                $client->followRedirect();
                self::assertSelectorTextContains('body', 'yayımlanmadan');
            }
        }

        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        $okRow = $crawler->filter('tr:contains("Görünür Adım")');
        self::assertGreaterThan(0, $okRow->count());
        $okForm = $okRow->filter('form[action$="/yayimla"]');
        $client->request('POST', (string) $okForm->attr('action'), [
            '_token' => (string) $okForm->filter('input[name="_token"]')->attr('value'),
            'confirm_publish' => '1',
            'note' => 'admin_placement_publish',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'published');

        $crawler = $client->request('GET', '/yonetim/mufredat/konu/'.$topicId->toRfc4122());
        $okRow = $crawler->filter('tr:contains("Görünür Adım")');
        $archForm = $okRow->filter('form[action$="/arsivle"]');
        self::assertGreaterThan(0, $archForm->count());
        $client->request('POST', (string) $archForm->attr('action'), [
            '_token' => (string) $archForm->filter('input[name="_token"]')->attr('value'),
            'note' => 'admin_placement_archive',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'archived');
    }

    public function testStudentTopicVisibilityFreeAndAndGateNoBodyLeak(): void
    {
        [$subjectSlug, $unitSlug, $topicSlug, $topicId, $canonicalId] = $this->seedPublishedCatalog('stu');
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var CatalogTopicLessonManager $placements */
        $placements = static::getContainer()->get(CatalogTopicLessonManager::class);
        /** @var AccessPackageManager $packages */
        $packages = static::getContainer()->get(AccessPackageManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $topic = $em->find(CatalogTopic::class, $topicId);
        $canonical = $em->find(Subject::class, $canonicalId);
        self::assertInstanceOf(CatalogTopic::class, $topic);
        self::assertInstanceOf(Subject::class, $canonical);

        $admin = $this->loadOrCreate('stu-admin@example.com', UserRole::Admin);
        $sa = $this->loadOrCreate('stu-sa@example.com', UserRole::SuperAdmin);

        $visible = $this->createAndPublishContent($admin, $canonical, 'stu_ok', 'Visible Title', self::SECRET_BODY);
        $packages->setLearningContentAccessPolicy($visible, $sa, ResourceAccessClass::Free, 'set_free');
        $visibleLesson = $placements->create(
            $admin,
            $topic->getId(),
            $visible->getId(),
            'Görünür Adım',
            'Kısa özet',
            0,
            'create_vis',
            'gorunur-adim',
        );
        $placements->publish($admin, $visibleLesson->getId(), 'pub_vis');

        $draftLc = $this->createDraftContent($admin, $canonical, 'stu_draft', 'Draft LC');
        $placements->create($admin, $topic->getId(), $draftLc->getId(), 'Taslak Adım', null, 1, 'create_d', 'taslak-adim');

        $denied = $this->createAndPublishContent($admin, $canonical, 'stu_deny', 'Denied');
        $packages->setLearningContentAccessPolicy($denied, $sa, ResourceAccessClass::EntitlementRequired, 'set_ent');
        $deniedLesson = $placements->create($admin, $topic->getId(), $denied->getId(), 'Kapalı Adım', null, 2, 'create_deny', 'kapali');
        $placements->publish($admin, $deniedLesson->getId(), 'pub_deny');

        $archivedLc = $this->createAndPublishContent($admin, $canonical, 'stu_arch', 'Archived');
        $packages->setLearningContentAccessPolicy($archivedLc, $sa, ResourceAccessClass::Free, 'set_free_a');
        $archivedLesson = $placements->create($admin, $topic->getId(), $archivedLc->getId(), 'Arşiv Adım', null, 3, 'create_a', 'arsiv');
        $placements->publish($admin, $archivedLesson->getId(), 'pub_a');
        $placements->archive($admin, $archivedLesson->getId(), 'arch');

        self::ensureKernelShutdown();

        $student = $this->createActiveStudent('stu-g1@example.com', GradeLevel::Grade1);
        $client = $this->newClient();
        $this->login($client, 'stu-g1@example.com');
        $path = \sprintf('/ogrenci/dersler/%s/%s/%s', $subjectSlug, $unitSlug, $topicSlug);
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Görünür Adım');
        self::assertSelectorNotExists('body:contains("Taslak Adım")');
        self::assertSelectorNotExists('body:contains("Kapalı Adım")');
        self::assertSelectorNotExists('body:contains("Arşiv Adım")');
        $html = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString(self::SECRET_BODY, $html);
        self::assertStringNotContainsString(self::SECRET_STORAGE, $html);
        self::assertStringNotContainsString('storageKey', $html);

        $client->request('GET', '/ogrenci/dersler/'.$subjectSlug.'/'.$unitSlug.'/yanlis-konu');
        self::assertResponseStatusCodeSame(404);

        $this->createActiveStudent('stu-g5@example.com', GradeLevel::Grade5);
        $client = $this->newClient();
        $this->login($client, 'stu-g5@example.com');
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{0: Uuid, 1: Uuid, 2: Uuid, 3: Uuid}
     */
    private function seedPlacementBundle(string $prefix): array
    {
        [$subjectSlug, $unitSlug, $topicSlug, $topicId, $canonicalId] = $this->seedPublishedCatalog($prefix);
        unset($subjectSlug, $unitSlug, $topicSlug);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $canonical = $em->find(Subject::class, $canonicalId);
        self::assertInstanceOf(Subject::class, $canonical);
        $admin = $this->loadOrCreate($prefix.'-seed-admin@example.com', UserRole::Admin);
        $sa = $this->loadOrCreate($prefix.'-seed-sa@example.com', UserRole::SuperAdmin);

        $published = $this->createAndPublishContent($admin, $canonical, $prefix.'_pub', 'Published LC', 'body');
        /** @var AccessPackageManager $packages */
        $packages = static::getContainer()->get(AccessPackageManager::class);
        $packages->setLearningContentAccessPolicy($published, $sa, ResourceAccessClass::Free, 'set_free');

        $draft = $this->createDraftContent($admin, $canonical, $prefix.'_draft', 'Draft LC');

        $otherSubject = $this->createOtherSubjectWithOutcome($sa, $prefix.'_other');
        $mismatch = $this->createAndPublishContent($admin, $otherSubject, $prefix.'_mis', 'Mismatch LC');

        $ids = [$topicId, $published->getId(), $draft->getId(), $mismatch->getId()];
        self::ensureKernelShutdown();

        return $ids;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: Uuid, 4: Uuid}
     */
    private function seedPublishedCatalog(string $prefix): array
    {
        $sa = $this->createPrivileged($prefix.'-cat-sa@example.com', UserRole::SuperAdmin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var CatalogWriteService $catalog */
        $catalog = static::getContainer()->get(CatalogWriteService::class);
        /** @var CurriculumProgramManager $programs */
        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        /** @var CurriculumUnitManager $units */
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        /** @var CurriculumTopicManager $topics */
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        /** @var CurriculumLearningOutcomeManager $outcomes */
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $saUser = $em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $saUser);

        $subject = $subjects->create($saUser, $prefix.'_subj', 'Subject '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $saUser, GradeLevel::Grade1, $prefix.'_prog', 'Prog', '1.0', 'create_p');
        $cUnit = $units->create($program, $saUser, $prefix.'_u', 'U', 1, 'create_u');
        $cTopic = $topics->createRoot($cUnit, $saUser, $prefix.'_t', 'T', 1, 'create_t');
        $outcomes->create($cTopic, $saUser, $prefix.'_lo', 'Outcome', 1, 'create_lo');
        $programs->publish($program, $saUser, 'publish_p');

        $catalogSubject = $catalog->createSubject(GradeLevel::Grade1, 'Ders '.$prefix, null, 1);
        $catalog->assignCanonicalSubject($saUser, $catalogSubject->getId(), $subject->getId());
        $unit = $catalog->createUnit($catalogSubject->getId(), 'Tema '.$prefix, null, 0);
        $topic = $catalog->createTopic($unit->getId(), 'Konu '.$prefix, 'Özet', 0, 15);
        $catalog->publishSubject($catalogSubject->getId());
        $catalog->publishUnit($unit->getId());
        $catalog->publishTopic($topic->getId());

        $result = [
            $catalogSubject->getSlug(),
            $unit->getSlug(),
            $topic->getSlug(),
            $topic->getId(),
            $subject->getId(),
        ];
        self::ensureKernelShutdown();

        return $result;
    }

    private function createOtherSubjectWithOutcome(User $sa, string $prefix): Subject
    {
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var CurriculumProgramManager $programs */
        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        /** @var CurriculumUnitManager $units */
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        /** @var CurriculumTopicManager $topics */
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        /** @var CurriculumLearningOutcomeManager $outcomes */
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);

        $subject = $subjects->create($sa, $prefix.'_s', 'Other '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'cp');
        $cUnit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'cu');
        $cTopic = $topics->createRoot($cUnit, $sa, $prefix.'_t', 'T', 1, 'ct');
        $outcomes->create($cTopic, $sa, $prefix.'_lo', 'O', 1, 'clo');
        $programs->publish($program, $sa, 'pp');

        return $subject;
    }

    private function createAndPublishContent(
        User $author,
        Subject $subject,
        string $code,
        string $title,
        ?string $bodyText = null,
    ): LearningContent {
        $content = $this->createDraftContent($author, $subject, $code, $title, $bodyText);
        $reviewer = $this->loadOrCreate($code.'-rev@example.com', UserRole::HeadTeacher);
        /** @var LearningContentManager $contents */
        $contents = static::getContainer()->get(LearningContentManager::class);
        $contents->submitForReview($content, $author, 'submit');
        $contents->publish($content, $reviewer, 'publish');

        return $content;
    }

    private function createDraftContent(
        User $actor,
        Subject $subject,
        string $code,
        string $title,
        ?string $bodyText = null,
    ): LearningContent {
        /** @var LearningContentManager $contents */
        $contents = static::getContainer()->get(LearningContentManager::class);
        /** @var CurriculumProgramRepository $programs */
        $programs = static::getContainer()->get(CurriculumProgramRepository::class);
        /** @var CurriculumLearningOutcomeRepository $outcomes */
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeRepository::class);

        $published = $programs->findPublishedForSubjectAndGrade($subject, GradeLevel::Grade1);
        self::assertNotEmpty($published);
        $los = $outcomes->findByProgram($published[0]);
        self::assertNotEmpty($los);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $los[0]);

        return $contents->createDraft(
            $actor,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            $code,
            $title,
            null,
            LearningContentDocument::paragraph($bodyText ?? $title),
            [['learningOutcome' => $los[0], 'isPrimary' => true]],
            'create',
        );
    }

    private function loadOrCreate(string $email, UserRole $role): User
    {
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $existing = $users->findOneByNormalizedEmail(mb_strtolower($email));
        if ($existing instanceof User) {
            return $existing;
        }

        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Admin', 'User', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users->save($user);

        return $user;
    }

    private function createActiveStudent(string $email, GradeLevel $grade): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ayşe', 'Yılmaz', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);
        /** @var StudentProfileManager $profiles */
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $grade;
        $profiles->completeOnboarding($user, $dto);
        self::ensureKernelShutdown();

        return $user;
    }

    private function newClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    private function createPrivileged(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Admin', 'User', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        self::ensureKernelShutdown();

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $conn = $em->getConnection();
            $sm = $conn->createSchemaManager();
            if ($sm->tablesExist(['catalog_topic_lessons'])) {
                $conn->executeStatement('DELETE FROM catalog_topic_lessons');
            }
            if ($sm->tablesExist(['learning_content_access_policies'])) {
                $conn->executeStatement('DELETE FROM learning_content_access_policies');
            }
            LearningContentDbCleanup::deleteLearningContents($conn);
            foreach ([
                'curriculum_learning_outcomes',
                'curriculum_topics',
                'curriculum_units',
                'curriculum_programs',
                'catalog_topics',
                'catalog_units',
                'catalog_subjects',
                'subjects',
                'student_profiles',
                'security_audit_events',
                'users',
            ] as $table) {
                if ($sm->tablesExist([$table])) {
                    $conn->executeStatement('DELETE FROM '.$table);
                }
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
