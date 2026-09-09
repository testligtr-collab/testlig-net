<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Assessment\AssessmentPublicationIntegrityVerifier;
use App\Entity\Assessment;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentFailureReason;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResultReleasePolicy;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AssessmentException;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentPublicationRepository;
use App\Repository\AssessmentRevisionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\AssessmentManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\AssessmentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class AssessmentPointerIntegrityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private SecurityAuditEventRepository $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testCurrentPointerRejectsWrongAssessmentRevisionAndNonexistent(): void
    {
        [$assessmentA] = $this->seedDraftAssessment('ptr_a');
        [$assessmentB, $revisionB] = $this->seedDraftAssessment('ptr_b');
        $conn = $this->em->getConnection();

        try {
            $conn->executeStatement(
                'UPDATE assessments SET current_revision_id = ?, current_revision_number = ? WHERE id = ?',
                [$revisionB->getId()->toBinary(), $revisionB->getRevisionNumber(), $assessmentA->getId()->toBinary()],
            );
            self::fail('wrong assessment revision as current');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE assessments SET current_revision_id = ?, current_revision_number = 1 WHERE id = ?',
                [(new UuidV7())->toBinary(), $assessmentA->getId()->toBinary()],
            );
            self::fail('nonexistent revision as current');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE assessments SET published_revision_id = ?, published_revision_number = ? WHERE id = ?',
                [$revisionB->getId()->toBinary(), $revisionB->getRevisionNumber(), $assessmentA->getId()->toBinary()],
            );
            self::fail('wrong assessment revision as published');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        unset($assessmentB);
    }

    public function testNullPairCheckRejectsPartialPointers(): void
    {
        [$assessment, $revision] = $this->seedDraftAssessment('pair');
        $conn = $this->em->getConnection();

        try {
            $conn->executeStatement(
                'UPDATE assessments SET current_revision_id = ?, current_revision_number = NULL WHERE id = ?',
                [$revision->getId()->toBinary(), $assessment->getId()->toBinary()],
            );
            self::fail('id without number');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE assessments SET current_revision_id = NULL, current_revision_number = 1 WHERE id = ?',
                [$assessment->getId()->toBinary()],
            );
            self::fail('number without id');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE assessments SET published_revision_id = ?, published_revision_number = NULL WHERE id = ?',
                [$revision->getId()->toBinary(), $assessment->getId()->toBinary()],
            );
            self::fail('published id without number');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testSealedInsertRejectedUnsealedInsertOk(): void
    {
        [$assessment] = $this->seedDraftAssessment('sealbi');
        $conn = $this->em->getConnection();
        $author = $assessment->getCreatedBy();

        try {
            $conn->insert('assessment_revisions', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessment->getId()->toBinary(),
                'revision_number' => 99,
                'title' => 'SealedInsert',
                'description' => null,
                'instructions' => null,
                'duration_seconds' => 600,
                'navigation_mode' => NavigationMode::Free->value,
                'question_order_mode' => QuestionOrderMode::Fixed->value,
                'option_order_mode' => OptionOrderMode::Fixed->value,
                'result_release_policy' => ResultReleasePolicy::Immediate->value,
                'pass_score_percentage' => null,
                'created_by_id' => $author->getId()->toBinary(),
                'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'public_content_hash' => str_repeat('ab', 32),
                'schema_version' => 1,
                'is_sealed' => 1,
            ]);
            self::fail('sealed insert');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $unsealedId = new UuidV7();
        $conn->insert('assessment_revisions', [
            'id' => $unsealedId->toBinary(),
            'assessment_id' => $assessment->getId()->toBinary(),
            'revision_number' => 98,
            'title' => 'UnsealedInsert',
            'description' => null,
            'instructions' => null,
            'duration_seconds' => 600,
            'navigation_mode' => NavigationMode::Free->value,
            'question_order_mode' => QuestionOrderMode::Fixed->value,
            'option_order_mode' => OptionOrderMode::Fixed->value,
            'result_release_policy' => ResultReleasePolicy::Immediate->value,
            'pass_score_percentage' => null,
            'created_by_id' => $author->getId()->toBinary(),
            'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'public_content_hash' => str_repeat('cd', 32),
            'schema_version' => 1,
            'is_sealed' => 0,
        ]);
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM assessment_revisions WHERE id = ?',
            [$unsealedId->toBinary()],
        ));
    }

    public function testSealTransitionRules(): void
    {
        [$assessment] = $this->seedDraftAssessment('sealrules');
        $conn = $this->em->getConnection();
        $author = $assessment->getCreatedBy();
        $revId = new UuidV7();
        $conn->insert('assessment_revisions', [
            'id' => $revId->toBinary(),
            'assessment_id' => $assessment->getId()->toBinary(),
            'revision_number' => 50,
            'title' => 'SealMe',
            'description' => null,
            'instructions' => null,
            'duration_seconds' => 600,
            'navigation_mode' => NavigationMode::Free->value,
            'question_order_mode' => QuestionOrderMode::Fixed->value,
            'option_order_mode' => OptionOrderMode::Fixed->value,
            'result_release_policy' => ResultReleasePolicy::Immediate->value,
            'pass_score_percentage' => null,
            'created_by_id' => $author->getId()->toBinary(),
            'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'public_content_hash' => str_repeat('ef', 32),
            'schema_version' => 1,
            'is_sealed' => 0,
        ]);

        $conn->executeStatement(
            'UPDATE assessment_revisions SET is_sealed = 1 WHERE id = ?',
            [$revId->toBinary()],
        );
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT is_sealed FROM assessment_revisions WHERE id = ?',
            [$revId->toBinary()],
        ));

        try {
            $conn->executeStatement(
                'UPDATE assessment_revisions SET is_sealed = 0 WHERE id = ?',
                [$revId->toBinary()],
            );
            self::fail('unseal');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE assessment_revisions SET is_sealed = 1, title = ? WHERE id = ?',
                ['Changed', $revId->toBinary()],
            );
            self::fail('seal+title');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE assessment_revisions SET is_sealed = 1 WHERE id = ?',
                [$revId->toBinary()],
            );
            self::fail('double seal');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testUnsealedPublicationInsertRejected(): void
    {
        [$assessment] = $this->seedDraftAssessment('unsealpub');
        $conn = $this->em->getConnection();
        $author = $assessment->getCreatedBy();
        $revId = new UuidV7();
        $conn->insert('assessment_revisions', [
            'id' => $revId->toBinary(),
            'assessment_id' => $assessment->getId()->toBinary(),
            'revision_number' => 40,
            'title' => 'UnsealedPub',
            'description' => null,
            'instructions' => null,
            'duration_seconds' => 600,
            'navigation_mode' => NavigationMode::Free->value,
            'question_order_mode' => QuestionOrderMode::Fixed->value,
            'option_order_mode' => OptionOrderMode::Fixed->value,
            'result_release_policy' => ResultReleasePolicy::Immediate->value,
            'pass_score_percentage' => null,
            'created_by_id' => $author->getId()->toBinary(),
            'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'public_content_hash' => str_repeat('11', 32),
            'schema_version' => 1,
            'is_sealed' => 0,
        ]);
        $conn->executeStatement(
            'UPDATE assessments SET current_revision_id = ?, current_revision_number = 40 WHERE id = ?',
            [$revId->toBinary(), $assessment->getId()->toBinary()],
        );

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessment->getId()->toBinary(),
                'assessment_revision_id' => $revId->toBinary(),
                'publication_number' => 1,
                'manifest' => '{}',
                'manifest_hash' => str_repeat('22', 32),
                'published_by_id' => $author->getId()->toBinary(),
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('unsealed publication');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testPublicationForNonCurrentRevisionRejected(): void
    {
        [$assessment, $revision] = $this->seedPublishedAssessment('noncur');
        $conn = $this->em->getConnection();
        $author = $assessment->getCreatedBy();

        $oldRevId = new UuidV7();
        $conn->insert('assessment_revisions', [
            'id' => $oldRevId->toBinary(),
            'assessment_id' => $assessment->getId()->toBinary(),
            'revision_number' => 7,
            'title' => 'NotCurrent',
            'description' => null,
            'instructions' => null,
            'duration_seconds' => 600,
            'navigation_mode' => NavigationMode::Free->value,
            'question_order_mode' => QuestionOrderMode::Fixed->value,
            'option_order_mode' => OptionOrderMode::Fixed->value,
            'result_release_policy' => ResultReleasePolicy::Immediate->value,
            'pass_score_percentage' => null,
            'created_by_id' => $author->getId()->toBinary(),
            'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'public_content_hash' => str_repeat('33', 32),
            'schema_version' => 1,
            'is_sealed' => 0,
        ]);
        $conn->executeStatement(
            'UPDATE assessment_revisions SET is_sealed = 1 WHERE id = ?',
            [$oldRevId->toBinary()],
        );

        $pub = $conn->fetchAssociative(
            'SELECT * FROM assessment_publications WHERE assessment_id = ? LIMIT 1',
            [$assessment->getId()->toBinary()],
        );
        self::assertIsArray($pub);

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessment->getId()->toBinary(),
                'assessment_revision_id' => $oldRevId->toBinary(),
                'publication_number' => 9,
                'manifest' => $pub['manifest'],
                'manifest_hash' => $pub['manifest_hash'],
                'published_by_id' => $pub['published_by_id'],
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('non-current publication');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        unset($revision);
    }

    public function testSealedCurrentPublicationAcceptedViaManager(): void
    {
        [$assessment, $revision] = $this->seedPublishedAssessment('okpub');
        self::assertSame(AssessmentStatus::Published, $assessment->getStatus());
        self::assertNotNull($assessment->getPublishedRevision());
        self::assertTrue($assessment->getPublishedRevision()->getId()->equals($revision->getId()));
        self::assertSame(1, $assessment->getPublishedRevisionNumber());
        self::assertSame(1, $assessment->getCurrentRevisionNumber());
    }

    public function testDuplicatePublicationRevisionAndNumberRejected(): void
    {
        [$assessment, $revision] = $this->seedPublishedAssessment('duppub');
        $conn = $this->em->getConnection();
        $pub = $conn->fetchAssociative(
            'SELECT * FROM assessment_publications WHERE assessment_id = ? LIMIT 1',
            [$assessment->getId()->toBinary()],
        );
        self::assertIsArray($pub);

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessment->getId()->toBinary(),
                'assessment_revision_id' => $revision->getId()->toBinary(),
                'publication_number' => 2,
                'manifest' => $pub['manifest'],
                'manifest_hash' => $pub['manifest_hash'],
                'published_by_id' => $pub['published_by_id'],
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('duplicate revision publication');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Second sealed current revision for number uniqueness (swap current temporarily).
        $author = $assessment->getCreatedBy();
        $rev2 = new UuidV7();
        $conn->insert('assessment_revisions', [
            'id' => $rev2->toBinary(),
            'assessment_id' => $assessment->getId()->toBinary(),
            'revision_number' => 2,
            'title' => 'DupNum',
            'description' => null,
            'instructions' => null,
            'duration_seconds' => 600,
            'navigation_mode' => NavigationMode::Free->value,
            'question_order_mode' => QuestionOrderMode::Fixed->value,
            'option_order_mode' => OptionOrderMode::Fixed->value,
            'result_release_policy' => ResultReleasePolicy::Immediate->value,
            'pass_score_percentage' => null,
            'created_by_id' => $author->getId()->toBinary(),
            'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'public_content_hash' => str_repeat('44', 32),
            'schema_version' => 1,
            'is_sealed' => 0,
        ]);
        $conn->executeStatement('UPDATE assessment_revisions SET is_sealed = 1 WHERE id = ?', [$rev2->toBinary()]);
        $conn->executeStatement(
            'UPDATE assessments SET current_revision_id = ?, current_revision_number = 2 WHERE id = ?',
            [$rev2->toBinary(), $assessment->getId()->toBinary()],
        );

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessment->getId()->toBinary(),
                'assessment_revision_id' => $rev2->toBinary(),
                'publication_number' => 1,
                'manifest' => $pub['manifest'],
                'manifest_hash' => $pub['manifest_hash'],
                'published_by_id' => $pub['published_by_id'],
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('duplicate publication number');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testPublishWithTamperedPublicContentHashFails(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('tamp');
        $this->programs()->publish($program, $sa, 'pub');
        $q = $this->createPublishedQuestion($sa, $reviewer, $subject, $lo, 'tamp');
        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qr = $qRevisions->findForQuestionNumber($q, 1);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        $assessment = $this->assessments()->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::MockExam,
            GradeLevel::Grade9,
            'Tamper Blueprint',
            null,
            null,
            600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [[
                'title' => 'S1',
                'position' => 1,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $q->getId(),
                    'questionRevisionId' => $qr->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'create_tamp',
        );
        $this->assessments()->submitForReview($assessment, $sa, 'submit_tamp');

        $conn = $this->em->getConnection();
        /** @var AssessmentRevisionRepository $revisions */
        $revisions = static::getContainer()->get(AssessmentRevisionRepository::class);
        $good = $revisions->findForAssessmentNumber($assessment, 1);
        self::assertInstanceOf(AssessmentRevision::class, $good);

        $section = $conn->fetchAssociative(
            'SELECT * FROM assessment_sections WHERE revision_id = ? LIMIT 1',
            [$good->getId()->toBinary()],
        );
        $item = $conn->fetchAssociative(
            'SELECT * FROM assessment_items WHERE assessment_revision_id = ? LIMIT 1',
            [$good->getId()->toBinary()],
        );
        self::assertIsArray($section);
        self::assertIsArray($item);

        $badRevId = new UuidV7();
        $conn->insert('assessment_revisions', [
            'id' => $badRevId->toBinary(),
            'assessment_id' => $assessment->getId()->toBinary(),
            'revision_number' => 2,
            'title' => $good->getTitle(),
            'description' => $good->getDescription(),
            'instructions' => $good->getInstructions(),
            'duration_seconds' => $good->getDurationSeconds(),
            'navigation_mode' => $good->getNavigationMode()->value,
            'question_order_mode' => $good->getQuestionOrderMode()->value,
            'option_order_mode' => $good->getOptionOrderMode()->value,
            'result_release_policy' => $good->getResultReleasePolicy()->value,
            'pass_score_percentage' => $good->getPassScorePercentage(),
            'created_by_id' => $sa->getId()->toBinary(),
            'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'public_content_hash' => str_repeat('0a', 32),
            'schema_version' => 1,
            'is_sealed' => 0,
        ]);
        $badSectionId = new UuidV7();
        $conn->insert('assessment_sections', [
            'id' => $badSectionId->toBinary(),
            'revision_id' => $badRevId->toBinary(),
            'title' => $section['title'],
            'instructions' => $section['instructions'],
            'position' => $section['position'],
            'duration_seconds' => $section['duration_seconds'],
            'question_order_mode' => $section['question_order_mode'],
            'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
        $conn->insert('assessment_items', [
            'id' => (new UuidV7())->toBinary(),
            'section_id' => $badSectionId->toBinary(),
            'assessment_revision_id' => $badRevId->toBinary(),
            'question_id' => $item['question_id'],
            'question_revision_id' => $item['question_revision_id'],
            'position' => $item['position'],
            'points' => $item['points'],
            'penalty_points' => $item['penalty_points'],
            'required' => $item['required'],
            'option_order_mode' => $item['option_order_mode'],
            'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
        $conn->executeStatement(
            'UPDATE assessment_revisions SET is_sealed = 1 WHERE id = ?',
            [$badRevId->toBinary()],
        );
        $conn->executeStatement(
            'UPDATE assessments SET current_revision_id = ?, current_revision_number = 2 WHERE id = ?',
            [$badRevId->toBinary(), $assessment->getId()->toBinary()],
        );

        $this->em->clear();
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertInstanceOf(User::class, $reviewer);
        self::assertSame(AssessmentStatus::InReview, $assessment->getStatus());

        $publishedBefore = $this->events->countByAction(SecurityAuditAction::AssessmentPublished->value);
        $pubCreatedBefore = $this->events->countByAction(SecurityAuditAction::AssessmentPublicationCreated->value);

        try {
            $this->assessments()->publish($assessment, $reviewer, 'publish_tamp');
            self::fail('tampered hash publish');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::PublicContentIntegrityFailed, $e->getReason());
        }

        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertSame(AssessmentStatus::InReview, $assessment->getStatus());
        self::assertNull($assessment->getPublishedRevisionNumber());
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ?',
            [$assessment->getId()->toBinary()],
        ));
        self::assertSame($publishedBefore, $this->events->countByAction(SecurityAuditAction::AssessmentPublished->value));
        self::assertSame($pubCreatedBefore, $this->events->countByAction(SecurityAuditAction::AssessmentPublicationCreated->value));
    }

    public function testManifestVerifierRejectsWrongHash(): void
    {
        [$assessment, $revision] = $this->seedPublishedAssessment('verif');
        /** @var AssessmentPublicationRepository $pubs */
        $pubs = static::getContainer()->get(AssessmentPublicationRepository::class);
        $publication = $pubs->findOneBy(['assessment' => $assessment]);
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        $verifier = static::getContainer()->get(AssessmentPublicationIntegrityVerifier::class);
        self::assertInstanceOf(AssessmentPublicationIntegrityVerifier::class, $verifier);
        $verifier->verify($publication, $assessment, $revision);

        $originalHash = $publication->getManifestHash();
        $hashRef = new \ReflectionProperty($publication, 'manifestHash');
        $hashRef->setValue($publication, str_repeat('ff', 32));

        try {
            $verifier->verify($publication, $assessment, $revision);
            self::fail('wrong hash');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::PublicationInvalid, $e->getReason());
        }

        $hashRef->setValue($publication, $originalHash);
        $manifestRef = new \ReflectionProperty($publication, 'manifest');
        $manifest = $publication->getManifest();
        $manifest['title'] = 'tampered-title';
        $manifestRef->setValue($publication, $manifest);
        try {
            $verifier->verify($publication, $assessment, $revision);
            self::fail('manifest changed with stale hash');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::PublicationInvalid, $e->getReason());
        }
    }

    public function testNewTriggersHaveNoBypassOrSessionVars(): void
    {
        $bodies = $this->em->getConnection()->fetchFirstColumn(
            "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME IN (
                 'trg_assessment_revisions_bi',
                 'trg_assessment_publications_bi',
                 'trg_assessment_publications_ai_sync_published',
                 'trg_assessments_bu_published_matches_latest_publication',
                 'trg_assessment_revisions_bu'
               )",
        );
        self::assertCount(5, $bodies);
        foreach ($bodies as $body) {
            self::assertStringNotContainsStringIgnoringCase('@testlig', (string) $body);
            self::assertStringNotContainsStringIgnoringCase('bypass', (string) $body);
            self::assertStringNotContainsStringIgnoringCase('@', (string) $body);
        }
    }

    public function testParentCascadeCleanupStillWorks(): void
    {
        $this->seedPublishedAssessment('casc2');
        $conn = $this->em->getConnection();
        self::assertGreaterThan(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_revisions'));
        AssessmentDbCleanup::deleteAssessments($conn);
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessments'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_revisions'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_publications'));
    }

    public function testPointerTransitionAcrossCreateRevisionAndPublish(): void
    {
        [$assessment, $revision1] = $this->seedPublishedAssessment('ptrtr');
        self::assertSame(1, $assessment->getCurrentRevisionNumber());
        self::assertSame(1, $assessment->getPublishedRevisionNumber());
        self::assertNotNull($assessment->getCurrentRevision());
        self::assertTrue($assessment->getCurrentRevision()->getId()->equals($revision1->getId()));

        $sa = $assessment->getCreatedBy();
        $item = $this->em->getConnection()->fetchAssociative(
            'SELECT question_id, question_revision_id FROM assessment_items WHERE assessment_revision_id = ? LIMIT 1',
            [$revision1->getId()->toBinary()],
        );
        self::assertIsArray($item);
        $q = $this->em->find(Question::class, Uuid::fromBinary($item['question_id']));
        $qr = $this->em->find(QuestionRevision::class, Uuid::fromBinary($item['question_revision_id']));
        self::assertInstanceOf(Question::class, $q);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        $rev2 = $this->assessments()->createRevision(
            $assessment,
            $sa,
            'Pointer v2',
            null,
            null,
            600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [[
                'title' => 'S1',
                'position' => 1,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $q->getId(),
                    'questionRevisionId' => $qr->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'rev2_ptr',
        );
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertSame(2, $assessment->getCurrentRevisionNumber());
        self::assertSame(1, $assessment->getPublishedRevisionNumber());
        self::assertNotNull($assessment->getCurrentRevision());
        self::assertTrue($assessment->getCurrentRevision()->getId()->equals($rev2->getId()));
        self::assertNotNull($assessment->getPublishedRevision());
        self::assertTrue($assessment->getPublishedRevision()->getId()->equals($revision1->getId()));

        $this->assessments()->submitForReview($assessment, $sa, 'submit2');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $reviewer = null;
        foreach ($this->users->findAll() as $user) {
            if (\in_array(UserRole::HeadTeacher->value, $user->getRoles(), true)
                && !\in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
                $reviewer = $user;
                break;
            }
        }
        self::assertInstanceOf(User::class, $reviewer);
        $this->assessments()->publish($assessment, $reviewer, 'publish2');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertSame(2, $assessment->getCurrentRevisionNumber());
        self::assertSame(2, $assessment->getPublishedRevisionNumber());
        self::assertNotNull($assessment->getCurrentRevision());
        self::assertNotNull($assessment->getPublishedRevision());
        self::assertTrue($assessment->getCurrentRevision()->getId()->equals($rev2->getId()));
        self::assertTrue($assessment->getPublishedRevision()->getId()->equals($rev2->getId()));
    }

    /**
     * @return array{0: Assessment, 1: AssessmentRevision}
     */
    private function seedDraftAssessment(string $suffix): array
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum($suffix);
        $this->programs()->publish($program, $sa, 'pub');
        $q = $this->createPublishedQuestion($sa, $reviewer, $subject, $lo, $suffix);
        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qr = $qRevisions->findForQuestionNumber($q, 1);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        $assessment = $this->assessments()->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::MockExam,
            GradeLevel::Grade9,
            'Draft '.$suffix,
            null,
            null,
            600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [[
                'title' => 'S1',
                'position' => 1,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $q->getId(),
                    'questionRevisionId' => $qr->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'create_'.$suffix,
        );
        self::assertSame(1, $assessment->getCurrentRevisionNumber());
        self::assertNotNull($assessment->getCurrentRevision());

        /** @var AssessmentRevisionRepository $revisions */
        $revisions = static::getContainer()->get(AssessmentRevisionRepository::class);
        $revision = $revisions->findForAssessmentNumber($assessment, 1);
        self::assertInstanceOf(AssessmentRevision::class, $revision);

        return [$assessment, $revision];
    }

    /**
     * @return array{0: Assessment, 1: AssessmentRevision}
     */
    private function seedPublishedAssessment(string $suffix): array
    {
        [$assessment] = $this->seedDraftAssessment($suffix);
        $sa = $assessment->getCreatedBy();
        $this->assessments()->submitForReview($assessment, $sa, 'submit');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

        $reviewer = null;
        foreach ($this->users->findAll() as $user) {
            if (\in_array(UserRole::HeadTeacher->value, $user->getRoles(), true)
                && !\in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
                $reviewer = $user;
                break;
            }
        }
        self::assertInstanceOf(User::class, $reviewer);
        $this->assessments()->publish($assessment, $reviewer, 'publish');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

        /** @var AssessmentRevisionRepository $revisions */
        $revisions = static::getContainer()->get(AssessmentRevisionRepository::class);
        $revision = $revisions->findForAssessmentNumber($assessment, 1);
        self::assertInstanceOf(AssessmentRevision::class, $revision);

        return [$assessment, $revision];
    }

    private function createPublishedQuestion(
        User $author,
        User $publisher,
        Subject $subject,
        CurriculumLearningOutcome $lo,
        string $suffix,
    ): Question {
        $question = $this->questions()->createDraftQuestion(
            $author,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('TF '.$suffix.'?'),
            null,
            [],
            ['correct' => true],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.$suffix,
        );
        $this->questions()->submitForReview($question, $author, 'submit_q');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);
        $publisher = $this->users->find($publisher->getId());
        self::assertInstanceOf(User::class, $publisher);
        $this->questions()->publish($question, $publisher, 'pub_q');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);

        return $question;
    }

    /**
     * @return array{0: User, 1: User, 2: Subject, 3: CurriculumProgram, 4: CurriculumLearningOutcome}
     */
    private function platformCurriculum(string $suffix): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $reviewer = $this->activeUser($suffix.'-rev@example.com', UserRole::HeadTeacher);
        $subject = $this->subjects()->create($sa, 'math_'.$suffix, 'Math '.$suffix, 'create_subj');
        $draft = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade9, 'math_'.$suffix, 'Math', '1.0', 'prog');
        $unit = $this->units()->create($draft, $sa, 'u1', 'Unit', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'Topic', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $sa, 'lo_'.$suffix, 'Outcome', 1, 'create_lo');

        return [$sa, $reviewer, $subject, $draft, $lo];
    }

    private function assessments(): AssessmentManager
    {
        $s = static::getContainer()->get(AssessmentManager::class);
        self::assertInstanceOf(AssessmentManager::class, $s);

        return $s;
    }

    private function questions(): QuestionManager
    {
        $s = static::getContainer()->get(QuestionManager::class);
        self::assertInstanceOf(QuestionManager::class, $s);

        return $s;
    }

    private function subjects(): SubjectManager
    {
        $s = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $s);

        return $s;
    }

    private function programs(): CurriculumProgramManager
    {
        $s = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $s);

        return $s;
    }

    private function units(): CurriculumUnitManager
    {
        $s = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $s);

        return $s;
    }

    private function topics(): CurriculumTopicManager
    {
        $s = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $s);

        return $s;
    }

    private function outcomes(): CurriculumLearningOutcomeManager
    {
        $s = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $s);

        return $s;
    }

    private function superAdmin(string $email): User
    {
        $user = $this->activeUser($email, UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function activeUser(string $email, UserRole $role = UserRole::Student): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $role);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
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
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        AssessmentDbCleanup::deleteAssessments($connection);
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'users',
        ]);
    }
}
