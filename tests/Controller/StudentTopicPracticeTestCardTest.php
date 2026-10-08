<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\Assessment;
use App\Entity\AssessmentAttempt;
use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicAssessment;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessPackageTargetType;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResourceAccessClass;
use App\Enum\ResultReleasePolicy;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\StudentPracticeException;
use App\LearningContent\Content\LearningContentDocument;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentAttemptRepository;
use App\Repository\AssessmentRepository;
use App\Repository\CatalogTopicAssessmentRepository;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\AccessLicenseManager;
use App\Service\AccessPackageManager;
use App\Service\AccessPackageVersionManager;
use App\Service\AssessmentManager;
use App\Service\CatalogTopicAssessmentManager;
use App\Service\CatalogTopicLessonManager;
use App\Service\CatalogWriteService;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\QuestionManager;
use App\Service\StudentAssessmentPractice;
use App\Service\StudentProfileManager;
use App\Service\StudentTopicContentQuery;
use App\Service\SubjectManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\AssessmentDbCleanup;
use App\Tests\Support\LearningContentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class StudentTopicPracticeTestCardTest extends WebTestCase
{
    public function testPublishedFreePlacementShowsCardAndWorkingDetailLink(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_vis', ResourceAccessClass::Free);
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Kendini dene kartı', 0, 'pub_vis');

        $this->onboardStudent('ptc-vis@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'ptc-vis@example.com');
        $path = $this->topicPath($bundle);
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2:contains("Kendini dene")', 'Kendini dene');
        self::assertSelectorTextContains('.student-topic-practice-tests', 'Kendini dene kartı');
        $detailHref = $crawler->filter('.student-topic-practice-tests a')->attr('href');
        self::assertNotNull($detailHref);
        self::assertStringContainsString('/ogrenci/testler/'.$bundle['code'], $detailHref);

        $beforeAttempts = $this->countRows('assessment_attempts');
        $client->request('GET', $detailHref);
        self::assertResponseIsSuccessful();
        self::assertSame($beforeAttempts, $this->countRows('assessment_attempts'));
    }

    public function testPracticeDiscoveryItemLoadQueriesDoNotScaleWithPlacementCount(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_qb_a', ResourceAccessClass::Free, 'QB-A');
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Birinci', 0, 'pub_qb_a');
        $second = $this->appendAssessmentToTopic($bundle, 'ptc_qb_b', ResourceAccessClass::Free, 'QB-B');
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $second['assessmentId'], 'İkinci', 1, 'pub_qb_b');

        $this->onboardStudent('ptc-qb@example.com', GradeLevel::Grade1);
        self::bootKernel();
        $student = $this->freshUser('ptc-qb@example.com');
        $this->resetDoctrineQueryLog();

        /** @var StudentTopicContentQuery $query */
        $query = static::getContainer()->get(StudentTopicContentQuery::class);
        $detailTwo = $query->getPublishedTopicDetail(
            GradeLevel::Grade1,
            $bundle['subjectSlug'],
            $bundle['unitSlug'],
            $bundle['topicSlug'],
            $student,
        );
        self::assertNotNull($detailTwo);
        self::assertCount(2, $detailTwo->practiceTests);
        $countsTwo = $this->doctrineQueryCounts();
        self::assertGreaterThan(0, $countsTwo['statements'], 'SQL counter must observe statements; a 0/0 reading is not evidence.');
        self::assertSame(1, $countsTwo['metadataSelects'], 'Two placements must share one assessment_items/question_revisions metadata SELECT.');

        $third = $this->appendAssessmentToTopic($bundle, 'ptc_qb_c', ResourceAccessClass::Free, 'QB-C');
        $fourth = $this->appendAssessmentToTopic($bundle, 'ptc_qb_d', ResourceAccessClass::Free, 'QB-D');
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $third['assessmentId'], 'Üçüncü', 2, 'pub_qb_c');
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $fourth['assessmentId'], 'Dördüncü', 3, 'pub_qb_d');

        self::ensureKernelShutdown();
        self::bootKernel();
        $student = $this->freshUser('ptc-qb@example.com');
        $this->resetDoctrineQueryLog();
        /** @var StudentTopicContentQuery $queryFour */
        $queryFour = static::getContainer()->get(StudentTopicContentQuery::class);
        $detailFour = $queryFour->getPublishedTopicDetail(
            GradeLevel::Grade1,
            $bundle['subjectSlug'],
            $bundle['unitSlug'],
            $bundle['topicSlug'],
            $student,
        );
        self::assertNotNull($detailFour);
        self::assertCount(4, $detailFour->practiceTests);
        $countsFour = $this->doctrineQueryCounts();
        self::assertGreaterThan(0, $countsFour['statements'], 'SQL counter must observe statements; a 0/0 reading is not evidence.');
        self::assertSame(1, $countsFour['metadataSelects'], 'Four placements must share one assessment_items/question_revisions metadata SELECT.');
    }

    public function testMultiplePlacementsOrderedByPositionThenStableId(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_ord_a', ResourceAccessClass::Free, 'ORD-A');
        $second = $this->appendAssessmentToTopic($bundle, 'ptc_ord_b', ResourceAccessClass::Free, 'ORD-B');
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $second['assessmentId'], 'İkinci kart', 2, 'pub_b');
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Birinci kart', 0, 'pub_a');

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $topic = $em->find(CatalogTopic::class, $bundle['topicId']);
        self::assertInstanceOf(CatalogTopic::class, $topic);
        /** @var CatalogTopicAssessmentRepository $repo */
        $repo = static::getContainer()->get(CatalogTopicAssessmentRepository::class);
        $ordered = $repo->findPublishedOrderedByTopic($topic);
        self::assertCount(2, $ordered);
        self::assertSame(0, $ordered[0]->getPosition());
        self::assertSame(2, $ordered[1]->getPosition());
        self::ensureKernelShutdown();

        $this->onboardStudent('ptc-ord@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'ptc-ord@example.com');
        $crawler = $client->request('GET', $this->topicPath($bundle));
        self::assertResponseIsSuccessful();
        $titles = $crawler->filter('.student-topic-practice-tests .student-catalog-card__title')->each(
            static fn ($node): string => trim($node->text()),
        );
        self::assertSame(['Birinci kart', 'İkinci kart'], $titles);
    }

    public function testDraftArchivedAndUnpublishedCatalogHideCards(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_hide', ResourceAccessClass::Free);
        $this->createDraftPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Taslak yerleşim', 0);
        $archivedAssessment = $this->appendAssessmentToTopic($bundle, 'ptc_arch_a', ResourceAccessClass::Free, 'ARCH');
        $archivedPlacementId = $this->publishPlacement($bundle['admin'], $bundle['topicId'], $archivedAssessment['assessmentId'], 'Arşivlenecek', 1, 'pub_arch')->getId();
        $this->archiveAssessment($archivedAssessment['assessmentId']);

        $this->onboardStudent('ptc-hide@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'ptc-hide@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('h2:contains("Kendini dene")');
        self::assertStringNotContainsString('Taslak yerleşim', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Arşivlenecek', (string) $client->getResponse()->getContent());

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogTopicAssessmentManager $manager */
        $manager = static::getContainer()->get(CatalogTopicAssessmentManager::class);
        $admin = $this->freshUser($bundle['adminEmail']);
        $manager->archive($admin, $archivedPlacementId, 'arch_pl');
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'ptc-hide@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertStringNotContainsString('Taslak yerleşim', (string) $client->getResponse()->getContent());

        $this->unpublishCatalogSubject($bundle['catalogSubjectId']);
        $client = static::createClient();
        $this->login($client, 'ptc-hide@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongTopicGradeSubjectAndInstitutionScopeInvisible(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_scope', ResourceAccessClass::Free);
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Scope kart', 0, 'pub_scope');

        $otherTopicPath = $this->seedSiblingTopicPath($bundle, 'ptc_other_topic');
        $this->onboardStudent('ptc-scope@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'ptc-scope@example.com');
        $client->request('GET', $otherTopicPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('h2:contains("Kendini dene")');

        $this->onboardStudent('ptc-g5@example.com', GradeLevel::Grade5);
        $client = static::createClient();
        $this->login($client, 'ptc-g5@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertResponseStatusCodeSame(404);

        $mismatchBundle = $this->seedTopicWithMismatchedSubjectAssessment('ptc_mismatch', ResourceAccessClass::Free);
        $this->onboardStudent('ptc-mismatch@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'ptc-mismatch@example.com');
        $client->request('GET', $this->topicPath($mismatchBundle));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('h2:contains("Kendini dene")');
    }

    public function testExistingPracticeListRouteStillListsFreeAssessment(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_regress', ResourceAccessClass::Free, 'REG');
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Regresyon', 0, 'pub_reg');
        $this->onboardStudent('ptc-reg@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'ptc-reg@example.com');
        $client->request('GET', '/ogrenci/testler');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Gate testi REG', (string) $client->getResponse()->getContent());
    }

    public function testMissingPolicyDeniesCardCoveringLicenseAllows(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_ent', null);
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Lisanslı kart', 0, 'pub_ent');
        $this->onboardStudent('ptc-ent@example.com', GradeLevel::Grade1);

        $client = static::createClient();
        $this->login($client, 'ptc-ent@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertSelectorNotExists('.student-topic-practice-tests');

        $this->setAssessmentAccessPolicy($bundle['assessmentId'], ResourceAccessClass::EntitlementRequired, 'ptc_ent_policy');
        $client = static::createClient();
        $this->login($client, 'ptc-ent@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertSelectorNotExists('.student-topic-practice-tests');

        $this->grantAssessmentLicense($bundle['assessmentId'], 'ptc-ent@example.com', '+30 days', 'ptc_ent_ok');
        $client = static::createClient();
        $this->login($client, 'ptc-ent@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertSelectorTextContains('.student-topic-practice-tests', 'Lisanslı kart');
    }

    public function testLearningContentFreeDoesNotOpenAssessmentOrShowCardWithoutPolicy(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_lc', ResourceAccessClass::EntitlementRequired);
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Kapalı test', 0, 'pub_lc');
        $this->seedFreeLearningContentOnTopic($bundle);
        $this->onboardStudent('ptc-lc@example.com', GradeLevel::Grade1);

        $before = $this->countRows('assessment_attempts');
        $client = static::createClient();
        $this->login($client, 'ptc-lc@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('h2:contains("Kendini dene")');
        self::assertSame($before, $this->countRows('assessment_attempts'));

        self::ensureKernelShutdown();
        self::bootKernel();
        $practice = static::getContainer()->get(StudentAssessmentPractice::class);
        self::assertInstanceOf(StudentAssessmentPractice::class, $practice);
        try {
            $practice->start($this->freshUser('ptc-lc@example.com'), GradeLevel::Grade1, $bundle['code']);
            self::fail('LC free must not open assessment');
        } catch (StudentPracticeException $exception) {
            self::assertSame('not_found', $exception->getReason());
        }
        self::assertSame($before, $this->countRows('assessment_attempts'));
    }

    public function testUnsuitablePracticeItemsSuppressCard(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_bad', ResourceAccessClass::Free, 'BAD', QuestionType::TrueFalse);
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Uygun değil', 0, 'pub_bad');
        $this->onboardStudent('ptc-bad@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'ptc-bad@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('h2:contains("Kendini dene")');
    }

    public function testTopicHtmlOmitsSecretsAndForeignAttempt(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_leak', ResourceAccessClass::Free);
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Güvenli kart', 0, 'pub_leak');
        $this->onboardStudent('ptc-leak-a@example.com', GradeLevel::Grade1);
        $this->onboardStudent('ptc-leak-b@example.com', GradeLevel::Grade1);

        self::ensureKernelShutdown();
        self::bootKernel();
        $practice = static::getContainer()->get(StudentAssessmentPractice::class);
        self::assertInstanceOf(StudentAssessmentPractice::class, $practice);
        $practice->start($this->freshUser('ptc-leak-a@example.com'), GradeLevel::Grade1, $bundle['code']);
        /** @var AssessmentAttemptRepository $attempts */
        $attempts = static::getContainer()->get(AssessmentAttemptRepository::class);
        $attempt = $attempts->findOneBy(['user' => $this->freshUser('ptc-leak-a@example.com')]);
        self::assertInstanceOf(AssessmentAttempt::class, $attempt);
        $attemptId = $attempt->getId()->toRfc4122();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'ptc-leak-b@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString('answer-key', $html);
        self::assertStringNotContainsString('storageKey', $html);
        self::assertStringNotContainsString($bundle['assessmentId']->toRfc4122(), $html);
        self::assertStringNotContainsString($bundle['revisionId']->toRfc4122(), $html);
        self::assertStringNotContainsString($attemptId, $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);
    }

    public function testCatalogWithoutCanonicalMappingShowsNoCard(): void
    {
        $bundle = $this->seedTopicWithPublishedAssessment('ptc_nocanon', ResourceAccessClass::Free);
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Canonicalsız', 0, 'pub_nc');
        $this->stripCanonicalMapping($bundle['catalogSubjectId']);
        $this->onboardStudent('ptc-nocanon@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'ptc-nocanon@example.com');
        $client->request('GET', $this->topicPath($bundle));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('h2:contains("Kendini dene")');
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function topicPath(array $bundle): string
    {
        return \sprintf(
            '/ogrenci/dersler/%s/%s/%s',
            $bundle['subjectSlug'],
            $bundle['unitSlug'],
            $bundle['topicSlug'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function seedTopicWithPublishedAssessment(
        string $prefix,
        ?ResourceAccessClass $policy,
        ?string $titleSuffix = null,
        QuestionType $questionType = QuestionType::SingleChoice,
    ): array {
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
        $catalog->assignCanonicalSubject($sa, $catalogSubject->getId(), $subject->getId());
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
            $questionType,
            QuestionContentDocument::paragraph('Soru '.$prefix.'?'),
            null,
            QuestionType::SingleChoice === $questionType ? [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ] : [],
            QuestionType::SingleChoice === $questionType ? ['correctStableKey' => 'opt_b'] : ['correct' => true],
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

        $assessmentTitle = 'Gate testi '.($titleSuffix ?? $prefix);
        $assessment = $assessments->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade1,
            $assessmentTitle,
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
        if ($policy instanceof ResourceAccessClass) {
            /** @var AccessPackageManager $packages */
            $packages = $container->get(AccessPackageManager::class);
            $packages->setAssessmentAccessPolicy($assessment, $publisher, $policy, 'seed_policy');
        }

        $bundle = [
            'subjectSlug' => $catalogSubject->getSlug(),
            'unitSlug' => $catalogUnit->getSlug(),
            'topicSlug' => $catalogTopic->getSlug(),
            'topicId' => $catalogTopic->getId(),
            'catalogSubjectId' => $catalogSubject->getId(),
            'assessmentId' => $assessment->getId(),
            'revisionId' => $assessment->getPublishedRevision()?->getId(),
            'code' => $assessment->getCode(),
            'admin' => $publisher,
            'adminEmail' => $publisher->getEmail(),
            'canonicalSubjectId' => $subject->getId(),
        ];
        self::ensureKernelShutdown();

        return $bundle;
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private function appendAssessmentToTopic(
        array $bundle,
        string $prefix,
        ?ResourceAccessClass $policy,
        ?string $titleSuffix = null,
        QuestionType $questionType = QuestionType::SingleChoice,
    ): array {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        $sa = $this->freshUser(str_replace('-pub@', '-sa@', $bundle['adminEmail']));
        $publisher = $this->freshUser($bundle['adminEmail']);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $subject = $em->find(Subject::class, $bundle['canonicalSubjectId']);
        self::assertInstanceOf(Subject::class, $subject);
        /** @var CurriculumProgramRepository $programs */
        $programs = $container->get(CurriculumProgramRepository::class);
        /** @var CurriculumLearningOutcomeRepository $outcomes */
        $outcomes = $container->get(CurriculumLearningOutcomeRepository::class);
        /** @var QuestionManager $questions */
        $questions = $container->get(QuestionManager::class);
        /** @var AssessmentManager $assessments */
        $assessments = $container->get(AssessmentManager::class);
        $published = $programs->findPublishedForSubjectAndGrade($subject, GradeLevel::Grade1);
        self::assertNotEmpty($published);
        $los = $outcomes->findByProgram($published[0]);
        self::assertNotEmpty($los);
        $outcome = $los[0];

        $question = $questions->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            $questionType,
            QuestionContentDocument::paragraph('Soru '.$prefix.'?'),
            null,
            QuestionType::SingleChoice === $questionType ? [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ] : [],
            QuestionType::SingleChoice === $questionType ? ['correctStableKey' => 'opt_b'] : ['correct' => true],
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

        $assessmentTitle = 'Gate testi '.($titleSuffix ?? $prefix);
        $assessment = $assessments->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade1,
            $assessmentTitle,
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
        if ($policy instanceof ResourceAccessClass) {
            /** @var AccessPackageManager $packages */
            $packages = $container->get(AccessPackageManager::class);
            $packages->setAssessmentAccessPolicy($assessment, $publisher, $policy, 'seed_policy');
        }

        $extra = [
            'subjectSlug' => $bundle['subjectSlug'],
            'unitSlug' => $bundle['unitSlug'],
            'topicSlug' => $bundle['topicSlug'],
            'topicId' => $bundle['topicId'],
            'catalogSubjectId' => $bundle['catalogSubjectId'],
            'assessmentId' => $assessment->getId(),
            'revisionId' => $assessment->getPublishedRevision()?->getId(),
            'code' => $assessment->getCode(),
            'admin' => $publisher,
            'adminEmail' => $bundle['adminEmail'],
            'canonicalSubjectId' => $bundle['canonicalSubjectId'],
        ];
        self::ensureKernelShutdown();

        return $extra;
    }

    /**
     * @return array<string, mixed>
     */
    private function seedTopicWithMismatchedSubjectAssessment(string $prefix, ?ResourceAccessClass $policy): array
    {
        $bundle = $this->seedTopicWithPublishedAssessment($prefix.'_base', $policy);
        $this->publishPlacement($bundle['admin'], $bundle['topicId'], $bundle['assessmentId'], 'Mismatch kart', 0, 'pub_mismatch');
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        $sa = $this->freshUser(str_replace('-pub@', '-sa@', $bundle['adminEmail']));
        $other = $subjects->create($sa, $prefix.'_other_s', 'Other', 'create_other');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE assessments SET subject_id = :subjectId WHERE id = :id',
            [
                'subjectId' => $other->getId()->toBinary(),
                'id' => $bundle['assessmentId']->toBinary(),
            ],
        );
        self::ensureKernelShutdown();

        return $bundle;
    }

    private function stripCanonicalMapping(Uuid $catalogSubjectId): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE catalog_subjects SET canonical_subject_id = NULL WHERE id = :id',
            ['id' => $catalogSubjectId->toBinary()],
        );
        self::ensureKernelShutdown();
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function seedSiblingTopicPath(array $bundle, string $prefix): string
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogWriteService $catalog */
        $catalog = static::getContainer()->get(CatalogWriteService::class);
        $unit = $catalog->createUnit($bundle['catalogSubjectId'], 'Tema '.$prefix, null, 1);
        $topic = $catalog->createTopic($unit->getId(), 'Konu '.$prefix, null, 1, 10);
        $catalog->publishUnit($unit->getId());
        $catalog->publishTopic($topic->getId());
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $subject = $em->find(\App\Entity\CatalogSubject::class, $bundle['catalogSubjectId']);
        self::assertNotNull($subject);
        $path = \sprintf('/ogrenci/dersler/%s/%s/%s', $subject->getSlug(), $unit->getSlug(), $topic->getSlug());
        self::ensureKernelShutdown();

        return $path;
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function seedFreeLearningContentOnTopic(array $bundle): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $canonical = $em->find(Subject::class, $bundle['canonicalSubjectId']);
        self::assertInstanceOf(Subject::class, $canonical);
        /** @var CatalogTopicLessonManager $lessons */
        $lessons = static::getContainer()->get(CatalogTopicLessonManager::class);
        /** @var LearningContentManager $contents */
        $contents = static::getContainer()->get(LearningContentManager::class);
        /** @var AccessPackageManager $packages */
        $packages = static::getContainer()->get(AccessPackageManager::class);
        $admin = $this->freshUser($bundle['adminEmail']);
        $sa = $this->freshUser(str_replace('-pub@', '-sa@', $bundle['adminEmail']));
        /** @var CurriculumProgramRepository $programs */
        $programs = static::getContainer()->get(CurriculumProgramRepository::class);
        /** @var CurriculumLearningOutcomeRepository $outcomes */
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeRepository::class);
        $published = $programs->findPublishedForSubjectAndGrade($canonical, GradeLevel::Grade1);
        self::assertNotEmpty($published);
        $los = $outcomes->findByProgram($published[0]);
        self::assertNotEmpty($los);
        $content = $contents->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $canonical,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'ptc_lc_free',
            'LC free body',
            null,
            LearningContentDocument::paragraph('Free paragraph'),
            [['learningOutcome' => $los[0], 'isPrimary' => true]],
            'create_lc',
        );
        $contents->submitForReview($content, $sa, 'submit');
        $contents->publish($content, $admin, 'publish_lc');
        $packages->setLearningContentAccessPolicy($content, $admin, ResourceAccessClass::Free, 'lc_free');
        $placement = $lessons->create($admin, $bundle['topicId'], $content->getId(), 'Ücretsiz adım', null, 5, 'create_lc_pl', 'ucretsiz-adim');
        $lessons->publish($admin, $placement->getId(), 'pub_lc_pl');
        self::ensureKernelShutdown();
    }

    private function publishPlacement(
        User $admin,
        Uuid $topicId,
        Uuid $assessmentId,
        string $title,
        int $position,
        string $reason,
    ): CatalogTopicAssessment {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogTopicAssessmentManager $manager */
        $manager = static::getContainer()->get(CatalogTopicAssessmentManager::class);
        $actor = $this->freshUser($admin->getEmail());
        $draft = $manager->create($actor, $topicId, $assessmentId, $title, null, $position, $reason, 'slug-'.$reason);
        $published = $manager->publish($actor, $draft->getId(), $reason.'_pub');
        self::ensureKernelShutdown();

        return $published;
    }

    private function createDraftPlacement(
        User $admin,
        Uuid $topicId,
        Uuid $assessmentId,
        string $title,
        int $position,
    ): Uuid {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogTopicAssessmentManager $manager */
        $manager = static::getContainer()->get(CatalogTopicAssessmentManager::class);
        $draft = $manager->create(
            $this->freshUser($admin->getEmail()),
            $topicId,
            $assessmentId,
            $title,
            null,
            $position,
            'draft_only',
            'draft-slug',
        );
        $id = $draft->getId();
        self::ensureKernelShutdown();

        return $id;
    }

    private function archiveAssessment(Uuid $assessmentId): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var AssessmentRepository $assessments */
        $assessments = static::getContainer()->get(AssessmentRepository::class);
        /** @var AssessmentManager $manager */
        $manager = static::getContainer()->get(AssessmentManager::class);
        $assessment = $assessments->findOneById($assessmentId);
        self::assertInstanceOf(Assessment::class, $assessment);
        $publisher = $this->freshUser($assessment->getCreatedBy()->getEmail());
        $manager->archive($assessment, $publisher, 'archive_test');
        self::ensureKernelShutdown();
    }

    private function unpublishCatalogSubject(Uuid $catalogSubjectId): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogWriteService $catalog */
        $catalog = static::getContainer()->get(CatalogWriteService::class);
        $catalog->archiveSubject($catalogSubjectId);
        self::ensureKernelShutdown();
    }

    private function setAssessmentAccessPolicy(Uuid $assessmentId, ResourceAccessClass $policy, string $reason): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var AssessmentRepository $assessments */
        $assessments = static::getContainer()->get(AssessmentRepository::class);
        $assessment = $assessments->findOneById($assessmentId);
        self::assertInstanceOf(Assessment::class, $assessment);
        $publisherEmail = str_replace('-sa@', '-pub@', $assessment->getCreatedBy()->getEmail());
        $publisher = $this->freshUser($publisherEmail);
        /** @var AccessPackageManager $packages */
        $packages = static::getContainer()->get(AccessPackageManager::class);
        $packages->setAssessmentAccessPolicy($assessment, $publisher, $policy, $reason);
        self::ensureKernelShutdown();
    }

    private function grantAssessmentLicense(
        Uuid $assessmentId,
        string $studentEmail,
        string|\DateTimeImmutable $endsAt,
        string $suffix,
    ): void {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var AssessmentRepository $assessments */
        $assessments = $container->get(AssessmentRepository::class);
        $assessment = $assessments->findOneById($assessmentId);
        self::assertInstanceOf(Assessment::class, $assessment);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $student = $users->findOneByNormalizedEmail(mb_strtolower($studentEmail));
        $sa = null;
        foreach ($users->findAll() as $candidate) {
            if (\in_array('ROLE_SUPER_ADMIN', $candidate->getRoles(), true)) {
                $sa = $candidate;
                break;
            }
        }
        self::assertInstanceOf(User::class, $student);
        self::assertInstanceOf(User::class, $sa);
        /** @var AccessPackageManager $packages */
        $packages = $container->get(AccessPackageManager::class);
        /** @var AccessPackageVersionManager $versions */
        $versions = $container->get(AccessPackageVersionManager::class);
        /** @var AccessLicenseManager $licenses */
        $licenses = $container->get(AccessLicenseManager::class);
        $package = $packages->create($sa, $suffix.'_pkg', 'Pkg '.$suffix, null, AccessPackageTargetType::Individual, 30, null, 'create_pkg');
        $version = $versions->createDraftVersion($package, $sa, 30, null, 'create_v');
        $versions->addAssessmentGrant($version, $assessment, $sa, 'add_grant');
        $version = $versions->activate($version, $sa, 'activate_v');
        $validUntil = $endsAt instanceof \DateTimeImmutable ? $endsAt : new \DateTimeImmutable($endsAt);
        $license = $licenses->createUserLicense(
            $version,
            $student,
            $sa,
            AccessLicenseSourceType::Manual,
            new \DateTimeImmutable('-1 day'),
            $validUntil,
            'grant_'.$suffix,
        );
        $licenses->activate($license, $sa, 'activate_lic_'.$suffix);
        self::ensureKernelShutdown();
    }

    private function onboardStudent(string $email, GradeLevel $grade): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        /** @var StudentProfileManager $profiles */
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ayşe', 'Yılmaz', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $grade;
        $profiles->completeOnboarding($user, $dto);
        self::ensureKernelShutdown();
    }

    private function freshUser(string $email): User
    {
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail(mb_strtolower($email));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function resetDoctrineQueryLog(): void
    {
        $this->doctrineDebugDataHolder()->reset();
    }

    /**
     * @return array{statements: int, metadataSelects: int}
     */
    private function doctrineQueryCounts(): array
    {
        $statements = 0;
        $metadataSelects = 0;
        foreach ($this->doctrineDebugDataHolder()->getData() as $queries) {
            foreach ($queries as $query) {
                $sql = $query['sql'] ?? null;
                if (!\is_string($sql) || '' === trim($sql)) {
                    continue;
                }
                ++$statements;
                $collapsed = preg_replace('/\s+/', ' ', $sql);
                $normalized = strtolower(\is_string($collapsed) ? $collapsed : $sql);
                if (
                    str_starts_with(ltrim($normalized), 'select')
                    && str_contains($normalized, 'from assessment_items')
                    && str_contains($normalized, 'join question_revisions')
                    && str_contains($normalized, 'penalty_points')
                    && str_contains($normalized, 'qr.type')
                ) {
                    ++$metadataSelects;
                }
            }
        }

        return [
            'statements' => $statements,
            'metadataSelects' => $metadataSelects,
        ];
    }

    private function doctrineDebugDataHolder(): DebugDataHolder
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);

        return $holder;
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

    private function countRows(string $table): int
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::ensureKernelShutdown();

        return $count;
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
            if ($sm->tablesExist(['catalog_topic_lessons'])) {
                $conn->executeStatement('DELETE FROM catalog_topic_lessons');
            }
            AccessEntitlementDbCleanup::deleteAll($conn);
            AssessmentDbCleanup::deleteAssessments($conn);
            LearningContentDbCleanup::deleteLearningContents($conn);
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
                'student_profiles',
                'users',
            ]);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
