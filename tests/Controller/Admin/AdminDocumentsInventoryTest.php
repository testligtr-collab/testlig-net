<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
use App\Entity\LearningDocumentAsset;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Presentation\AdminDocumentsInventoryLabels;
use App\Repository\LearningDocumentAssetRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\LearningContentManager;
use App\Service\LearningDocumentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminDocumentsInventoryTest extends WebTestCase
{
    public function testSuperAdminSeesOnlyPlatformLinkedSafeFields(): void
    {
        $this->cleanupDocumentAssets();
        $sa = $this->activeUser('docs-inv-sa@example.com', UserRole::SuperAdmin);
        $admin = $this->activeUser('docs-inv-admin@example.com', UserRole::Admin);
        $teacher = $this->activeUser('docs-inv-teacher@example.com', UserRole::Teacher);
        $owner = $this->activeUser('docs-inv-owner@example.com', UserRole::InstitutionManager);

        [$subject, $outcome] = $this->seedCurriculum($sa, 'docs_inv');
        $institutions = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $institutions);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $memberships = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $memberships);
        $institution = $institutions->create($sa, $owner, 'Dokuman Kurum Gizli', InstitutionType::School, 'docs_inv_inst');
        $status->activate($institution, $sa, 'docs_inv_activate');
        $memberships->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'docs_inv_member');

        $documents = $this->documents();
        $secretName = 'gizli-platform-calisma.pdf';
        $platformPdf = $this->receivePdf($documents, $teacher, $secretName);
        $documents->markReady($admin, $this->handle($platformPdf));

        $orphanName = 'bagimsiz-orphan.pdf';
        $orphan = $this->receivePdf($documents, $teacher, $orphanName);
        $documents->markReady($admin, $this->handle($orphan));

        $institutionName = 'kurum-gizli-ders.pdf';
        $institutionPdf = $this->receivePdf($documents, $teacher, $institutionName);
        $documents->markReady($admin, $this->handle($institutionPdf));

        $contents = static::getContainer()->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $contents);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $subjectEntity = $em->find(Subject::class, $subject->getId());
        $outcomeEntity = $em->find(CurriculumLearningOutcome::class, $outcome->getId());
        $institutionEntity = $em->find(Institution::class, $institution->getId());
        $teacherEntity = $em->find(User::class, $teacher->getId());
        self::assertInstanceOf(Subject::class, $subjectEntity);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $outcomeEntity);
        self::assertInstanceOf(Institution::class, $institutionEntity);
        self::assertInstanceOf(User::class, $teacherEntity);

        $platformTitle = 'Platform Dokuman Envanter Ders';
        $platformContent = $contents->createDraft(
            $teacherEntity,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'docs_inv_platform',
            $platformTitle,
            null,
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [[
                    'type' => 'document',
                    'assetId' => $platformPdf->getId()->toRfc4122(),
                    'label' => 'Calisma',
                ]],
            ]),
            [['learningOutcome' => $outcomeEntity, 'isPrimary' => true]],
            'docs_inv_create',
        );

        $institutionTitle = 'Kurum Gizli Dokuman Ders';
        $contents->createDraft(
            $teacherEntity,
            LearningContentScope::Institution,
            $institutionEntity,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::Worksheet,
            'docs_inv_institution',
            $institutionTitle,
            null,
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [[
                    'type' => 'document',
                    'assetId' => $institutionPdf->getId()->toRfc4122(),
                    'label' => 'Kurum PDF',
                ]],
            ]),
            [['learningOutcome' => $outcomeEntity, 'isPrimary' => true]],
            'docs_inv_inst_create',
        );

        $before = $this->assetCount();
        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim/bolum/dokumanlar');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSelectorTextContains('h1', 'Dokümanlar');
        self::assertSelectorNotExists('.admin-stage');
        self::assertSelectorTextContains('body', '1 kayıt');
        self::assertSame($before, $this->assetCount(), 'GET must not write document assets');

        $rows = $crawler->filter('.admin-documents-inventory-page__table tbody tr');
        self::assertCount(1, $rows);
        self::assertSame(
            ['İçerik', 'Tür', 'Boyut', 'Durum'],
            $rows->filter('.admin-result-field__label')->each(
                static fn (Crawler $cell): string => trim($cell->text()),
            ),
        );
        self::assertSame(
            ['PDF', AdminDocumentsInventoryLabels::byteSize($platformPdf->getByteSize())],
            $rows->filter('.admin-result-field__value')->each(
                static fn (Crawler $cell): string => trim($cell->text()),
            ),
        );
        self::assertSame('Kullanıma hazır', trim($rows->filter('.admin-status')->text()));

        $open = $rows->filter('a.admin-documents-inventory-page__open');
        self::assertCount(1, $open);
        self::assertSame($platformTitle, trim($open->text()));
        $router = static::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        self::assertSame(
            $router->generate('app_admin_learning_content', ['id' => $platformContent->getId()->toRfc4122()]),
            $open->attr('href'),
        );

        $html = $crawler->html();
        foreach ([
            $secretName,
            $orphanName,
            $institutionName,
            $platformPdf->getStorageKey(),
            $platformPdf->getContentSha256(),
            $orphan->getOriginalName(),
            $institutionPdf->getStorageKey(),
            $institutionTitle,
            $institution->getName(),
            $owner->getEmail(),
            $teacher->getEmail(),
            $sa->getEmail(),
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $html);
        }
        $page = $crawler->filter('.admin-documents-inventory-page');
        self::assertGreaterThanOrEqual(1, $page->count());
        $pageHtml = $page->html();
        self::assertStringNotContainsString('Yükle', $pageHtml);
        self::assertStringNotContainsString('Sil', $pageHtml);
        self::assertStringNotContainsString('İndir', $pageHtml);
        self::assertSelectorNotExists('.admin-documents-inventory-page form');
        self::assertSelectorNotExists('.admin-documents-inventory-page button');
    }

    public function testEmptyStateAndAccessGates(): void
    {
        $this->cleanupDocumentAssets();
        $sa = $this->activeUser('docs-empty-sa@example.com', UserRole::SuperAdmin);
        $client = $this->browser();
        $client->loginUser($sa);
        $client->request('GET', '/yonetim/bolum/dokumanlar');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Gösterilecek platform dokümanı yok');
        self::assertSelectorTextContains('body', '0 kayıt');
        self::assertSelectorNotExists('.admin-documents-inventory-page__table');

        foreach ([
            ['docs-deny-admin@example.com', UserRole::Admin, 403],
            ['docs-deny-teacher@example.com', UserRole::Teacher, 403],
        ] as [$email, $role, $status]) {
            $user = $this->activeUser($email, $role);
            $denied = $this->browser();
            $denied->loginUser($user);
            $denied->request('GET', '/yonetim/bolum/dokumanlar');
            self::assertResponseStatusCodeSame($status);
        }

        $anon = $this->browser();
        $anon->request('GET', '/yonetim/bolum/dokumanlar');
        self::assertResponseRedirects('/giris');
    }

    public function testNavMarksDocumentsReadyAndCurrentOnce(): void
    {
        $this->cleanupDocumentAssets();
        $sa = $this->activeUser('docs-nav-sa@example.com', UserRole::SuperAdmin);
        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('a.admin-media-link[href="/yonetim/bolum/dokumanlar"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Çalışıyor', $card->text());
        self::assertStringNotContainsString('Geliştirme aşamasında', $card->text());

        $crawler = $client->request('GET', '/yonetim/bolum/dokumanlar');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#panel-sidebar a[href="/yonetim/bolum/dokumanlar"][aria-current="page"]'));
        self::assertNotNull($crawler->filter('#nav-group-media')->attr('open'));
    }

    private function documents(): LearningDocumentManager
    {
        $documents = static::getContainer()->get(LearningDocumentManager::class);
        self::assertInstanceOf(LearningDocumentManager::class, $documents);

        return $documents;
    }

    private function receivePdf(LearningDocumentManager $documents, User $actor, string $name): LearningDocumentAsset
    {
        $path = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'docs-inv-'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($path, "%PDF-1.4\n".$name."\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $file = new UploadedFile($path, $name, 'application/pdf', \UPLOAD_ERR_OK, true);

        return $documents->receive($actor, $file);
    }

    private function handle(LearningDocumentAsset $asset): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);

        return hash_hmac('sha256', $asset->getId()->toRfc4122(), $secret);
    }

    /**
     * @return array{0: Subject, 1: CurriculumLearningOutcome}
     */
    private function seedCurriculum(User $sa, string $prefix): array
    {
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

        $subject = $subjects->create($sa, $prefix.'_s', 'Subj '.$prefix, 'docs_inv_cs');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'docs_inv_cp');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'docs_inv_cu');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'docs_inv_ct');
        $outcome = $outcomes->create($topic, $sa, $prefix.'_lo', 'O', 1, 'docs_inv_clo');
        $programs->publish($program, $sa, 'docs_inv_pp');

        return [$subject, $outcome];
    }

    private function assetCount(): int
    {
        $repo = static::getContainer()->get(LearningDocumentAssetRepository::class);
        self::assertInstanceOf(LearningDocumentAssetRepository::class, $repo);

        return $repo->count([]);
    }

    private function activeUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Dok', 'Man', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function cleanupDocumentAssets(): void
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['learning_document_assets'])) {
            $connection->executeStatement('DELETE FROM learning_document_assets');
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupDocumentAssets();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            if ($connection->createSchemaManager()->tablesExist(['institution_memberships'])) {
                $connection->executeStatement(
                    "DELETE FROM institution_memberships WHERE institution_id IN (SELECT id FROM institutions WHERE name = 'Dokuman Kurum Gizli')",
                );
            }
            if ($connection->createSchemaManager()->tablesExist(['institutions'])) {
                $connection->executeStatement("DELETE FROM institutions WHERE name = 'Dokuman Kurum Gizli'");
            }
            if ($connection->createSchemaManager()->tablesExist(['users'])) {
                $connection->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'docs-%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
