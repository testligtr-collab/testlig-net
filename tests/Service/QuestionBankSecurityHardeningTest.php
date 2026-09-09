<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Question;
use App\Entity\QuestionAnswerKey;
use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionOption;
use App\Entity\SecurityAuditEvent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionFailureReason;
use App\Enum\QuestionScope;
use App\Enum\QuestionSourceType;
use App\Enum\QuestionStatus;
use App\Enum\QuestionType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\QuestionException;
use App\Question\Answer\QuestionAnswerIntegrityHasher;
use App\Question\Content\QuestionContentDocument;
use App\Question\Content\QuestionContentHasher;
use App\Question\Content\QuestionPublicContentHashBuilder;
use App\Repository\QuestionAnswerKeyRepository;
use App\Repository\QuestionRevisionAlignmentRepository;
use App\Repository\QuestionRevisionOptionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\JsonKeyTree;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class QuestionBankSecurityHardeningTest extends KernelTestCase
{
    private const FORBIDDEN_SERIALIZER_KEYS = [
        'answerPayload',
        'correctStableKey',
        'correctStableKeys',
        'answer_integrity_hmac',
        'answerIntegrityHmac',
    ];

    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testPublicContentHashOracleClosedForSingleChoiceAndTrueFalse(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('hash_oracle');
        /** @var QuestionPublicContentHashBuilder $builder */
        $builder = static::getContainer()->get(QuestionPublicContentHashBuilder::class);
        /** @var QuestionContentHasher $hasher */
        $hasher = static::getContainer()->get(QuestionContentHasher::class);

        $stem = QuestionContentDocument::paragraph('Oracle stem 2+2?');
        $options = [
            ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Three'), 'position' => 1],
            ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Four'), 'position' => 2],
        ];
        $alignments = [['learningOutcome' => $lo, 'isPrimary' => true]];

        $qA = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            $stem,
            null,
            $options,
            ['correctStableKey' => 'opt_a'],
            $alignments,
            QuestionDifficulty::Easy,
            'oracle_sc_a',
        );
        $qB = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            $stem,
            null,
            $options,
            ['correctStableKey' => 'opt_b'],
            $alignments,
            QuestionDifficulty::Easy,
            'oracle_sc_b',
        );

        $revA = $this->revisionFor($qA, 1);
        $revB = $this->revisionFor($qB, 1);
        self::assertSame($revA->getContentHash(), $revB->getContentHash());

        $publicOptions = [
            ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Three')->toArray(), 'position' => 1],
            ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Four')->toArray(), 'position' => 2],
        ];
        $publicAlignments = [[
            'learningOutcomeId' => $lo->getId()->toRfc4122(),
            'isPrimary' => true,
        ]];
        $publicPayload = $builder->build(
            QuestionType::SingleChoice->value,
            $stem->toArray(),
            null,
            $publicOptions,
            QuestionDifficulty::Easy->value,
            null,
            QuestionSourceType::Original->value,
            null,
            $publicAlignments,
            QuestionContentDocument::SCHEMA_VERSION,
        );
        $publicHash = $hasher->hash($publicPayload);
        self::assertSame($publicHash, $revA->getContentHash());

        $candidateHashes = [];
        foreach (['opt_a', 'opt_b'] as $candidate) {
            $leaky = $publicPayload;
            $leaky['correctStableKey'] = $candidate;
            $candidateHashes[$candidate] = $hasher->hash($leaky);
        }
        self::assertNotSame($candidateHashes['opt_a'], $candidateHashes['opt_b']);
        self::assertNotSame($publicHash, $candidateHashes['opt_a']);
        self::assertNotSame($publicHash, $candidateHashes['opt_b']);
        // Matching public hash does not uniquely identify the correct answer.
        self::assertSame($revA->getContentHash(), $revB->getContentHash());

        $tfTrue = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('Water is polar?'),
            null,
            [],
            ['correct' => true],
            $alignments,
            QuestionDifficulty::Medium,
            'oracle_tf_t',
        );
        $tfFalse = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('Water is polar?'),
            null,
            [],
            ['correct' => false],
            $alignments,
            QuestionDifficulty::Medium,
            'oracle_tf_f',
        );
        $tfRevTrue = $this->revisionFor($tfTrue, 1);
        $tfRevFalse = $this->revisionFor($tfFalse, 1);
        self::assertSame($tfRevTrue->getContentHash(), $tfRevFalse->getContentHash());

        $tfPublic = $builder->build(
            QuestionType::TrueFalse->value,
            QuestionContentDocument::paragraph('Water is polar?')->toArray(),
            null,
            [],
            QuestionDifficulty::Medium->value,
            null,
            QuestionSourceType::Original->value,
            null,
            $publicAlignments,
            QuestionContentDocument::SCHEMA_VERSION,
        );
        $tfPublicHash = $hasher->hash($tfPublic);
        self::assertSame($tfPublicHash, $tfRevTrue->getContentHash());
        $leakyTrue = $tfPublic + ['correct' => true];
        $leakyFalse = $tfPublic + ['correct' => false];
        self::assertNotSame($hasher->hash($leakyTrue), $hasher->hash($leakyFalse));
        self::assertNotSame($tfPublicHash, $hasher->hash($leakyTrue));
    }

    public function testSerializerKeyTreeOmitsAnswerSecrets(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('ser_keys');
        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Serialize me?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'ser_create',
        );
        $revision = $this->revisionFor($question, 1);
        /** @var QuestionAnswerKeyRepository $keys */
        $keys = static::getContainer()->get(QuestionAnswerKeyRepository::class);
        $answerKey = $keys->findOneByRevision($revision);
        self::assertInstanceOf(QuestionAnswerKey::class, $answerKey);

        /** @var SerializerInterface $serializer */
        $serializer = static::getContainer()->get(SerializerInterface::class);
        foreach ([$question, $revision, $answerKey] as $entity) {
            $decoded = json_decode($serializer->serialize($entity, 'json'), true, 512, \JSON_THROW_ON_ERROR);
            $keyTree = JsonKeyTree::collectKeys($decoded);
            foreach (self::FORBIDDEN_SERIALIZER_KEYS as $forbidden) {
                self::assertNotContains($forbidden, $keyTree);
            }
        }

        $revDecoded = json_decode($serializer->serialize($revision, 'json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('contentHash', $revDecoded);
        self::assertSame($revision->getContentHash(), $revDecoded['contentHash']);

        $keyDecoded = json_decode($serializer->serialize($answerKey, 'json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], JsonKeyTree::collectKeys($keyDecoded));
    }

    public function testRealDbalAppendOnlyTriggersBlockUpdateAndDelete(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('append_only');
        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Immutable?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_a'],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'append_create',
        );
        $revision = $this->revisionFor($question, 1);
        $originalHash = $revision->getContentHash();
        $connection = $this->em->getConnection();

        try {
            $connection->executeStatement(
                'UPDATE question_revisions SET content_hash = :h WHERE id = :id',
                ['h' => str_repeat('f', 64), 'id' => $revision->getId()->toBinary()],
            );
            self::fail('revision UPDATE must throw');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }
        $hashAfter = $connection->fetchOne(
            'SELECT content_hash FROM question_revisions WHERE id = :id',
            ['id' => $revision->getId()->toBinary()],
        );
        self::assertSame($originalHash, $hashAfter);

        try {
            $connection->executeStatement(
                'DELETE FROM question_revisions WHERE id = :id',
                ['id' => $revision->getId()->toBinary()],
            );
            self::fail('revision DELETE must throw');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revisions WHERE id = :id',
            ['id' => $revision->getId()->toBinary()],
        ));

        /** @var QuestionRevisionOptionRepository $optionRepo */
        $optionRepo = static::getContainer()->get(QuestionRevisionOptionRepository::class);
        $options = $optionRepo->findByRevision($revision);
        self::assertNotEmpty($options);
        $option = $options[0];
        self::assertInstanceOf(QuestionRevisionOption::class, $option);
        $this->assertAppendOnlyBlocked($connection, 'question_revision_options', $option->getId()->toBinary());

        /** @var QuestionAnswerKeyRepository $keyRepo */
        $keyRepo = static::getContainer()->get(QuestionAnswerKeyRepository::class);
        $key = $keyRepo->findOneByRevision($revision);
        self::assertInstanceOf(QuestionAnswerKey::class, $key);
        $this->assertAppendOnlyBlocked($connection, 'question_answer_keys', $key->getId()->toBinary(), 'answer_type');

        /** @var QuestionRevisionAlignmentRepository $alignmentRepo */
        $alignmentRepo = static::getContainer()->get(QuestionRevisionAlignmentRepository::class);
        $alignments = $alignmentRepo->findByRevision($revision);
        self::assertNotEmpty($alignments);
        $this->assertAppendOnlyBlocked($connection, 'question_revision_alignments', $alignments[0]->getId()->toBinary(), 'is_primary');

        // ORM listener still blocks in-process mutation (domain coverage remains).
        $listener = new \App\Doctrine\QuestionRevisionImmutabilityListener();
        $changeSet = ['contentHash' => [$originalHash, str_repeat('e', 64)]];
        try {
            $listener->preUpdate(new \Doctrine\ORM\Event\PreUpdateEventArgs($revision, $this->em, $changeSet));
            self::fail('ORM immutability listener');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::Immutable, $e->getReason());
        }
    }

    public function testPrimaryAlignmentDatabaseGuardsAndSchema(): void
    {
        $connection = $this->em->getConnection();

        $extra = $connection->fetchOne(
            "SELECT EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'question_revision_alignments'
               AND COLUMN_NAME = 'primary_revision_scope_id'",
        );
        self::assertIsString($extra);
        self::assertStringContainsString('STORED GENERATED', strtoupper($extra));

        $indexes = $connection->createSchemaManager()->listTableIndexes('question_revision_alignments');
        self::assertArrayHasKey('uniq_qra_one_primary_per_revision', $indexes);

        $triggers = $connection->fetchFirstColumn(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME IN (
                 'trg_question_revisions_bu', 'trg_question_revisions_bd',
                 'trg_question_revision_options_bu', 'trg_question_revision_options_bd',
                 'trg_question_answer_keys_bu', 'trg_question_answer_keys_bd',
                 'trg_question_revision_alignments_bu', 'trg_question_revision_alignments_bd'
               )",
        );
        self::assertContains('trg_question_revisions_bu', $triggers);
        self::assertContains('trg_question_revisions_bd', $triggers);
        self::assertContains('trg_question_revision_options_bu', $triggers);
        self::assertContains('trg_question_revision_options_bd', $triggers);
        self::assertContains('trg_question_answer_keys_bu', $triggers);
        self::assertContains('trg_question_answer_keys_bd', $triggers);
        self::assertContains('trg_question_revision_alignments_bu', $triggers);
        self::assertContains('trg_question_revision_alignments_bd', $triggers);

        [$sa, , $subject, $program, $loPrimary] = $this->platformCurriculum('prim_db');
        $topic = $loPrimary->getTopic();
        $loSecondary = $this->outcomes()->create($topic, $sa, 'lo_prim_db_2', 'Secondary', 2, 'create_lo2');
        $loTertiary = $this->outcomes()->create($topic, $sa, 'lo_prim_db_3', 'Tertiary', 3, 'create_lo3');

        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('Primary DB?'),
            null,
            [],
            ['correct' => true],
            [['learningOutcome' => $loPrimary, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'prim_create',
        );
        $revision = $this->revisionFor($question, 1);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $connection->insert('question_revision_alignments', [
                'id' => (new UuidV7())->toBinary(),
                'revision_id' => $revision->getId()->toBinary(),
                'curriculum_program_id' => $program->getId()->toBinary(),
                'subject_id' => $subject->getId()->toBinary(),
                'curriculum_topic_id' => $topic->getId()->toBinary(),
                'learning_outcome_id' => $loSecondary->getId()->toBinary(),
                'is_primary' => 1,
                'created_at' => $now,
            ]);
            self::fail('second primary alignment must be rejected');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $nonPrimaryId = (new UuidV7())->toBinary();
        $connection->insert('question_revision_alignments', [
            'id' => $nonPrimaryId,
            'revision_id' => $revision->getId()->toBinary(),
            'curriculum_program_id' => $program->getId()->toBinary(),
            'subject_id' => $subject->getId()->toBinary(),
            'curriculum_topic_id' => $topic->getId()->toBinary(),
            'learning_outcome_id' => $loSecondary->getId()->toBinary(),
            'is_primary' => 0,
            'created_at' => $now,
        ]);
        $connection->insert('question_revision_alignments', [
            'id' => (new UuidV7())->toBinary(),
            'revision_id' => $revision->getId()->toBinary(),
            'curriculum_program_id' => $program->getId()->toBinary(),
            'subject_id' => $subject->getId()->toBinary(),
            'curriculum_topic_id' => $topic->getId()->toBinary(),
            'learning_outcome_id' => $loTertiary->getId()->toBinary(),
            'is_primary' => 0,
            'created_at' => $now,
        ]);

        // Guards have no DELETE trigger; remove the real primary guard so we can probe FK rules.
        $connection->executeStatement(
            'DELETE FROM question_revision_primary_alignment_guards WHERE revision_id = :id',
            ['id' => $revision->getId()->toBinary()],
        );

        try {
            $connection->insert('question_revision_primary_alignment_guards', [
                'revision_id' => $revision->getId()->toBinary(),
                'alignment_id' => $nonPrimaryId,
                'must_be_primary' => 1,
            ]);
            self::fail('guard pointing at non-primary must be rejected');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Different revisions' primaries do not conflict.
        $other = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('Other primary?'),
            null,
            [],
            ['correct' => false],
            [['learningOutcome' => $loPrimary, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'prim_other',
        );
        self::assertSame(1, $other->getCurrentRevisionNumber());
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revision_alignments WHERE is_primary = 1',
        ));
    }

    public function testPublishRejectsStaleManagedPublishedCurriculumProgram(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('stale_curr');
        $this->programs()->publish($program, $sa, 'pub_curr');
        self::assertSame(CurriculumStatus::Published, $program->getStatus());

        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('Stale curriculum publish?'),
            null,
            [],
            ['correct' => true],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'stale_create',
        );
        $this->questions()->submitForReview($question, $sa, 'stale_submit');
        $question = $this->reloadQuestion($question->getId());
        $program = $this->em->find(CurriculumProgram::class, $program->getId());
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(CurriculumProgram::class, $program);
        self::assertInstanceOf(User::class, $reviewer);
        self::assertSame(CurriculumStatus::Published, $program->getStatus());
        self::assertTrue($this->em->contains($program));

        $this->em->getConnection()->executeStatement(
            "UPDATE curriculum_programs SET status = 'retired' WHERE id = :id",
            ['id' => $program->getId()->toBinary()],
        );
        self::assertSame(CurriculumStatus::Published, $program->getStatus(), 'Precondition: managed still published');

        try {
            $this->questions()->publish($question, $reviewer, 'stale_publish');
            self::fail('publish must reject retired curriculum from DB');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::CurriculumNotPublished, $e->getReason());
        }
        $question = $this->reloadQuestion($question->getId());
        self::assertSame(QuestionStatus::InReview, $question->getStatus());
    }

    public function testPublishRejectsWhenReviewerRolesStrippedInDatabase(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('stale_role');
        $this->programs()->publish($program, $sa, 'pub_curr');

        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('Stale reviewer roles?'),
            null,
            [],
            ['correct' => true],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'role_create',
        );
        $this->questions()->submitForReview($question, $sa, 'role_submit');
        $question = $this->reloadQuestion($question->getId());
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);
        self::assertContains(UserRole::HeadTeacher->value, $reviewer->getRoles());
        self::assertTrue($this->em->contains($reviewer));

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET global_roles = :roles WHERE id = :id',
            [
                'roles' => json_encode([UserRole::Student->value], \JSON_THROW_ON_ERROR),
                'id' => $reviewer->getId()->toBinary(),
            ],
        );
        self::assertContains(UserRole::HeadTeacher->value, $reviewer->getRoles(), 'Precondition: managed still head teacher');

        try {
            $this->questions()->publish($question, $reviewer, 'role_publish');
            self::fail('publish must authorize from refreshed DB roles');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::Unauthorized, $e->getReason());
        }
        $question = $this->reloadQuestion($question->getId());
        self::assertSame(QuestionStatus::InReview, $question->getStatus());
    }

    public function testExceptionAndAuditMetadataDoNotLeakSecrets(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('leak');
        $secret = 'SECRET_ANSWER_XYZ';

        try {
            $this->questions()->createDraftQuestion(
                $sa,
                QuestionScope::Platform,
                null,
                $subject,
                GradeLevel::Grade9,
                QuestionType::SingleChoice,
                QuestionContentDocument::paragraph('Leak probe?'),
                null,
                [
                    ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                    ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
                ],
                ['correctStableKey' => $secret],
                [['learningOutcome' => $lo, 'isPrimary' => true]],
                QuestionDifficulty::Easy,
                'leak_bad_answer',
            );
            self::fail('invalid answer must throw');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::AnswerInvalid, $e->getReason());
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        $subject = $this->em->find(Subject::class, $subject->getId());
        $lo = $this->em->find(CurriculumLearningOutcome::class, $lo->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(Subject::class, $subject);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $lo);

        $stemText = 'Audit stem must not leak';
        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph($stemText),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_a'],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'leak_ok_create',
        );

        $event = $this->em->getRepository(SecurityAuditEvent::class)->findOneBy(
            ['action' => SecurityAuditAction::QuestionCreated],
            ['occurredAt' => 'DESC'],
        );
        self::assertInstanceOf(SecurityAuditEvent::class, $event);
        $metadata = $event->getMetadata();
        $encoded = json_encode($metadata, \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('content_hash', $metadata);
        self::assertArrayNotHasKey('contentHash', $metadata);
        self::assertStringNotContainsString('content_hash', $encoded);
        self::assertStringNotContainsString($stemText, $encoded);
        self::assertStringNotContainsString('correctStableKey', $encoded);
        self::assertStringNotContainsString('opt_a', $encoded);
        self::assertSame($question->getId()->toRfc4122(), $metadata['question_id'] ?? null);
    }

    public function testDeleteTriggersRejectEvenWithBypassSessionVariable(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('bypass_reject');
        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Bypass must not help?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_a'],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'bypass_create',
        );
        $revision = $this->revisionFor($question, 1);
        $connection = $this->em->getConnection();

        /** @var QuestionRevisionOptionRepository $optionRepo */
        $optionRepo = static::getContainer()->get(QuestionRevisionOptionRepository::class);
        $options = $optionRepo->findByRevision($revision);
        self::assertNotEmpty($options);
        self::assertInstanceOf(QuestionRevisionOption::class, $options[0]);

        /** @var QuestionAnswerKeyRepository $keyRepo */
        $keyRepo = static::getContainer()->get(QuestionAnswerKeyRepository::class);
        $key = $keyRepo->findOneByRevision($revision);
        self::assertInstanceOf(QuestionAnswerKey::class, $key);
        $originalHmac = $key->getAnswerIntegrityHmac();

        /** @var QuestionRevisionAlignmentRepository $alignmentRepo */
        $alignmentRepo = static::getContainer()->get(QuestionRevisionAlignmentRepository::class);
        $alignments = $alignmentRepo->findByRevision($revision);
        self::assertNotEmpty($alignments);

        $connection->executeStatement('SET @testlig_immutable_delete_bypass = 1');
        $connection->executeStatement('SET @testlig_immutable_delete_bypass = TRUE');
        $connection->executeStatement('SET @fake_immutable_bypass = 1');
        $connection->executeStatement('SET @testlig_immutable = 1');

        foreach ([
            'question_revisions' => $revision->getId()->toBinary(),
            'question_revision_options' => $options[0]->getId()->toBinary(),
            'question_answer_keys' => $key->getId()->toBinary(),
            'question_revision_alignments' => $alignments[0]->getId()->toBinary(),
        ] as $table => $idBinary) {
            try {
                $connection->executeStatement(
                    "DELETE FROM {$table} WHERE id = :id",
                    ['id' => $idBinary],
                );
                self::fail($table.' DELETE must still throw with bypass session vars');
            } catch (\Throwable $e) {
                self::assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
            }
            self::assertSame(1, (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM {$table} WHERE id = :id",
                ['id' => $idBinary],
            ));
        }

        try {
            $connection->executeStatement(
                'UPDATE question_revisions SET content_hash = :h WHERE id = :id',
                ['h' => str_repeat('a', 64), 'id' => $revision->getId()->toBinary()],
            );
            self::fail('revision UPDATE must still throw');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }
        try {
            $connection->executeStatement(
                'UPDATE question_answer_keys SET answer_integrity_hmac = :h WHERE id = :id',
                ['h' => str_repeat('b', 64), 'id' => $key->getId()->toBinary()],
            );
            self::fail('answer key UPDATE must still throw');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }

        self::assertSame($originalHmac, $connection->fetchOne(
            'SELECT answer_integrity_hmac FROM question_answer_keys WHERE id = :id',
            ['id' => $key->getId()->toBinary()],
        ));
        self::assertSame($revision->getContentHash(), $connection->fetchOne(
            'SELECT content_hash FROM question_revisions WHERE id = :id',
            ['id' => $revision->getId()->toBinary()],
        ));

        $this->assertDeleteTriggersBypassFree($connection);
    }

    public function testParentQuestionCascadeDeletesImmutableChildrenWithoutBypass(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('cascade_parent');
        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Cascade wipe?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_a'],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'cascade_create',
        );
        $revision = $this->revisionFor($question, 1);
        $questionId = $question->getId()->toBinary();
        $revisionId = $revision->getId()->toBinary();
        $connection = $this->em->getConnection();

        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revisions WHERE question_id = :id',
            ['id' => $questionId],
        ));
        self::assertGreaterThan(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revision_options WHERE revision_id = :id',
            ['id' => $revisionId],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_answer_keys WHERE revision_id = :id',
            ['id' => $revisionId],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revision_alignments WHERE revision_id = :id',
            ['id' => $revisionId],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revision_primary_alignment_guards WHERE revision_id = :id',
            ['id' => $revisionId],
        ));

        $connection->executeStatement('DELETE FROM questions WHERE id = :id', ['id' => $questionId]);

        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revisions WHERE question_id = :id',
            ['id' => $questionId],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revision_options WHERE revision_id = :id',
            ['id' => $revisionId],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_answer_keys WHERE revision_id = :id',
            ['id' => $revisionId],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revision_alignments WHERE revision_id = :id',
            ['id' => $revisionId],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_revision_primary_alignment_guards WHERE revision_id = :id',
            ['id' => $revisionId],
        ));

        $this->assertDeleteTriggersBypassFree($connection);
    }

    public function testPublishVerifiesAnswerIntegrityHmac(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('hmac_pub');
        $this->programs()->publish($program, $sa, 'pub_curr');

        $valid = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('Valid HMAC publish?'),
            null,
            [],
            ['correct' => true],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'hmac_valid_create',
        );
        $this->questions()->submitForReview($valid, $sa, 'hmac_valid_submit');
        $valid = $this->reloadQuestion($valid->getId());
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);

        $connection = $this->em->getConnection();
        $publishedBefore = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = :a',
            ['a' => SecurityAuditAction::QuestionPublished->value],
        );

        $this->questions()->publish($valid, $reviewer, 'hmac_valid_publish');
        $valid = $this->reloadQuestion($valid->getId());
        self::assertSame(QuestionStatus::Published, $valid->getStatus());
        self::assertSame($publishedBefore + 1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = :a',
            ['a' => SecurityAuditAction::QuestionPublished->value],
        ));

        $sa = $this->users->find($sa->getId());
        $reviewer = $this->users->find($reviewer->getId());
        $subject = $this->em->find(Subject::class, $subject->getId());
        $program = $this->em->find(CurriculumProgram::class, $program->getId());
        $lo = $this->em->find(CurriculumLearningOutcome::class, $lo->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(User::class, $reviewer);
        self::assertInstanceOf(Subject::class, $subject);
        self::assertInstanceOf(CurriculumProgram::class, $program);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $lo);

        $badHmac = str_repeat('ab', 32); // 64 lowercase hex, cryptographically wrong
        $tamperedId = $this->insertTrueFalseQuestionGraphViaDbal(
            $sa,
            $subject,
            $program,
            $lo,
            QuestionStatus::InReview,
            $badHmac,
            ['correct' => false],
            'Tampered HMAC publish?',
        );
        $tampered = $this->reloadQuestion($tamperedId);
        $publishedBeforeTamper = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = :a',
            ['a' => SecurityAuditAction::QuestionPublished->value],
        );

        try {
            $this->questions()->publish($tampered, $reviewer, 'hmac_tamper_publish');
            self::fail('publish must reject wrong HMAC');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::AnswerIntegrityFailed, $e->getReason());
            self::assertStringNotContainsStringIgnoringCase('hmac', $e->getMessage());
            self::assertStringNotContainsStringIgnoringCase('payload', $e->getMessage());
            self::assertStringNotContainsStringIgnoringCase('key', $e->getMessage());
            self::assertStringNotContainsString($badHmac, $e->getMessage());
        }

        $tampered = $this->reloadQuestion($tamperedId);
        self::assertSame(QuestionStatus::InReview, $tampered->getStatus());
        self::assertSame($publishedBeforeTamper, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = :a',
            ['a' => SecurityAuditAction::QuestionPublished->value],
        ));
    }

    public function testPublishRejectsHmacBoundToWrongRevisionOrAnswerType(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('hmac_bind');
        $this->programs()->publish($program, $sa, 'pub_curr');
        /** @var QuestionAnswerIntegrityHasher $hasher */
        $hasher = static::getContainer()->get(QuestionAnswerIntegrityHasher::class);

        $payload = ['correct' => true];
        $wrongRevisionHmac = $hasher->hash($payload, QuestionType::TrueFalse, new UuidV7());
        $wrongRevisionId = $this->insertTrueFalseQuestionGraphViaDbal(
            $sa,
            $subject,
            $program,
            $lo,
            QuestionStatus::InReview,
            $wrongRevisionHmac,
            $payload,
            'Wrong revision UUID HMAC?',
        );

        $sa = $this->users->find($sa->getId());
        $reviewer = $this->users->find($reviewer->getId());
        $subject = $this->em->find(Subject::class, $subject->getId());
        $program = $this->em->find(CurriculumProgram::class, $program->getId());
        $lo = $this->em->find(CurriculumLearningOutcome::class, $lo->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(User::class, $reviewer);
        self::assertInstanceOf(Subject::class, $subject);
        self::assertInstanceOf(CurriculumProgram::class, $program);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $lo);

        // HMAC bound to SingleChoice while row stores true_false (type matches revision; verify fails).
        $revisionIdForType = new UuidV7();
        $wrongTypeBoundHmac = $hasher->hash($payload, QuestionType::SingleChoice, $revisionIdForType);
        $wrongTypeQuestionId = $this->insertTrueFalseQuestionGraphViaDbal(
            $sa,
            $subject,
            $program,
            $lo,
            QuestionStatus::InReview,
            $wrongTypeBoundHmac,
            $payload,
            'Wrong answer type HMAC?',
            $revisionIdForType,
        );

        foreach ([$wrongRevisionId, $wrongTypeQuestionId] as $questionId) {
            $question = $this->reloadQuestion($questionId);
            $reviewer = $this->users->find($reviewer->getId());
            self::assertInstanceOf(User::class, $reviewer);
            try {
                $this->questions()->publish($question, $reviewer, 'hmac_bind_publish');
                self::fail('publish must reject HMAC bound to wrong inputs');
            } catch (QuestionException $e) {
                self::assertSame(QuestionFailureReason::AnswerIntegrityFailed, $e->getReason());
            }
            $question = $this->reloadQuestion($questionId);
            self::assertSame(QuestionStatus::InReview, $question->getStatus());
        }
    }

    public function testAnswerIntegrityHmacCheckConstraint(): void
    {
        [$sa, , $subject, $program, $lo] = $this->platformCurriculum('hmac_chk');
        $connection = $this->em->getConnection();
        $revisionId = new UuidV7();
        $this->insertTrueFalseQuestionGraphViaDbal(
            $sa,
            $subject,
            $program,
            $lo,
            QuestionStatus::Draft,
            str_repeat('cd', 32),
            ['correct' => true],
            'CHECK constraint host?',
            $revisionId,
            withAnswerKey: false,
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $base = [
            'revision_id' => $revisionId->toBinary(),
            'answer_type' => QuestionType::TrueFalse->value,
            'answer_payload' => json_encode(['correct' => true], \JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ];

        foreach ([
            '' => 'empty',
            str_repeat('a', 63) => 'too short',
            str_repeat('a', 65) => 'too long',
            str_repeat('g', 64) => 'non-hex',
            strtoupper(str_repeat('ab', 32)) => 'uppercase',
        ] as $hmac => $label) {
            try {
                $connection->insert('question_answer_keys', $base + [
                    'id' => (new UuidV7())->toBinary(),
                    'answer_integrity_hmac' => $hmac,
                ]);
                self::fail('CHECK must reject '.$label.' HMAC');
            } catch (\Throwable $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        $connection->insert('question_answer_keys', $base + [
            'id' => (new UuidV7())->toBinary(),
            'answer_integrity_hmac' => str_repeat('ef', 32),
        ]);
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM question_answer_keys WHERE revision_id = :id',
            ['id' => $revisionId->toBinary()],
        ));
    }

    private function assertDeleteTriggersBypassFree(\Doctrine\DBAL\Connection $connection): void
    {
        $statements = $connection->fetchFirstColumn(
            "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME IN (
                 'trg_question_revisions_bd',
                 'trg_question_revision_options_bd',
                 'trg_question_answer_keys_bd',
                 'trg_question_revision_alignments_bd'
               )",
        );
        self::assertCount(4, $statements);
        foreach ($statements as $statement) {
            self::assertIsString($statement);
            self::assertStringNotContainsStringIgnoringCase('bypass', $statement);
            self::assertStringNotContainsStringIgnoringCase('testlig_immutable', $statement);
        }
    }

    /**
     * Inserts a platform TrueFalse question graph via DBAL (not QuestionManager) so the
     * answer_integrity_hmac can be fabricated without going through UPDATE (append-only).
     *
     * @param array{correct: bool} $answerPayload
     */
    private function insertTrueFalseQuestionGraphViaDbal(
        User $createdBy,
        Subject $subject,
        CurriculumProgram $program,
        CurriculumLearningOutcome $lo,
        QuestionStatus $status,
        string $answerIntegrityHmac,
        array $answerPayload,
        string $stemText,
        ?Uuid $revisionId = null,
        bool $withAnswerKey = true,
    ): Uuid {
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $questionId = new UuidV7();
        $revisionId ??= new UuidV7();
        $alignmentId = new UuidV7();
        $topic = $lo->getTopic();
        $stem = QuestionContentDocument::paragraph($stemText)->toArray();
        $contentHash = str_repeat('11', 32);

        $connection->insert('questions', [
            'id' => $questionId->toBinary(),
            'scope' => QuestionScope::Platform->value,
            'institution_id' => null,
            'subject_id' => $subject->getId()->toBinary(),
            'grade_level' => GradeLevel::Grade9->value,
            'created_by_id' => $createdBy->getId()->toBinary(),
            'status' => $status->value,
            'current_revision_number' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('question_revisions', [
            'id' => $revisionId->toBinary(),
            'question_id' => $questionId->toBinary(),
            'revision_number' => 1,
            'type' => QuestionType::TrueFalse->value,
            'stem_content' => json_encode($stem, \JSON_THROW_ON_ERROR),
            'explanation_content' => null,
            'difficulty' => QuestionDifficulty::Easy->value,
            'estimated_seconds' => null,
            'source_type' => QuestionSourceType::Original->value,
            'source_reference' => null,
            'created_by_id' => $createdBy->getId()->toBinary(),
            'created_at' => $now,
            'content_hash' => $contentHash,
            'schema_version' => QuestionContentDocument::SCHEMA_VERSION,
        ]);
        $connection->insert('question_revision_alignments', [
            'id' => $alignmentId->toBinary(),
            'revision_id' => $revisionId->toBinary(),
            'curriculum_program_id' => $program->getId()->toBinary(),
            'subject_id' => $subject->getId()->toBinary(),
            'curriculum_topic_id' => $topic->getId()->toBinary(),
            'learning_outcome_id' => $lo->getId()->toBinary(),
            'is_primary' => 1,
            'created_at' => $now,
        ]);
        $connection->insert('question_revision_primary_alignment_guards', [
            'revision_id' => $revisionId->toBinary(),
            'alignment_id' => $alignmentId->toBinary(),
            'must_be_primary' => 1,
        ]);

        if ($withAnswerKey) {
            $connection->insert('question_answer_keys', [
                'id' => (new UuidV7())->toBinary(),
                'revision_id' => $revisionId->toBinary(),
                'answer_type' => QuestionType::TrueFalse->value,
                'answer_payload' => json_encode($answerPayload, \JSON_THROW_ON_ERROR),
                'answer_integrity_hmac' => $answerIntegrityHmac,
                'created_at' => $now,
            ]);
        }

        return $questionId;
    }

    private function assertAppendOnlyBlocked(
        \Doctrine\DBAL\Connection $connection,
        string $table,
        string $idBinary,
        string $updateColumn = 'id',
    ): void {
        try {
            if ('is_primary' === $updateColumn) {
                $connection->executeStatement(
                    "UPDATE {$table} SET is_primary = 0 WHERE id = :id",
                    ['id' => $idBinary],
                );
            } elseif ('answer_type' === $updateColumn) {
                $connection->executeStatement(
                    "UPDATE {$table} SET answer_type = :t WHERE id = :id",
                    ['t' => QuestionType::TrueFalse->value, 'id' => $idBinary],
                );
            } else {
                $connection->executeStatement(
                    "UPDATE {$table} SET id = id WHERE id = :id",
                    ['id' => $idBinary],
                );
            }
            self::fail($table.' UPDATE must throw');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }

        try {
            $connection->executeStatement(
                "DELETE FROM {$table} WHERE id = :id",
                ['id' => $idBinary],
            );
            self::fail($table.' DELETE must throw');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }

        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE id = :id",
            ['id' => $idBinary],
        ));
    }

    private function revisionFor(Question $question, int $number): QuestionRevision
    {
        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, $number);
        self::assertInstanceOf(QuestionRevision::class, $revision);

        return $revision;
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

    private function reloadQuestion(Uuid $id): Question
    {
        $this->em->clear();
        $this->rebind();
        $q = $this->em->find(Question::class, $id);
        self::assertInstanceOf(Question::class, $q);

        return $q;
    }

    private function resetDoctrine(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebind();
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

    private function questions(): QuestionManager
    {
        $s = static::getContainer()->get(QuestionManager::class);
        self::assertInstanceOf(QuestionManager::class, $s);

        return $s;
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

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'question_revision_primary_alignment_guards',
            'question_revision_alignments',
            'question_answer_keys',
            'question_revision_options',
            'question_revisions',
            'questions',
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'institution_memberships',
            'institutions',
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
