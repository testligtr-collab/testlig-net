<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assessment;
use App\Entity\AssessmentRevision;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
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
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentRevisionRepository;
use App\Repository\QuestionRevisionRepository;
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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Commit-visible bidirectional publication ↔ published-pointer integrity.
 */
final class AssessmentPublicationPointerSyncTest extends KernelTestCase
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

    public function testDirectDbalPublicationInsertCommitsAndSyncsPublishedPointer(): void
    {
        [$assessment, $revision, $author] = $this->seedInReviewAssessment('dbsync1');
        $assessmentId = $assessment->getId();
        $revisionId = $revision->getId();
        $manifest = $this->safeManifest($assessmentId->toRfc4122(), $revisionId->toRfc4122());
        $hash = hash('sha256', $manifest);

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessmentId->toBinary(),
                'assessment_revision_id' => $revisionId->toBinary(),
                'publication_number' => 1,
                'manifest' => $manifest,
                'manifest_hash' => $hash,
                'published_by_id' => $author->getId()->toBinary(),
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        // Fresh connection / clear EM — prove commit-visible state.
        $this->em->clear();
        $fresh = $this->freshConnection();
        $row = $fresh->fetchAssociative(
            'SELECT status, published_revision_id, published_revision_number FROM assessments WHERE id = ?',
            [$assessmentId->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame(AssessmentStatus::Published->value, $row['status']);
        self::assertSame($revisionId->toBinary(), $row['published_revision_id']);
        self::assertSame(1, (int) $row['published_revision_number']);
        self::assertSame(1, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ? AND assessment_revision_id = ?',
            [$assessmentId->toBinary(), $revisionId->toBinary()],
        ));

        $entity = $this->em->find(Assessment::class, $assessmentId);
        self::assertInstanceOf(Assessment::class, $entity);
        self::assertSame(AssessmentStatus::Published, $entity->getStatus());
        self::assertNotNull($entity->getPublishedRevision());
        self::assertTrue($entity->getPublishedRevision()->getId()->equals($revisionId));
    }

    public function testSecondPublicationMovesPointerWhilePreservingHistory(): void
    {
        [$assessment, $revision1] = $this->seedPublishedAssessment('hist');
        $assessmentId = $assessment->getId();
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
            'History v2',
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
            'rev2_hist',
        );
        $assessment = $this->em->find(Assessment::class, $assessmentId);
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertSame(2, $assessment->getCurrentRevisionNumber());
        self::assertSame(1, $assessment->getPublishedRevisionNumber());

        $this->assessments()->submitForReview($assessment, $sa, 'submit2');
        $assessment = $this->em->find(Assessment::class, $assessmentId);
        self::assertInstanceOf(Assessment::class, $assessment);
        $reviewer = $this->findHeadTeacherReviewer();
        $this->assessments()->publish($assessment, $reviewer, 'publish2');

        $this->em->clear();
        $fresh = $this->freshConnection();
        $row = $fresh->fetchAssociative(
            'SELECT status, published_revision_id, published_revision_number FROM assessments WHERE id = ?',
            [$assessmentId->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame(AssessmentStatus::Published->value, $row['status']);
        self::assertSame($rev2->getId()->toBinary(), $row['published_revision_id']);
        self::assertSame(2, (int) $row['published_revision_number']);
        self::assertSame(2, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ?',
            [$assessmentId->toBinary()],
        ));
        self::assertSame(1, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ? AND assessment_revision_id = ? AND publication_number = 1',
            [$assessmentId->toBinary(), $revision1->getId()->toBinary()],
        ));
        self::assertSame(1, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ? AND assessment_revision_id = ? AND publication_number = 2',
            [$assessmentId->toBinary(), $rev2->getId()->toBinary()],
        ));
    }

    public function testPublicationNumberMustBeSequentialPerAssessment(): void
    {
        [$assessmentA, $revisionA, $authorA] = $this->seedInReviewAssessment('num_a');
        [$assessmentB, $revisionB, $authorB] = $this->seedInReviewAssessment('num_b');
        $conn = $this->em->getConnection();

        $manifestA = $this->safeManifest($assessmentA->getId()->toRfc4122(), $revisionA->getId()->toRfc4122());
        $hashA = hash('sha256', $manifestA);

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessmentA->getId()->toBinary(),
                'assessment_revision_id' => $revisionA->getId()->toBinary(),
                'publication_number' => 99,
                'manifest' => $manifestA,
                'manifest_hash' => $hashA,
                'published_by_id' => $authorA->getId()->toBinary(),
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('first publication number 99');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessmentA->getId()->toBinary(),
                'assessment_revision_id' => $revisionA->getId()->toBinary(),
                'publication_number' => 2,
                'manifest' => $manifestA,
                'manifest_hash' => $hashA,
                'published_by_id' => $authorA->getId()->toBinary(),
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('first publication number 2');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $conn->insert('assessment_publications', [
            'id' => (new UuidV7())->toBinary(),
            'assessment_id' => $assessmentA->getId()->toBinary(),
            'assessment_revision_id' => $revisionA->getId()->toBinary(),
            'publication_number' => 1,
            'manifest' => $manifestA,
            'manifest_hash' => $hashA,
            'published_by_id' => $authorA->getId()->toBinary(),
            'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'schema_version' => 1,
        ]);

        // Independent assessment counter starts at 1.
        $manifestB = $this->safeManifest($assessmentB->getId()->toRfc4122(), $revisionB->getId()->toRfc4122());
        $conn->insert('assessment_publications', [
            'id' => (new UuidV7())->toBinary(),
            'assessment_id' => $assessmentB->getId()->toBinary(),
            'assessment_revision_id' => $revisionB->getId()->toBinary(),
            'publication_number' => 1,
            'manifest' => $manifestB,
            'manifest_hash' => hash('sha256', $manifestB),
            'published_by_id' => $authorB->getId()->toBinary(),
            'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'schema_version' => 1,
        ]);

        // Prepare second publication for A (revision 2).
        $assessmentA = $this->em->find(Assessment::class, $assessmentA->getId());
        self::assertInstanceOf(Assessment::class, $assessmentA);
        $sa = $assessmentA->getCreatedBy();
        $item = $conn->fetchAssociative(
            'SELECT question_id, question_revision_id FROM assessment_items WHERE assessment_revision_id = ? LIMIT 1',
            [$revisionA->getId()->toBinary()],
        );
        self::assertIsArray($item);
        $q = $this->em->find(Question::class, Uuid::fromBinary($item['question_id']));
        $qr = $this->em->find(QuestionRevision::class, Uuid::fromBinary($item['question_revision_id']));
        self::assertInstanceOf(Question::class, $q);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        $rev2 = $this->assessments()->createRevision(
            $assessmentA,
            $sa,
            'Num v2',
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
            'rev2_num',
        );
        $assessmentA = $this->em->find(Assessment::class, $assessmentA->getId());
        self::assertInstanceOf(Assessment::class, $assessmentA);
        $this->assessments()->submitForReview(
            $assessmentA,
            $sa,
            'submit_num2',
        );

        $manifest2 = $this->safeManifest($assessmentA->getId()->toRfc4122(), $rev2->getId()->toRfc4122());
        $hash2 = hash('sha256', $manifest2);

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessmentA->getId()->toBinary(),
                'assessment_revision_id' => $rev2->getId()->toBinary(),
                'publication_number' => 1,
                'manifest' => $manifest2,
                'manifest_hash' => $hash2,
                'published_by_id' => $sa->getId()->toBinary(),
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('second publication number 1');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessmentA->getId()->toBinary(),
                'assessment_revision_id' => $rev2->getId()->toBinary(),
                'publication_number' => 3,
                'manifest' => $manifest2,
                'manifest_hash' => $hash2,
                'published_by_id' => $sa->getId()->toBinary(),
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('second publication number 3');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $conn->insert('assessment_publications', [
            'id' => (new UuidV7())->toBinary(),
            'assessment_id' => $assessmentA->getId()->toBinary(),
            'assessment_revision_id' => $rev2->getId()->toBinary(),
            'publication_number' => 2,
            'manifest' => $manifest2,
            'manifest_hash' => $hash2,
            'published_by_id' => $sa->getId()->toBinary(),
            'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'schema_version' => 1,
        ]);
        self::assertSame(2, (int) $conn->fetchOne(
            'SELECT published_revision_number FROM assessments WHERE id = ?',
            [$assessmentA->getId()->toBinary()],
        ));
    }

    public function testFailedPublicationInsertDoesNotMutatePointer(): void
    {
        [$assessment, $revision, $author] = $this->seedInReviewAssessment('failins');
        $conn = $this->em->getConnection();
        $before = $conn->fetchAssociative(
            'SELECT status, published_revision_id, published_revision_number FROM assessments WHERE id = ?',
            [$assessment->getId()->toBinary()],
        );
        self::assertIsArray($before);
        self::assertSame(AssessmentStatus::InReview->value, $before['status']);
        self::assertNull($before['published_revision_id']);

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessment->getId()->toBinary(),
                'assessment_revision_id' => $revision->getId()->toBinary(),
                'publication_number' => 1,
                'manifest' => '{}',
                'manifest_hash' => 'not-a-valid-hash',
                'published_by_id' => $author->getId()->toBinary(),
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('invalid hash');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $after = $conn->fetchAssociative(
            'SELECT status, published_revision_id, published_revision_number FROM assessments WHERE id = ?',
            [$assessment->getId()->toBinary()],
        );
        self::assertIsArray($after);
        self::assertSame($before['status'], $after['status']);
        self::assertNull($after['published_revision_id']);
        self::assertNull($after['published_revision_number']);
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ?',
            [$assessment->getId()->toBinary()],
        ));
    }

    public function testCannotClearAllPointersWhilePublicationsExist(): void
    {
        [$assessment, $revision] = $this->seedPublishedAssessment('detach');
        $assessmentId = $assessment->getId();
        $conn = $this->em->getConnection();
        $before = $conn->fetchAssociative(
            'SELECT status, current_revision_id, current_revision_number, published_revision_id, published_revision_number
             FROM assessments WHERE id = ?',
            [$assessmentId->toBinary()],
        );
        self::assertIsArray($before);

        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'UPDATE assessments SET
                    current_revision_id = NULL,
                    current_revision_number = NULL,
                    published_revision_id = NULL,
                    published_revision_number = NULL
                 WHERE id = ?',
                [$assessmentId->toBinary()],
            );
            $conn->commit();
            self::fail('clearing pointers while publications exist must be rejected');
        } catch (DbalException $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            self::assertStringContainsStringIgnoringCase('cannot clear published pointer', $e->getMessage());
        }

        $this->em->clear();
        $fresh = $this->freshConnection();
        $after = $fresh->fetchAssociative(
            'SELECT status, current_revision_id, current_revision_number, published_revision_id, published_revision_number
             FROM assessments WHERE id = ?',
            [$assessmentId->toBinary()],
        );
        self::assertIsArray($after);
        self::assertSame($before['status'], $after['status']);
        self::assertSame($before['current_revision_id'], $after['current_revision_id']);
        self::assertSame($before['current_revision_number'], $after['current_revision_number']);
        self::assertSame($before['published_revision_id'], $after['published_revision_id']);
        self::assertSame($before['published_revision_number'], $after['published_revision_number']);
        self::assertSame(1, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ?',
            [$assessmentId->toBinary()],
        ));
        self::assertSame($revision->getId()->toBinary(), $after['published_revision_id']);
    }

    public function testCannotClearPublishedPointerAloneWhilePublicationsExist(): void
    {
        [$assessment] = $this->seedPublishedAssessment('pubnull');
        $assessmentId = $assessment->getId();
        $conn = $this->em->getConnection();

        try {
            $conn->executeStatement(
                'UPDATE assessments SET published_revision_id = NULL, published_revision_number = NULL WHERE id = ?',
                [$assessmentId->toBinary()],
            );
            self::fail('clearing published pointer alone must be rejected');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('cannot clear published pointer', $e->getMessage());
        }

        self::assertNotNull($conn->fetchOne(
            'SELECT published_revision_id FROM assessments WHERE id = ?',
            [$assessmentId->toBinary()],
        ));
    }

    public function testParentAssessmentDeleteCascadesWithoutPointerDetach(): void
    {
        [$keepAssessment, $keepRevision] = $this->seedPublishedAssessment('keep');
        [$dropAssessment] = $this->seedPublishedAssessment('drop');
        $keepId = $keepAssessment->getId();
        $dropId = $dropAssessment->getId();
        $keepRevisionId = $keepRevision->getId();

        $conn = $this->em->getConnection();
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessments'));
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_publications'));

        $conn->beginTransaction();
        try {
            $conn->executeStatement('DELETE FROM assessments WHERE id = ?', [$dropId->toBinary()]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        $this->em->clear();
        $fresh = $this->freshConnection();
        self::assertSame(0, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessments WHERE id = ?',
            [$dropId->toBinary()],
        ));
        self::assertSame(0, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_revisions WHERE assessment_id = ?',
            [$dropId->toBinary()],
        ));
        self::assertSame(0, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ?',
            [$dropId->toBinary()],
        ));
        self::assertSame(0, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_sections s
             INNER JOIN assessment_revisions r ON r.id = s.revision_id
             WHERE r.assessment_id = ?',
            [$dropId->toBinary()],
        ));
        self::assertSame(0, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_items i
             INNER JOIN assessment_revisions r ON r.id = i.assessment_revision_id
             WHERE r.assessment_id = ?',
            [$dropId->toBinary()],
        ));

        $kept = $fresh->fetchAssociative(
            'SELECT published_revision_id, published_revision_number, status FROM assessments WHERE id = ?',
            [$keepId->toBinary()],
        );
        self::assertIsArray($kept);
        self::assertSame($keepRevisionId->toBinary(), $kept['published_revision_id']);
        self::assertSame(1, (int) $kept['published_revision_number']);
        self::assertSame(AssessmentStatus::Published->value, $kept['status']);
        self::assertSame(1, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ?',
            [$keepId->toBinary()],
        ));
        self::assertGreaterThan(0, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_revisions WHERE assessment_id = ?',
            [$keepId->toBinary()],
        ));
    }

    public function testCannotRevertPublishedPointerToOlderRevision(): void
    {
        [$assessment, $revision1] = $this->seedPublishedAssessment('revert');
        $assessmentId = $assessment->getId();
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
            'Revert v2',
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
            'rev2_revert',
        );
        $assessment = $this->em->find(Assessment::class, $assessmentId);
        self::assertInstanceOf(Assessment::class, $assessment);
        $this->assessments()->submitForReview($assessment, $sa, 'submit_rev');
        $assessment = $this->em->find(Assessment::class, $assessmentId);
        self::assertInstanceOf(Assessment::class, $assessment);
        $this->assessments()->publish(
            $assessment,
            $this->findHeadTeacherReviewer(),
            'publish_rev',
        );

        $conn = $this->em->getConnection();
        try {
            $conn->executeStatement(
                'UPDATE assessments SET published_revision_id = ?, published_revision_number = 1 WHERE id = ?',
                [$revision1->getId()->toBinary(), $assessmentId->toBinary()],
            );
            self::fail('revert to older publication');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertSame(
            $rev2->getId()->toBinary(),
            $conn->fetchOne(
                'SELECT published_revision_id FROM assessments WHERE id = ?',
                [$assessmentId->toBinary()],
            ),
        );
    }

    public function testArchiveKeepsPublishedPointerAndPublicationHistory(): void
    {
        [$assessment, $revision] = $this->seedPublishedAssessment('arch');
        $assessmentId = $assessment->getId();
        $reviewer = $this->findHeadTeacherReviewer();
        $this->assessments()->archive($assessment, $reviewer, 'archive_ok');

        $this->em->clear();
        $fresh = $this->freshConnection();
        $row = $fresh->fetchAssociative(
            'SELECT status, published_revision_id, published_revision_number FROM assessments WHERE id = ?',
            [$assessmentId->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame(AssessmentStatus::Archived->value, $row['status']);
        self::assertSame($revision->getId()->toBinary(), $row['published_revision_id']);
        self::assertSame(1, (int) $row['published_revision_number']);
        self::assertSame(1, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM assessment_publications WHERE assessment_id = ?',
            [$assessmentId->toBinary()],
        ));
    }

    public function testTriggerBodiesHaveNoBypassOrSessionVariables(): void
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME IN (
                 'trg_assessment_publications_bi',
                 'trg_assessment_publications_ai_sync_published',
                 'trg_assessments_bu_published_matches_latest_publication'
               )
             ORDER BY TRIGGER_NAME",
        );
        self::assertCount(3, $rows);
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['TRIGGER_NAME']] = $row;
            $body = (string) $row['ACTION_STATEMENT'];
            self::assertStringNotContainsStringIgnoringCase('@testlig', $body);
            self::assertStringNotContainsStringIgnoringCase('bypass', $body);
            self::assertStringNotContainsStringIgnoringCase('FOREIGN_KEY_CHECKS', $body);
            self::assertStringNotContainsStringIgnoringCase('@', $body);
            self::assertStringNotContainsStringIgnoringCase('full detach', $body);
            self::assertStringNotContainsStringIgnoringCase('parent DELETE cleanup', $body);
        }
        self::assertSame('BEFORE', $byName['trg_assessment_publications_bi']['ACTION_TIMING']);
        self::assertSame('INSERT', $byName['trg_assessment_publications_bi']['EVENT_MANIPULATION']);
        self::assertSame('AFTER', $byName['trg_assessment_publications_ai_sync_published']['ACTION_TIMING']);
        self::assertSame('INSERT', $byName['trg_assessment_publications_ai_sync_published']['EVENT_MANIPULATION']);
        self::assertSame('BEFORE', $byName['trg_assessments_bu_published_matches_latest_publication']['ACTION_TIMING']);
        self::assertSame('UPDATE', $byName['trg_assessments_bu_published_matches_latest_publication']['EVENT_MANIPULATION']);
        self::assertStringContainsString(
            'cannot clear published pointer while publications exist',
            (string) $byName['trg_assessments_bu_published_matches_latest_publication']['ACTION_STATEMENT'],
        );
    }

    public function testPointerForeignKeysUseOnDeleteCascade(): void
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE, GROUP_CONCAT(kcu.COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION) AS cols
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.TABLE_NAME = kcu.TABLE_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.TABLE_NAME = 'assessments'
               AND rc.CONSTRAINT_NAME IN (
                 'FK_4BFCEC0AA32ED756',
                 'FK_4BFCEC0AFE671D30',
                 'FK_ASSESSMENT_CURRENT_REVISION',
                 'FK_ASSESSMENT_PUBLISHED_REVISION'
               )
             GROUP BY rc.CONSTRAINT_NAME, rc.DELETE_RULE
             ORDER BY rc.CONSTRAINT_NAME",
        );
        self::assertCount(4, $rows);
        foreach ($rows as $row) {
            self::assertSame('CASCADE', $row['DELETE_RULE'], (string) $row['CONSTRAINT_NAME']);
        }
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['CONSTRAINT_NAME']] = $row['cols'];
        }
        self::assertSame('current_revision_id', $byName['FK_4BFCEC0AA32ED756']);
        self::assertSame('published_revision_id', $byName['FK_4BFCEC0AFE671D30']);
        self::assertSame('current_revision_id,id,current_revision_number', $byName['FK_ASSESSMENT_CURRENT_REVISION']);
        self::assertSame('published_revision_id,id,published_revision_number', $byName['FK_ASSESSMENT_PUBLISHED_REVISION']);
    }

    /**
     * @return array{0: Assessment, 1: AssessmentRevision, 2: User}
     */
    private function seedInReviewAssessment(string $suffix): array
    {
        [$assessment, $revision] = $this->seedDraftAssessment($suffix);
        $sa = $assessment->getCreatedBy();
        $this->assessments()->submitForReview($assessment, $sa, 'submit_'.$suffix);
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertSame(AssessmentStatus::InReview, $assessment->getStatus());

        return [$assessment, $revision, $sa];
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
        [$assessment, $revision] = $this->seedInReviewAssessment($suffix);
        $this->assessments()->publish($assessment, $this->findHeadTeacherReviewer(), 'publish_'.$suffix);
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

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

    private function safeManifest(string $assessmentId, string $revisionId): string
    {
        return json_encode([
            'assessmentId' => $assessmentId,
            'assessmentRevisionId' => $revisionId,
            'schemaVersion' => 1,
            'sections' => [],
        ], \JSON_THROW_ON_ERROR);
    }

    private function findHeadTeacherReviewer(): User
    {
        foreach ($this->users->findAll() as $user) {
            if (\in_array(UserRole::HeadTeacher->value, $user->getRoles(), true)
                && !\in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
                return $user;
            }
        }
        self::fail('head teacher reviewer missing');
    }

    private function freshConnection(): Connection
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em->getConnection();
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
