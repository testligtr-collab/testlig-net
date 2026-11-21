<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
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
use App\Repository\LearningContentRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminVideosInventoryTest extends WebTestCase
{
    public function testSuperAdminSeesOnlyPlatformCurrentRevisionVideos(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $sa = $this->persistUser('vids-inv-sa@example.com', UserRole::SuperAdmin);
        $teacher = $this->persistUser('vids-inv-teacher@example.com', UserRole::Teacher);
        $owner = $this->persistUser('vids-inv-owner@example.com', UserRole::InstitutionManager);

        [$subject, $outcome] = $this->seedCurriculum($sa, 'vids_inv');
        $institutions = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $institutions);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $memberships = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $memberships);
        $institution = $institutions->create($sa, $owner, 'Video Kurum Gizli', InstitutionType::School, 'vids_inv_inst');
        $status->activate($institution, $sa, 'vids_inv_activate');
        $memberships->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'vids_inv_member');

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

        $platformTitle = 'Platform Video Envanter Ders';
        $secretYoutubeId = 'dQw4w9WgXcQ';
        $secretVimeoId = '987654321';
        $staleVideoId = 'OldRevVideo';
        $alignment = [['learningOutcome' => $outcomeEntity, 'isPrimary' => true]];

        $platformContent = $contents->createDraft(
            $teacherEntity,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'vids_inv_platform',
            $platformTitle,
            null,
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'text' => 'Serbest metinde https://youtu.be/'.$secretYoutubeId.' olmamali.',
                    ],
                    [
                        'type' => 'callout',
                        'variant' => 'info',
                        'blocks' => [
                            ['type' => 'paragraph', 'text' => 'Ic ice video yok; yalniz paragraf.'],
                        ],
                    ],
                    [
                        'type' => 'video',
                        'provider' => 'youtube',
                        'providerVideoId' => $secretYoutubeId,
                        'title' => 'Gizli youtube baslik',
                        'description' => 'Gizli youtube aciklama',
                    ],
                    [
                        'type' => 'video',
                        'provider' => 'vimeo',
                        'providerVideoId' => $secretVimeoId,
                        'title' => 'Gizli vimeo baslik',
                        'description' => '',
                    ],
                ],
            ]),
            $alignment,
            'vids_inv_create',
        );

        $staleTitle = 'Eski Revision Video Ders';
        $stale = $contents->createDraft(
            $teacherEntity,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'vids_inv_stale',
            $staleTitle,
            null,
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [[
                    'type' => 'video',
                    'provider' => 'youtube',
                    'providerVideoId' => $staleVideoId,
                    'title' => 'Eski revision videosu',
                    'description' => '',
                ]],
            ]),
            $alignment,
            'vids_inv_stale_create',
        );
        $contents->createRevision(
            $stale,
            $teacherEntity,
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Guncel surumde video yok.'],
                ],
            ]),
            $alignment,
            'vids_inv_stale_rev2',
        );

        $institutionTitle = 'Kurum Gizli Video Ders';
        $contents->createDraft(
            $teacherEntity,
            LearningContentScope::Institution,
            $institutionEntity,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::Worksheet,
            'vids_inv_institution',
            $institutionTitle,
            null,
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [[
                    'type' => 'video',
                    'provider' => 'youtube',
                    'providerVideoId' => 'InstVideo01',
                    'title' => 'Kurum video',
                    'description' => '',
                ]],
            ]),
            $alignment,
            'vids_inv_inst_create',
        );

        $before = $this->contentCount();
        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim/bolum/videolar');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSelectorTextContains('h1', 'Videolar');
        self::assertSelectorNotExists('.admin-stage');
        self::assertMatchesRegularExpression('/\d+ kayıt/u', $crawler->filter('.admin-videos-inventory-page__count')->text());
        self::assertSame($before, $this->contentCount(), 'GET must not write learning contents');

        $opens = $crawler->filter('a.admin-videos-inventory-page__open');
        $ourRows = $opens->reduce(
            static fn (Crawler $node): bool => $platformTitle === trim($node->text()),
        );
        self::assertCount(2, $ourRows);
        $router = static::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $expectedHref = $router->generate('app_admin_learning_content', ['id' => $platformContent->getId()->toRfc4122()]);
        self::assertSame($expectedHref, $ourRows->eq(0)->attr('href'));
        self::assertSame($expectedHref, $ourRows->eq(1)->attr('href'));

        $providerByTitle = [];
        $crawler->filter('.admin-videos-inventory-page__table tbody tr')->each(
            static function (Crawler $row) use (&$providerByTitle): void {
                $title = trim($row->filter('a.admin-videos-inventory-page__open')->text(''));
                $provider = trim($row->filter('.admin-result-field__value')->text(''));
                if ('' !== $title && '' !== $provider) {
                    $providerByTitle[$title][] = $provider;
                }
            },
        );
        self::assertSame(['YouTube', 'Vimeo'], $providerByTitle[$platformTitle] ?? null);
        self::assertArrayNotHasKey($staleTitle, $providerByTitle);
        self::assertArrayNotHasKey($institutionTitle, $providerByTitle);

        $html = $crawler->html();
        foreach ([
            $secretYoutubeId,
            $secretVimeoId,
            $staleVideoId,
            'https://youtu.be/',
            'https://vimeo.com/',
            'youtube-nocookie',
            'player.vimeo',
            'Gizli youtube baslik',
            'Gizli vimeo baslik',
            'Eski revision videosu',
            'InstVideo01',
            $institutionTitle,
            $staleTitle,
            $institution->getName(),
            $owner->getEmail(),
            $teacher->getEmail(),
            $sa->getEmail(),
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $html);
        }
        $page = $crawler->filter('.admin-videos-inventory-page');
        self::assertGreaterThanOrEqual(1, $page->count());
        $pageHtml = $page->html();
        self::assertStringNotContainsString('Yükle', $pageHtml);
        self::assertStringNotContainsString('Sil', $pageHtml);
        self::assertStringNotContainsString('iframe', $pageHtml);
        self::assertSelectorNotExists('.admin-videos-inventory-page form');
        self::assertSelectorNotExists('.admin-videos-inventory-page button');
    }

    public function testEmptyStateAndAccessGates(): void
    {
        $sa = $this->activeUser('vids-empty-sa@example.com', UserRole::SuperAdmin);
        $client = $this->browser();
        $client->loginUser($sa);
        $client->request('GET', '/yonetim/bolum/videolar');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.admin-videos-inventory-page');
        self::assertSelectorNotExists('.admin-stage');
        $inventory = static::getContainer()->get(LearningContentRepository::class);
        self::assertInstanceOf(LearningContentRepository::class, $inventory);
        if (0 === $inventory->countPlatformLinkedVideosForAdmin()) {
            self::assertSelectorTextContains('body', 'Gösterilecek platform videosu yok');
            self::assertSelectorTextContains('body', '0 kayıt');
            self::assertSelectorNotExists('.admin-videos-inventory-page__table');
        }

        foreach ([
            ['vids-deny-admin@example.com', UserRole::Admin, 403],
            ['vids-deny-teacher@example.com', UserRole::Teacher, 403],
        ] as [$email, $role, $status]) {
            $user = $this->activeUser($email, $role);
            $denied = $this->browser();
            $denied->loginUser($user);
            $denied->request('GET', '/yonetim/bolum/videolar');
            self::assertResponseStatusCodeSame($status);
        }

        $anon = $this->browser();
        $anon->request('GET', '/yonetim/bolum/videolar');
        self::assertResponseRedirects('/giris');
    }

    public function testNavMarksVideosReadyAndCurrentOnce(): void
    {
        $sa = $this->activeUser('vids-nav-sa@example.com', UserRole::SuperAdmin);
        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('a.admin-media-link[href="/yonetim/bolum/videolar"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Çalışıyor', $card->text());
        self::assertStringNotContainsString('Geliştirme aşamasında', $card->text());

        $crawler = $client->request('GET', '/yonetim/bolum/videolar');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#panel-sidebar a[href="/yonetim/bolum/videolar"][aria-current="page"]'));
        self::assertNotNull($crawler->filter('#nav-group-media')->attr('open'));
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

        $subject = $subjects->create($sa, $prefix.'_s', 'Subj '.$prefix, 'vids_inv_cs');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'vids_inv_cp');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'vids_inv_cu');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'vids_inv_ct');
        $outcome = $outcomes->create($topic, $sa, $prefix.'_lo', 'O', 1, 'vids_inv_clo');
        $programs->publish($program, $sa, 'vids_inv_pp');

        return [$subject, $outcome];
    }

    private function contentCount(): int
    {
        $repo = static::getContainer()->get(LearningContentRepository::class);
        self::assertInstanceOf(LearningContentRepository::class, $repo);

        return $repo->count([]);
    }

    private function activeUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return $this->persistUser($email, $role);
    }

    private function persistUser(string $email, UserRole $role): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Vid', 'Eo', $initial);
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

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            if ($connection->createSchemaManager()->tablesExist(['institution_memberships'])) {
                $connection->executeStatement(
                    "DELETE FROM institution_memberships WHERE institution_id IN (SELECT id FROM institutions WHERE name = 'Video Kurum Gizli')",
                );
            }
            if ($connection->createSchemaManager()->tablesExist(['institutions'])) {
                $connection->executeStatement("DELETE FROM institutions WHERE name = 'Video Kurum Gizli'");
            }
            if ($connection->createSchemaManager()->tablesExist(['users'])) {
                $connection->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'vids-%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
