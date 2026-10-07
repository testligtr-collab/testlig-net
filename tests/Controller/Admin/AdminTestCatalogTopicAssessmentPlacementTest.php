<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\QuestionRevision;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResourceAccessClass;
use App\Enum\ResultReleasePolicy;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\AssessmentManager;
use App\Service\CatalogTopicAssessmentManager;
use App\Service\CatalogWriteService;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\AssessmentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminTestCatalogTopicAssessmentPlacementTest extends WebTestCase
{
    public function testCreatePublishArchiveCsrfAndTeacherPublishDenied(): void
    {
        $seed = $this->seedPublishedAssessmentWithCatalog('atp');
        $this->createPrivileged('atp-admin@example.com', UserRole::Admin);
        $this->createPrivileged('atp-teacher@example.com', UserRole::Teacher);

        $client = $this->newClient();
        $this->login($client, 'atp-admin@example.com');
        $path = '/yonetim/testler/'.$seed['assessmentId'];

        $client->request('POST', $path.'/yerlesim', [
            '_token' => 'bad',
            'catalog_topic_id' => $seed['topicId']->toRfc4122(),
            'display_title' => 'Katalog testi',
            'position' => '0',
            'note' => 'admin_test_placement_create',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#placement-create-form input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', $path.'/yerlesim', [
            '_token' => $token,
            'catalog_topic_id' => $seed['topicId']->toRfc4122(),
            'display_title' => 'Katalog testi',
            'slug' => 'katalog-testi',
            'position' => '0',
            'note' => 'admin_test_placement_create',
        ]);
        self::assertResponseRedirects($path);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Katalog testi');

        $crawler = $client->request('GET', $path);
        $publishForm = $crawler->filter('form[action*="/yerlesim/"][action$="/yayimla"]')->first();
        self::assertGreaterThan(0, $publishForm->count());
        $publishAction = $publishForm->attr('action');
        self::assertNotNull($publishAction);
        $publishToken = $publishForm->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($publishToken);

        $client->request('POST', $publishAction, [
            '_token' => $publishToken,
            'note' => 'admin_test_placement_publish',
        ]);
        self::assertResponseRedirects($path);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'onay kutusu');

        $beforePublishAudit = $this->placementAuditCount(SecurityAuditAction::CatalogTopicAssessmentPublished);
        $client->request('POST', $publishAction, [
            '_token' => $publishToken,
            'confirm_publish' => '1',
            'note' => 'admin_test_placement_publish',
        ]);
        self::assertResponseRedirects($path);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Yayında');
        self::assertSame($beforePublishAudit + 1, $this->placementAuditCount(SecurityAuditAction::CatalogTopicAssessmentPublished));

        $client->request('POST', $publishAction, [
            '_token' => $publishToken,
            'confirm_publish' => '1',
            'note' => 'admin_test_placement_publish',
        ]);
        self::assertResponseRedirects($path);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'zaten yayımlanmış');
        self::assertSame($beforePublishAudit + 1, $this->placementAuditCount(SecurityAuditAction::CatalogTopicAssessmentPublished));

        $teacherClient = $this->newClient();
        $this->login($teacherClient, 'atp-teacher@example.com');
        $teacherClient->request('POST', $publishAction, [
            '_token' => $publishToken,
            'confirm_publish' => '1',
            'note' => 'teacher_try_publish',
        ]);
        self::assertResponseStatusCodeSame(403);

        $client = $this->newClient();
        $this->login($client, 'atp-admin@example.com');
        $crawler = $client->request('GET', $path);
        $archiveForm = $crawler->filter('form[action$="/arsivle"]')->first();
        $client->request('POST', (string) $archiveForm->attr('action'), [
            '_token' => (string) $archiveForm->filter('input[name="_token"]')->attr('value'),
            'note' => 'admin_test_placement_archive',
        ]);
        self::assertResponseRedirects($path);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Arşivlenmiş');
    }

    public function testDuplicateCreateIsIdempotentWithoutExtraAudit(): void
    {
        $seed = $this->seedPublishedAssessmentWithCatalog('atp_idem');
        $this->createPrivileged('atp-idem-admin@example.com', UserRole::Admin);
        $client = $this->newClient();
        $this->login($client, 'atp-idem-admin@example.com');
        $path = '/yonetim/testler/'.$seed['assessmentId'];
        $crawler = $client->request('GET', $path);
        $token = $crawler->filter('#placement-create-form input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $payload = [
            '_token' => $token,
            'catalog_topic_id' => $seed['topicId']->toRfc4122(),
            'display_title' => 'Tek yerleşim',
            'slug' => 'tek-yerlesim',
            'position' => '0',
            'note' => 'admin_test_placement_create',
        ];
        $beforeCreated = $this->placementAuditCount(SecurityAuditAction::CatalogTopicAssessmentCreated);
        $client->request('POST', $path.'/yerlesim', $payload);
        self::assertResponseRedirects($path);
        $client->followRedirect();
        self::assertSame($beforeCreated + 1, $this->placementAuditCount(SecurityAuditAction::CatalogTopicAssessmentCreated));
        self::assertSame(1, $this->placementCountForTopic($seed['topicId']));

        $crawler = $client->request('GET', $path);
        $token = $crawler->filter('#placement-create-form input[name="_token"]')->attr('value');
        $payload['_token'] = $token;
        $client->request('POST', $path.'/yerlesim', $payload);
        self::assertResponseRedirects($path);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'zaten mevcut');
        self::assertSame($beforeCreated + 1, $this->placementAuditCount(SecurityAuditAction::CatalogTopicAssessmentCreated));
        self::assertSame(1, $this->placementCountForTopic($seed['topicId']));
    }

    public function testBindRejectsMissingCanonicalSubject(): void
    {
        $seed = $this->seedPublishedAssessmentWithCatalog('atp_canon', assignCanonical: false);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogTopicAssessmentManager $manager */
        $manager = static::getContainer()->get(CatalogTopicAssessmentManager::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $admin = $users->findOneByNormalizedEmail(mb_strtolower('atp_canon-pub@example.com'));
        self::assertInstanceOf(User::class, $admin);
        $rejected = false;
        try {
            $manager->create(
                $admin,
                $seed['topicId'],
                Uuid::fromString($seed['assessmentId']),
                'Canonical yok',
                null,
                0,
                'bind_no_canonical',
                'canonical-yok',
            );
        } catch (\App\Exception\CatalogException) {
            $rejected = true;
        }
        self::assertTrue($rejected);
        self::ensureKernelShutdown();
    }

    /**
     * @return array{assessmentId: string, topicId: Uuid}
     */
    private function seedPublishedAssessmentWithCatalog(string $prefix, bool $assignCanonical = true): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var UserFactory $factory */
        $factory = $container->get(UserFactory::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $sa = $factory->createAndPersist($prefix.'-sa@example.com', 'Guclu-Parola-123!', 'S', 'A', UserRole::Student);
        $sa->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $sa->transitionTo(UserStatus::Active);
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $users->save($sa);
        $publisher = $factory->createAndPersist($prefix.'-pub@example.com', 'Guclu-Parola-123!', 'P', 'U', UserRole::Teacher);
        $publisher->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $publisher->transitionTo(UserStatus::Active);
        $publisher->addGlobalRole(UserRole::Admin);
        $users->save($publisher);

        /** @var SubjectManager $subjects */
        $subjects = $container->get(SubjectManager::class);
        /** @var CatalogWriteService $catalog */
        $catalog = $container->get(CatalogWriteService::class);
        /** @var CurriculumProgramManager $programs */
        $programs = $container->get(CurriculumProgramManager::class);
        /** @var CurriculumUnitManager $units */
        $units = $container->get(CurriculumUnitManager::class);
        /** @var CurriculumTopicManager $topics */
        $topics = $container->get(CurriculumTopicManager::class);
        /** @var CurriculumLearningOutcomeManager $outcomes */
        $outcomes = $container->get(CurriculumLearningOutcomeManager::class);
        /** @var QuestionManager $questions */
        $questions = $container->get(QuestionManager::class);
        /** @var AssessmentManager $assessments */
        $assessments = $container->get(AssessmentManager::class);

        $subject = $subjects->create($sa, $prefix.'_s', 'Ders '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'create_p');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'create_t');
        $outcome = $outcomes->create($topic, $sa, $prefix.'_lo', 'Kazanim', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');

        $catalogSubject = $catalog->createSubject(GradeLevel::Grade1, 'Matematik '.$prefix, null, 1);
        if ($assignCanonical) {
            $catalog->assignCanonicalSubject($sa, $catalogSubject->getId(), $subject->getId());
        }
        $catalogUnit = $catalog->createUnit($catalogSubject->getId(), 'Tema '.$prefix, null, 0);
        $catalogTopic = $catalog->createTopic($catalogUnit->getId(), 'Konu '.$prefix, 'Özet', 0, 15);
        $catalog->publishSubject($catalogSubject->getId());
        $catalog->publishUnit($catalogUnit->getId());
        $catalog->publishTopic($catalogTopic->getId());

        $question = $questions->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Soru?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.$prefix,
        );
        $questions->submitForReview($question, $sa, 'ready_for_review');
        $questions->publish($question, $publisher, 'publish_approved');
        /** @var QuestionRevisionRepository $revisions */
        $revisions = $container->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        self::assertInstanceOf(QuestionRevision::class, $revision);

        $assessment = $assessments->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade1,
            'Admin placement testi '.$prefix,
            null,
            null,
            600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Manual,
            null,
            [[
                'title' => 'Bölüm',
                'position' => 1,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $question->getId(),
                    'questionRevisionId' => $revision->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'create_a_'.$prefix,
            $subject,
        );
        $assessments->submitForReview($assessment, $sa, 'ready_for_review');
        $assessments->publish($assessment, $publisher, 'publish_approved');
        /** @var AccessPackageManager $packages */
        $packages = $container->get(AccessPackageManager::class);
        $packages->setAssessmentAccessPolicy($assessment, $publisher, ResourceAccessClass::Free, 'seed_policy');

        $result = [
            'assessmentId' => $assessment->getId()->toRfc4122(),
            'topicId' => $catalogTopic->getId(),
        ];
        self::ensureKernelShutdown();

        return $result;
    }

    private function placementAuditCount(SecurityAuditAction $action): int
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var SecurityAuditEventRepository $events */
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        $count = $events->countByAction($action->value);
        self::ensureKernelShutdown();

        return $count;
    }

    private function placementCountForTopic(Uuid $topicId): int
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM catalog_topic_assessments WHERE catalog_topic_id = :topic',
            ['topic' => $topicId->toBinary()],
        );
        self::ensureKernelShutdown();

        return $count;
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

    private function createPrivileged(string $email, UserRole $role): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Test', 'Kullanici', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            /** @var EntityManagerInterface $em */
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $conn = $em->getConnection();
            $sm = $conn->createSchemaManager();
            if ($sm->tablesExist(['catalog_topic_assessments'])) {
                $conn->executeStatement('DELETE FROM catalog_topic_assessments');
            }
            AccessEntitlementDbCleanup::deleteAll($conn);
            AssessmentDbCleanup::deleteAssessments($conn);
            foreach (['catalog_topics', 'catalog_units', 'catalog_subjects'] as $table) {
                if ($sm->tablesExist([$table])) {
                    $conn->executeStatement('DELETE FROM '.$table);
                }
            }
            QuestionBankDbCleanup::deleteTables($conn, [
                'curriculum_learning_outcomes',
                'curriculum_topics',
                'curriculum_units',
                'curriculum_programs',
                'subjects',
                'users',
            ]);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
