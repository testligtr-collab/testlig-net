<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\CatalogTopic;
use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\ResourceAccessClass;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\LearningContentException;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\CatalogImport\CatalogImportService;
use App\Service\CatalogPublish\CatalogPublishTreeService;
use App\Service\CatalogTopicLessonManager;
use App\Service\CatalogWriteService;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\LearningDocumentManager;
use App\Service\StudentProfileManager;
use App\Service\SubjectManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\LearningContentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class StudentTopicPageControllerTest extends WebTestCase
{
    private const FIXTURE = 'data/catalog/meb/tymm-2026/grade-1-matematik.yaml';
    private const SECRET_BODY = 'SECRET_REVISION_BODY_MARKER_9f3a';
    private const SECRET_STORAGE = 'platform/media/SECRET_STORAGE_KEY_MARKER_7c2b.bin';

    public function testAnonymousTopicRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ogrenci/dersler/matematik/nesnelerin-geometrisi-1/uzamsal-iliskiler');
        self::assertResponseRedirects('/giris');
    }

    public function testTeacherForbiddenOnStudentTopicRoute(): void
    {
        $this->createActive('topic-teacher@example.com', UserRole::Teacher);
        $client = static::createClient();
        $this->login($client, 'topic-teacher@example.com');
        $client->request('GET', '/ogrenci/dersler/matematik/nesnelerin-geometrisi-1/uzamsal-iliskiler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminForbiddenOnStudentTopicRoute(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $factory->createAndPersist('topic-admin-deny@example.com', 'Guclu-Parola-123!', 'A', 'U', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        $user->addGlobalRole(UserRole::Admin);
        $users->save($user);
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'topic-admin-deny@example.com');
        $client->request('GET', '/ogrenci/dersler/matematik/nesnelerin-geometrisi-1/uzamsal-iliskiler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPublishedTopicEmptyStateAndNineteenRoutes(): void
    {
        $this->importAndPublishTymm();

        $user = $this->createActive('topic-g1@example.com', UserRole::Student);
        $this->completeOnboarding($user, GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'topic-g1@example.com');

        $client->request('GET', '/ogrenci/dersler/matematik/nesnelerin-geometrisi-1/uzamsal-iliskiler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Uzamsal İlişkiler');
        self::assertSelectorTextContains('body', 'Bu konu için öğrenme adımları hazırlanıyor.');
        self::assertSelectorTextContains('.student-breadcrumb', 'Panel');
        self::assertSelectorTextContains('.student-breadcrumb', 'Dersler');
        self::assertSelectorTextContains('.student-breadcrumb', 'Matematik');

        $paths = $this->collectPublishedTopicPaths();
        self::assertCount(19, $paths);
        foreach ($paths as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Bu konu için öğrenme adımları hazırlanıyor.');
        }

        $client->request('GET', '/ogrenci/dersler/matematik/nesnelerin-geometrisi-1');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/ogrenci/dersler/matematik/nesnelerin-geometrisi-1/uzamsal-iliskiler"]');
    }

    public function testWrongSlugCombinationsAndOtherGradeAreOpaque404(): void
    {
        $this->importAndPublishTymm();
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogWriteService $writer */
        $writer = static::getContainer()->get(CatalogWriteService::class);
        $math5 = $writer->createSubject(GradeLevel::Grade5, 'Matematik Beş Topic', null, 1);
        $writer->publishSubject($math5->getId());
        $unit5 = $writer->createUnit($math5->getId(), 'Tema Beş', null, 0);
        $writer->publishUnit($unit5->getId());
        $topic5 = $writer->createTopic($unit5->getId(), 'Konu Beş', null, 0, 10);
        $writer->publishTopic($topic5->getId());
        self::ensureKernelShutdown();

        $user = $this->createActive('topic-404@example.com', UserRole::Student);
        $this->completeOnboarding($user, GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'topic-404@example.com');

        $client->request('GET', '/ogrenci/dersler/matematik/yanlis-unite/uzamsal-iliskiler');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/ogrenci/dersler/matematik/nesnelerin-geometrisi-1/yanlis-konu');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/ogrenci/dersler/yanlis-ders/nesnelerin-geometrisi-1/uzamsal-iliskiler');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/ogrenci/dersler/matematik-bes-topic/tema-bes/konu-bes');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPlacementVisibilityFiltersAndNoSecretLeak(): void
    {
        [$subjectSlug, $unitSlug, $topicSlug, $topicId] = $this->seedPublishedTopicHierarchy('vis');
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var CatalogTopicLessonManager $placements */
        $placements = static::getContainer()->get(CatalogTopicLessonManager::class);
        /** @var LearningContentManager $contents */
        $contents = static::getContainer()->get(LearningContentManager::class);
        /** @var AccessPackageManager $packages */
        $packages = static::getContainer()->get(AccessPackageManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $topic = $em->find(CatalogTopic::class, $topicId);
        self::assertInstanceOf(CatalogTopic::class, $topic);
        $canonical = $topic->getUnit()->getSubject()->getCanonicalSubject();
        self::assertInstanceOf(Subject::class, $canonical);

        $admin = $this->activeStaff('vis-admin@example.com', UserRole::Admin);
        $sa = $this->activeStaff('vis-sa@example.com', UserRole::SuperAdmin);

        $visible = $this->createAndPublishContent($admin, $canonical, 'vis_ok', 'Visible Lesson Title', 'Safe visible paragraph');
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

        $draftLc = $this->createDraftContent($admin, $canonical, 'vis_draft_lc', 'Draft LC');
        $draftPlacement = $placements->create(
            $admin,
            $topic->getId(),
            $draftLc->getId(),
            'Taslak Adım',
            null,
            1,
            'create_draft',
            'taslak-adim',
        );
        self::assertSame('draft', $draftPlacement->getVisibilityStatus()->value);

        $denied = $this->createAndPublishContent($admin, $canonical, 'vis_deny', 'Denied Body', self::SECRET_BODY);
        $packages->setLearningContentAccessPolicy($denied, $sa, ResourceAccessClass::EntitlementRequired, 'set_ent');
        $deniedLesson = $placements->create(
            $admin,
            $topic->getId(),
            $denied->getId(),
            'Kapalı Adım',
            null,
            2,
            'create_deny',
            'kapali-adim',
        );
        $placements->publish($admin, $deniedLesson->getId(), 'pub_deny');

        $archivedLc = $this->createAndPublishContent($admin, $canonical, 'vis_arch', 'Archived Body');
        $packages->setLearningContentAccessPolicy($archivedLc, $sa, ResourceAccessClass::Free, 'set_free_arch');
        $archivedLesson = $placements->create(
            $admin,
            $topic->getId(),
            $archivedLc->getId(),
            'Arşiv Adım',
            null,
            3,
            'create_arch',
            'arsiv-adim',
        );
        $placements->publish($admin, $archivedLesson->getId(), 'pub_arch');
        $placements->archive($admin, $archivedLesson->getId(), 'arch');

        $unpubLcBundle = $this->createAndPublishContent($admin, $canonical, 'vis_unpub', 'Unpublished Body Title', self::SECRET_BODY);
        $packages->setLearningContentAccessPolicy($unpubLcBundle, $sa, ResourceAccessClass::Free, 'set_free_unpub');
        $unpubLesson = $placements->create(
            $admin,
            $topic->getId(),
            $unpubLcBundle->getId(),
            'Gizli Gövde Adım',
            null,
            4,
            'create_unpub',
            'gizli-govde',
        );
        $placements->publish($admin, $unpubLesson->getId(), 'pub_unpub');
        $contents->archive($unpubLcBundle, $admin, 'archive_lc');

        self::ensureKernelShutdown();

        $student = $this->createActive('topic-vis@example.com', UserRole::Student);
        $this->completeOnboarding($student, GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'topic-vis@example.com');

        $path = \sprintf('/ogrenci/dersler/%s/%s/%s', $subjectSlug, $unitSlug, $topicSlug);
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Görünür Adım');
        self::assertSelectorTextContains('body', 'Kısa özet');
        self::assertSelectorTextContains('body', 'Safe visible paragraph');
        self::assertSelectorNotExists('body:contains("Taslak Adım")');
        self::assertSelectorNotExists('body:contains("Kapalı Adım")');
        self::assertSelectorNotExists('body:contains("Arşiv Adım")');
        self::assertSelectorNotExists('body:contains("Gizli Gövde Adım")');
        $html = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString(self::SECRET_BODY, $html);
        self::assertStringNotContainsString(self::SECRET_STORAGE, $html);
        self::assertStringNotContainsString('storageKey', $html);
        self::assertStringNotContainsString('content_json', $html);
        self::assertStringNotContainsString('structuredContent', $html);
        $cacheControl = $client->getResponse()->headers->get('Cache-Control') ?? '';
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testTypedBlocksRenderInOrderWithEscapingAndOpaqueDenies(): void
    {
        [$subjectSlug, $unitSlug, $topicSlug, $topicId] = $this->seedPublishedTopicHierarchy('blk');
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var CatalogTopicLessonManager $placements */
        $placements = static::getContainer()->get(CatalogTopicLessonManager::class);
        /** @var AccessPackageManager $packages */
        $packages = static::getContainer()->get(AccessPackageManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $topic = $em->find(CatalogTopic::class, $topicId);
        self::assertInstanceOf(CatalogTopic::class, $topic);
        $canonical = $topic->getUnit()->getSubject()->getCanonicalSubject();
        self::assertInstanceOf(Subject::class, $canonical);

        $admin = $this->activeStaff('blk-admin@example.com', UserRole::Admin);
        $sa = $this->activeStaff('blk-sa@example.com', UserRole::SuperAdmin);

        $document = new LearningContentDocument(LearningContentDocument::SCHEMA_VERSION, [
            ['type' => 'heading', 'level' => 1, 'text' => 'Baslik Bir'],
            ['type' => 'paragraph', 'text' => 'Tom & Jerry "alinti"'],
            ['type' => 'list', 'items' => ['Elma', 'Armut']],
            ['type' => 'callout', 'variant' => 'tip', 'blocks' => [
                ['type' => 'paragraph', 'text' => 'Ipucu metni'],
            ]],
            ['type' => 'quote', 'text' => 'Alinti blogu'],
            ['type' => 'math', 'latex' => 'x + y = 2'],
        ]);

        $first = $this->createAndPublishContentWithDocument($admin, $canonical, 'blk_a', 'Ilk Adim', $document);
        $packages->setLearningContentAccessPolicy($first, $sa, ResourceAccessClass::Free, 'set_free_a');
        $lessonA = $placements->create($admin, $topic->getId(), $first->getId(), 'Ilk Adim', 'Ozet A', 0, 'create_a', 'ilk-adim');
        $placements->publish($admin, $lessonA->getId(), 'pub_a');

        $secondDoc = LearningContentDocument::paragraph('Ikinci govde');
        $second = $this->createAndPublishContentWithDocument($admin, $canonical, 'blk_b', 'Ikinci Adim', $secondDoc);
        $packages->setLearningContentAccessPolicy($second, $sa, ResourceAccessClass::Free, 'set_free_b');
        $lessonB = $placements->create($admin, $topic->getId(), $second->getId(), 'Ikinci Adim', null, 1, 'create_b', 'ikinci-adim');
        $placements->publish($admin, $lessonB->getId(), 'pub_b');

        self::ensureKernelShutdown();

        $student = $this->createActive('topic-blk@example.com', UserRole::Student);
        $this->completeOnboarding($student, GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'topic-blk@example.com');

        $path = \sprintf('/ogrenci/dersler/%s/%s/%s', $subjectSlug, $unitSlug, $topicSlug);
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent() ?: '';

        self::assertSelectorTextContains('.student-content-heading', 'Baslik Bir');
        self::assertSelectorTextContains('.student-content-paragraph', 'Tom & Jerry "alinti"');
        self::assertSelectorTextContains('.student-content-list', 'Elma');
        self::assertSelectorTextContains('.student-content-callout--tip', 'Ipucu metni');
        self::assertSelectorTextContains('.student-content-quote', 'Alinti blogu');
        self::assertSelectorTextContains('.student-content-math', 'x + y = 2');
        self::assertStringContainsString('&amp;', $html);
        self::assertStringNotContainsString('javascript:', $html);

        $posA = strpos($html, 'Ilk Adim');
        $posB = strpos($html, 'Ikinci Adim');
        self::assertNotFalse($posA);
        self::assertNotFalse($posB);
        self::assertLessThan($posB, $posA);

        self::assertStringNotContainsString('storageKey', $html);
        self::assertStringNotContainsString(self::SECRET_BODY, $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);
    }

    public function testAnonymousDocumentRouteRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ogrenci/dersler/matematik/nesnelerin-geometrisi-1/uzamsal-iliskiler/adim/pdf/0');
        self::assertResponseRedirects('/giris');
    }

    public function testPublishedVideoAndDocumentRespectAccessGateAndHeaders(): void
    {
        [$subjectSlug, $unitSlug, $topicSlug, $topicId] = $this->seedPublishedTopicHierarchy('med');
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var LearningDocumentManager $documents */
        $documents = static::getContainer()->get(LearningDocumentManager::class);
        /** @var CatalogTopicLessonManager $placements */
        $placements = static::getContainer()->get(CatalogTopicLessonManager::class);
        /** @var AccessPackageManager $packages */
        $packages = static::getContainer()->get(AccessPackageManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $topic = $em->find(CatalogTopic::class, $topicId);
        self::assertInstanceOf(CatalogTopic::class, $topic);
        $canonical = $topic->getUnit()->getSubject()->getCanonicalSubject();
        self::assertInstanceOf(Subject::class, $canonical);

        $admin = $this->activeStaff('med-admin@example.com', UserRole::Admin);
        $teacher = $this->activeStaff('med-teacher@example.com', UserRole::Teacher);
        $sa = $this->activeStaff('med-sa@example.com', UserRole::SuperAdmin);

        $open = $this->receivePdf($documents, $teacher, 'Notlar.pdf');
        try {
            $documents->markReady($teacher, $this->handle($open->getId()->toRfc4122()));
            self::fail('Teacher must not approve a PDF.');
        } catch (LearningContentException $e) {
            self::assertStringContainsString('yalnız yönetici', $e->getMessage());
        }
        $documents->markReady($admin, $this->handle($open->getId()->toRfc4122()));

        $closed = $this->receivePdf($documents, $teacher, 'Kapali.pdf');
        $documents->markReady($sa, $this->handle($closed->getId()->toRfc4122()));

        $visible = $this->createAndPublishContentWithDocument(
            $admin,
            $canonical,
            'med_open',
            'Acik ders',
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [[
                    'type' => 'video',
                    'provider' => 'youtube',
                    'providerVideoId' => 'dQw4w9WgXcQ',
                    'title' => 'Konu videosu',
                    'description' => 'Kisa aciklama',
                ], [
                    'type' => 'document',
                    'assetId' => $open->getId()->toRfc4122(),
                    'label' => 'Calisma kagidi',
                ]],
            ]),
        );
        $packages->setLearningContentAccessPolicy($visible, $sa, ResourceAccessClass::Free, 'set_free_med');
        $visibleLesson = $placements->create($admin, $topic->getId(), $visible->getId(), 'Medya Adim', null, 0, 'create_med', 'medya-adim');
        $placements->publish($admin, $visibleLesson->getId(), 'pub_med');

        $hidden = $this->createAndPublishContentWithDocument(
            $admin,
            $canonical,
            'med_closed',
            'Kapali ders',
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [[
                    'type' => 'document',
                    'assetId' => $closed->getId()->toRfc4122(),
                    'label' => 'Kapali dokuman',
                ], [
                    'type' => 'video',
                    'provider' => 'vimeo',
                    'providerVideoId' => '123456789',
                    'title' => 'Kapali video',
                    'description' => '',
                ]],
            ]),
        );
        $packages->setLearningContentAccessPolicy($hidden, $sa, ResourceAccessClass::EntitlementRequired, 'set_ent_med');
        $hiddenLesson = $placements->create($admin, $topic->getId(), $hidden->getId(), 'Kapali Adim', null, 1, 'create_closed', 'kapali-medya');
        $placements->publish($admin, $hiddenLesson->getId(), 'pub_closed');
        self::ensureKernelShutdown();

        $student = $this->createActive('med-student@example.com', UserRole::Student);
        $this->completeOnboarding($student, GradeLevel::Grade1);
        $other = $this->createActive('med-other@example.com', UserRole::Student);
        $this->completeOnboarding($other, GradeLevel::Grade5);

        $client = static::createClient();
        $this->login($client, 'med-student@example.com');
        $topicPath = \sprintf('/ogrenci/dersler/%s/%s/%s', $subjectSlug, $unitSlug, $topicSlug);
        $crawler = $client->request('GET', $topicPath);
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        self::assertStringContainsString('Konu videosu', $html);
        self::assertStringContainsString('Notlar.pdf', $html);
        self::assertStringContainsString('PDF’yi aç', $html);
        self::assertStringContainsString('noopener', $html);
        self::assertStringNotContainsString('www.youtube.com/watch', $html);
        self::assertStringNotContainsString('Kapali.pdf', $html);
        self::assertStringNotContainsString('Kapali video', $html);
        self::assertStringNotContainsString('storageKey', $html);
        self::assertStringNotContainsString('learning-documents', $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);

        $openLink = $crawler->filter('.student-content-document a')->attr('href');
        self::assertNotNull($openLink);
        $client->request('GET', $openLink);
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $cache = $client->getResponse()->headers->get('Cache-Control') ?? '';
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
        $pdfResponse = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $pdfResponse);
        ob_start();
        $pdfResponse->sendContent();
        $pdfBytes = ob_get_clean();
        self::assertIsString($pdfBytes);
        self::assertStringStartsWith('%PDF-', $pdfBytes);

        $client->request('GET', $topicPath.'/kapali-medya/pdf/0');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $topicPath.'/medya-adim/pdf/9');
        self::assertResponseStatusCodeSame(404);

        $client = static::createClient();
        $this->login($client, 'med-other@example.com');
        $client->request('GET', $openLink);
        self::assertResponseStatusCodeSame(404);
    }

    private function receivePdf(LearningDocumentManager $documents, User $actor, string $name): \App\Entity\LearningDocumentAsset
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertNotFalse($path);
        file_put_contents($path, "%PDF-1.4\n".$name."\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $file = new UploadedFile($path, $name, 'application/pdf', \UPLOAD_ERR_OK, true);

        return $documents->receive($actor, $file);
    }

    private function handle(string $uuid): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);

        return hash_hmac('sha256', $uuid, $secret);
    }

    /**
     * @return list<string>
     */
    private function collectPublishedTopicPaths(): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogSubjectRepository $subjects */
        $subjects = static::getContainer()->get(CatalogSubjectRepository::class);
        /** @var CatalogUnitRepository $units */
        $units = static::getContainer()->get(CatalogUnitRepository::class);
        /** @var CatalogTopicRepository $topics */
        $topics = static::getContainer()->get(CatalogTopicRepository::class);
        $subject = $subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        $paths = [];
        foreach ($units->findPublishedBySubject($subject) as $unit) {
            foreach ($topics->findPublishedByUnit($unit) as $topic) {
                $paths[] = \sprintf(
                    '/ogrenci/dersler/%s/%s/%s',
                    $subject->getSlug(),
                    $unit->getSlug(),
                    $topic->getSlug(),
                );
            }
        }
        self::ensureKernelShutdown();

        return $paths;
    }

    private function importAndPublishTymm(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->cleanupCatalogAndContent();
        /** @var CatalogImportService $import */
        $import = static::getContainer()->get(CatalogImportService::class);
        /** @var CatalogPublishTreeService $publisher */
        $publisher = static::getContainer()->get(CatalogPublishTreeService::class);
        $path = \dirname(__DIR__, 2).\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, self::FIXTURE);
        $import->import($path, apply: true, updateExisting: false);
        $publisher->publish('TYMM-2026', 'MAT', 1, 7, 19, apply: true);
        self::ensureKernelShutdown();
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: Uuid}
     */
    private function seedPublishedTopicHierarchy(string $prefix): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->cleanupCatalogAndContent();

        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var CatalogWriteService $catalog */
        $catalog = static::getContainer()->get(CatalogWriteService::class);

        $sa = $this->activeStaff($prefix.'-sa-seed@example.com', UserRole::SuperAdmin);
        $subject = $subjects->create($sa, $prefix.'_subj', 'Subject '.$prefix, 'create_s');
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
        $outcomes->create($cTopic, $sa, $prefix.'_lo', 'Outcome', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');

        $catalogSubject = $catalog->createSubject(GradeLevel::Grade1, 'Matematik '.$prefix, null, 1);
        $catalog->assignCanonicalSubject($sa, $catalogSubject->getId(), $subject->getId());
        $unit = $catalog->createUnit($catalogSubject->getId(), 'Tema '.$prefix, null, 0);
        $topic = $catalog->createTopic($unit->getId(), 'Konu '.$prefix, 'Özet', 0, 15);
        $catalog->publishSubject($catalogSubject->getId());
        $catalog->publishUnit($unit->getId());
        $catalog->publishTopic($topic->getId());

        $result = [$catalogSubject->getSlug(), $unit->getSlug(), $topic->getSlug(), $topic->getId()];
        self::ensureKernelShutdown();

        return $result;
    }

    private function createAndPublishContentWithDocument(
        User $author,
        Subject $subject,
        string $code,
        string $title,
        LearningContentDocument $document,
    ): LearningContent {
        $content = $this->createDraftContentWithDocument($author, $subject, $code, $title, $document);
        $reviewer = $this->activeStaff($code.'-rev@example.com', UserRole::HeadTeacher);
        /** @var LearningContentManager $contents */
        $contents = static::getContainer()->get(LearningContentManager::class);
        $contents->submitForReview($content, $author, 'submit');
        $contents->publish($content, $reviewer, 'publish');

        return $content;
    }

    private function createDraftContentWithDocument(
        User $actor,
        Subject $subject,
        string $code,
        string $title,
        LearningContentDocument $document,
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
            $document,
            [['learningOutcome' => $los[0], 'isPrimary' => true]],
            'create',
        );
    }

    private function createAndPublishContent(
        User $author,
        Subject $subject,
        string $code,
        string $title,
        ?string $bodyText = null,
    ): LearningContent {
        return $this->createAndPublishContentWithDocument(
            $author,
            $subject,
            $code,
            $title,
            LearningContentDocument::paragraph($bodyText ?? $title),
        );
    }

    private function createDraftContent(
        User $actor,
        Subject $subject,
        string $code,
        string $title,
        ?string $bodyText = null,
    ): LearningContent {
        return $this->createDraftContentWithDocument(
            $actor,
            $subject,
            $code,
            $title,
            LearningContentDocument::paragraph($bodyText ?? $title),
        );
    }

    private function activeStaff(string $email, UserRole $role): User
    {
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $createAs = \in_array($role, [UserRole::Admin, UserRole::Moderator, UserRole::SuperAdmin], true)
            ? UserRole::Student
            : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $createAs);
        if ($createAs !== $role) {
            $user->addGlobalRole($role);
        }
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $users->save($user);

        return $user;
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

    private function createActive(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ayşe', 'Yılmaz', $role);
        $lifecycle->markEmailVerifiedAndActivate($user);
        self::ensureKernelShutdown();

        return $user;
    }

    private function completeOnboarding(User $user, GradeLevel $grade): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $fresh = $users->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        /** @var StudentProfileManager $manager */
        $manager = static::getContainer()->get(StudentProfileManager::class);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $grade;
        $manager->completeOnboarding($fresh, $dto);
        self::ensureKernelShutdown();
    }

    private function cleanupCatalogAndContent(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $sm = $conn->createSchemaManager();
        if ($sm->tablesExist(['catalog_topic_lessons'])) {
            $conn->executeStatement('DELETE FROM catalog_topic_lessons');
        }
        AccessEntitlementDbCleanup::deleteAll($conn);
        LearningContentDbCleanup::deleteLearningContents($conn);
        foreach (['catalog_topics', 'catalog_units', 'catalog_subjects'] as $table) {
            if ($sm->tablesExist([$table])) {
                $conn->executeStatement('DELETE FROM '.$table);
            }
        }
        if ($sm->tablesExist(['curriculum_topics'])) {
            $conn->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($conn, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $this->cleanupCatalogAndContent();
            /** @var EntityManagerInterface $em */
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $conn = $em->getConnection();
            $sm = $conn->createSchemaManager();
            foreach (['student_profiles', 'users'] as $table) {
                if ($sm->tablesExist([$table])) {
                    $conn->executeStatement('DELETE FROM '.$table);
                }
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
