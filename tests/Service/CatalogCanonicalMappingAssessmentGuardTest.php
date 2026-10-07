<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuestionRevision;
use App\Entity\Subject;
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
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\CatalogException;
use App\Question\Content\QuestionContentDocument;
use App\Repository\QuestionRevisionRepository;
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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CatalogCanonicalMappingAssessmentGuardTest extends KernelTestCase
{
    public function testClearCanonicalRejectedWhenOnlyAssessmentPlacementExists(): void
    {
        $seed = $this->seedCatalogWithPublishedAssessmentPlacement('cca_clear');
        try {
            $this->catalog()->assignCanonicalSubject($seed['admin'], $seed['catalogSubjectId'], null);
            self::fail('Expected canonical clear rejection with assessment placement');
        } catch (CatalogException $e) {
            self::assertStringContainsString('konu yerleşimi', $e->getMessage());
        }
    }

    public function testChangeCanonicalRejectedWhenOnlyAssessmentPlacementExists(): void
    {
        $seed = $this->seedCatalogWithPublishedAssessmentPlacement('cca_change');
        $other = $this->subjects()->create($seed['sa'], 'cca_other_s', 'Other subject', 'create_other');
        try {
            $this->catalog()->assignCanonicalSubject($seed['admin'], $seed['catalogSubjectId'], $other->getId());
            self::fail('Expected canonical change rejection with assessment placement');
        } catch (CatalogException $e) {
            self::assertStringContainsString('konu yerleşimi', $e->getMessage());
        }
    }

    public function testAssignSameCanonicalSucceedsWithAssessmentPlacement(): void
    {
        $seed = $this->seedCatalogWithPublishedAssessmentPlacement('cca_noop');
        $subject = $this->em()->find(Subject::class, $seed['canonicalSubjectId']);
        self::assertInstanceOf(Subject::class, $subject);
        $updated = $this->catalog()->assignCanonicalSubject(
            $seed['admin'],
            $seed['catalogSubjectId'],
            $subject->getId(),
            'noop_map',
        );
        self::assertTrue($subject->getId()->equals($updated->getCanonicalSubject()?->getId()));
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

    /**
     * @return array{
     *     sa: User,
     *     admin: User,
     *     catalogSubjectId: Uuid,
     *     canonicalSubjectId: Uuid,
     * }
     */
    private function seedCatalogWithPublishedAssessmentPlacement(string $prefix): array
    {
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
        $admin = $factory->createAndPersist($prefix.'-admin@example.com', 'Guclu-Parola-123!', 'A', 'D', UserRole::Teacher);
        $admin->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $admin->transitionTo(UserStatus::Active);
        $admin->addGlobalRole(UserRole::Admin);
        $users->save($admin);

        $subject = $this->subjects()->create($sa, $prefix.'_s', 'Ders '.$prefix, 'create_s');
        $programs = $container->get(CurriculumProgramManager::class);
        $units = $container->get(CurriculumUnitManager::class);
        $topics = $container->get(CurriculumTopicManager::class);
        $outcomes = $container->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programs);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        self::assertInstanceOf(CurriculumTopicManager::class, $topics);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);

        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'create_p');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'create_t');
        $outcome = $outcomes->create($topic, $sa, $prefix.'_lo', 'Kazanim', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');

        $catalog = $this->catalog();
        $catalogSubject = $catalog->createSubject(GradeLevel::Grade1, 'Matematik '.$prefix, null, 1);
        $catalog->assignCanonicalSubject($admin, $catalogSubject->getId(), $subject->getId());
        $catalogUnit = $catalog->createUnit($catalogSubject->getId(), 'Tema '.$prefix, null, 0);
        $catalogTopic = $catalog->createTopic($catalogUnit->getId(), 'Konu '.$prefix, 'Özet', 0, 15);
        $catalog->publishSubject($catalogSubject->getId());
        $catalog->publishUnit($catalogUnit->getId());
        $catalog->publishTopic($catalogTopic->getId());

        /** @var QuestionManager $questions */
        $questions = $container->get(QuestionManager::class);
        /** @var AssessmentManager $assessments */
        $assessments = $container->get(AssessmentManager::class);
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
        $questions->publish($question, $admin, 'publish_approved');
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
            'Guard testi '.$prefix,
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
        $assessments->publish($assessment, $admin, 'publish_approved');
        /** @var AccessPackageManager $packages */
        $packages = $container->get(AccessPackageManager::class);
        $packages->setAssessmentAccessPolicy($assessment, $admin, ResourceAccessClass::Free, 'seed_policy');

        /** @var CatalogTopicAssessmentManager $placements */
        $placements = $container->get(CatalogTopicAssessmentManager::class);
        $draft = $placements->create($admin, $catalogTopic->getId(), $assessment->getId(), 'Test yerleşimi', null, 0, 'create_pl', 'test-yerlesim');
        $placements->publish($admin, $draft->getId(), 'publish_pl');

        return [
            'sa' => $sa,
            'admin' => $admin,
            'catalogSubjectId' => $catalogSubject->getId(),
            'canonicalSubjectId' => $subject->getId(),
        ];
    }

    private function catalog(): CatalogWriteService
    {
        $catalog = static::getContainer()->get(CatalogWriteService::class);
        self::assertInstanceOf(CatalogWriteService::class, $catalog);

        return $catalog;
    }

    private function subjects(): SubjectManager
    {
        $subjects = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjects);

        return $subjects;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
