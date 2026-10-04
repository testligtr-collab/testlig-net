<?php

declare(strict_types=1);

namespace App\Tests\Service\LearningContent;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\LearningContentPackageException;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use App\Service\CatalogTopicLessonManager;
use App\Service\CatalogWriteService;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContent\LearningContentPackageImportService;
use App\Service\LearningContent\LearningContentPackageTarget;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LearningContentPackageImportMat133ServiceTest extends KernelTestCase
{
    private const PACKAGE = 'data/content/tymm-2026/grade-1/matematik/mat-1-3-3';

    private EntityManagerInterface $entityManager;
    private LearningContentPackageImportService $importer;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $importer = static::getContainer()->get(LearningContentPackageImportService::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(LearningContentPackageImportService::class, $importer);
        $this->entityManager = $entityManager;
        $this->importer = $importer;
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testDryRunWritesNothingAndApplyIsDeterministic(): void
    {
        $teacher = $this->teacher('pkg133-teacher@example.com');
        $outcome = $this->ensureOutcome();
        $content = $this->placeholder($teacher, $outcome, LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $before = $this->evidence();
        $revision = $content->getCurrentRevision();
        self::assertInstanceOf(LearningContentRevision::class, $revision);
        $beforeBody = $revision->getStructuredContent();

        $first = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        $second = $this->importer->execute(self::PACKAGE, 'verify', null, $teacher);
        self::assertSame($first->planFingerprint, $second->planFingerprint);
        self::assertSame(64, \strlen($first->planFingerprint));
        self::assertSame(1, $first->packageFound);
        self::assertSame(1, $first->subjectFound);
        self::assertSame(1, $first->outcomeFound);
        self::assertSame(1, $first->contentFound);
        self::assertSame(1, $first->revisionFound);
        self::assertSame(1, $first->placeholderRevision);
        self::assertSame(9, $first->blocksExpected);
        self::assertSame(1, $first->blocksCurrent);
        self::assertSame(9, $first->blocksToReplace);
        self::assertSame(0, $first->conflicts);
        self::assertSame(1, $first->applyReady);
        self::assertSame(0, $first->applied);
        self::assertSame(0, $first->questionsTouched);
        self::assertSame(0, $first->assessmentsTouched);
        self::assertSame(0, $first->placementsTouched);
        self::assertSame(0, $first->usersTouched);
        self::assertSame([], $first->conflictReasons);
        self::assertSame(1, $first->ownerMatch);
        self::assertSame(1, $first->contentStatusMatch);
        self::assertSame(1, $first->revisionStatusMatch);
        self::assertSame(1, $first->subjectMatch);
        self::assertSame(1, $first->gradeMatch);
        self::assertSame(1, $first->outcomeMatch);
        self::assertSame(1, $first->stableCodeMatch);
        self::assertSame(1, $first->contentTypeMatch);
        self::assertSame(1, $first->titleMatch);
        self::assertSame(1, $first->summaryMatch);
        self::assertSame(1, $first->placementAbsent);
        $this->entityManager->refresh($revision);
        self::assertSame($beforeBody, $revision->getStructuredContent());

        $applied = $this->importer->execute(self::PACKAGE, 'apply', $first->planFingerprint, $teacher);
        self::assertSame(1, $applied->applied);
        self::assertSame(0, $applied->noop);
        self::assertSame('replace_placeholder', $applied->operation);
        $this->entityManager->refresh($revision);
        self::assertSame(9, \count($revision->getStructuredContent()['blocks'] ?? []));
        self::assertNotSame($beforeBody, $revision->getStructuredContent());

        $noopPlan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertNotSame($first->planFingerprint, $noopPlan->planFingerprint);
        self::assertSame([], $noopPlan->conflictReasons);
        self::assertSame(1, $noopPlan->noop);
        $noop = $this->importer->execute(self::PACKAGE, 'apply', $noopPlan->planFingerprint, $teacher);
        self::assertSame(0, $noop->applied);
        self::assertSame(1, $noop->noop);
        self::assertSame('noop', $noop->operation);
        $this->entityManager->refresh($revision);
        self::assertSame(9, \count($revision->getStructuredContent()['blocks'] ?? []));
        self::assertSame($before, $this->evidence());
        $this->assertPackageAudit('replace_placeholder');
        $this->assertPackageAudit('noop');
    }

    public function testApplyWithoutFingerprintDoesNotWrite(): void
    {
        $teacher = $this->teacher('pkg133-nofp@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        try {
            $this->importer->execute(self::PACKAGE, 'apply', null, $teacher);
            self::fail('missing fingerprint');
        } catch (LearningContentPackageException $exception) {
            self::assertSame('Apply requires the plan fingerprint.', $exception->getMessage());
        }
        $this->assertStillPlaceholder($content);
    }

    public function testStaleFingerprintRollsBack(): void
    {
        $teacher = $this->teacher('pkg133-stale@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        try {
            $this->importer->execute(self::PACKAGE, 'apply', str_repeat('a', 64), $teacher);
            self::fail('stale fingerprint');
        } catch (LearningContentPackageException $exception) {
            self::assertSame('Plan fingerprint is stale.', $exception->getMessage());
        }
        $this->assertStillPlaceholder($content);
    }

    public function testOtherAuthorConflictsAndDoesNotWrite(): void
    {
        $owner = $this->teacher('pkg133-owner@example.com');
        $other = $this->teacher('pkg133-other@example.com');
        $content = $this->placeholder($owner, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $other);
        self::assertSame(['actor_not_owner'], $plan->conflictReasons);
        self::assertSame(0, $plan->ownerMatch);
        self::assertSame(0, $plan->applyReady);
        try {
            $this->importer->execute(self::PACKAGE, 'apply', $plan->planFingerprint, $other);
            self::fail('other author');
        } catch (LearningContentPackageException $exception) {
            self::assertSame('Package import conflict.', $exception->getMessage());
        }
        $this->assertStillPlaceholder($content);
    }

    public function testSealedRevisionConflicts(): void
    {
        $teacher = $this->teacher('pkg133-sealed@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $this->contents()->submitForReview($content, $teacher, 'submit_pkg');
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertContains('revision_not_draft', $plan->conflictReasons);
        self::assertContains('content_not_draft', $plan->conflictReasons);
        self::assertSame(0, $plan->revisionStatusMatch);
        self::assertSame(0, $plan->contentStatusMatch);
        $this->entityManager->refresh($content);
        $revision = $content->getCurrentRevision();
        self::assertInstanceOf(LearningContentRevision::class, $revision);
        self::assertTrue($revision->isSealed());
    }

    public function testPublishedContentConflicts(): void
    {
        $teacher = $this->teacher('pkg133-published-owner@example.com');
        $publisher = $this->teacher('pkg133-publisher@example.com', UserRole::HeadTeacher);
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $this->contents()->submitForReview($content, $teacher, 'submit_pkg_pub');
        $this->contents()->publish($content, $publisher, 'publish_pkg');
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertContains('content_not_draft', $plan->conflictReasons);
        self::assertContains('revision_not_draft', $plan->conflictReasons);
        self::assertContains('review_or_publish_history_exists', $plan->conflictReasons);
        self::assertSame(0, $plan->contentStatusMatch);
    }

    public function testPlacementConflicts(): void
    {
        $teacher = $this->teacher('pkg133-place-owner@example.com');
        $admin = $this->teacher('pkg133-place-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users()->save($admin);
        $subject = $this->subject();
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $catalog = static::getContainer()->get(CatalogWriteService::class);
        $placements = static::getContainer()->get(CatalogTopicLessonManager::class);
        self::assertInstanceOf(CatalogWriteService::class, $catalog);
        self::assertInstanceOf(CatalogTopicLessonManager::class, $placements);
        $catalogSubject = $catalog->createSubject(GradeLevel::Grade1, 'Paket Ders', null, 40);
        $catalog->assignCanonicalSubject($admin, $catalogSubject->getId(), $subject->getId(), 'map_pkg');
        $unit = $catalog->createUnit($catalogSubject->getId(), 'Paket Unite', null, 1);
        $topic = $catalog->createTopic($unit->getId(), 'Paket Konu', null, 1, 10);
        $catalog->publishSubject($catalogSubject->getId());
        $catalog->publishUnit($unit->getId());
        $catalog->publishTopic($topic->getId());
        $placements->create($admin, $topic->getId(), $content->getId(), 'Nesnelerin Biçimsel Özellikleri', null, 1, 'place_pkg');
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['placement_exists'], $plan->conflictReasons);
        self::assertSame(0, $plan->placementAbsent);
        $this->assertStillPlaceholder($content);
    }

    public function testTitleSummaryAndOutcomeConflicts(): void
    {
        $teacher = $this->teacher('pkg133-title@example.com');
        $title = $this->placeholder($teacher, $this->ensureOutcome(), 'Başka Başlık', $this->packageSummary());
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['title_mismatch'], $plan->conflictReasons);
        self::assertSame(0, $plan->titleMatch);
        $this->assertStillPlaceholder($title);
    }

    public function testSummaryConflict(): void
    {
        $teacher = $this->teacher('pkg133-summary@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, 'Kısa özet');
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['summary_mismatch'], $plan->conflictReasons);
        self::assertSame(0, $plan->summaryMatch);
        self::assertStringNotContainsString('Kısa özet', implode("\n", $plan->lines()));
        $this->assertStillPlaceholder($content);
    }

    public function testNullSummaryMismatchesFilledPackageSummary(): void
    {
        $teacher = $this->teacher('pkg133-null-summary@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, null);
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['summary_mismatch'], $plan->conflictReasons);
        self::assertSame(0, $plan->summaryMatch);
        $this->assertStillPlaceholder($content);
    }

    public function testOutcomeConflict(): void
    {
        $teacher = $this->teacher('pkg133-outcome@example.com');
        $this->ensureOutcome();
        $content = $this->placeholder($teacher, $this->ensureExtraOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['outcome_mismatch'], $plan->conflictReasons);
        self::assertSame(0, $plan->outcomeMatch);
        $this->assertStillPlaceholder($content);
    }

    public function testMultipleMismatchesAreSortedDeterministically(): void
    {
        $teacher = $this->teacher('pkg133-multi@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), 'Başka Başlık', 'Kısa özet');
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['title_mismatch', 'summary_mismatch'], $plan->conflictReasons);
        self::assertSame(2, $plan->conflicts);
        self::assertSame('title_mismatch,summary_mismatch', implode(',', $plan->conflictReasons));
        $this->assertStillPlaceholder($content);
    }

    public function testEditedBodyIsRevisionNotPlaceholder(): void
    {
        $teacher = $this->teacher('pkg133-edited@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $revision = $content->getCurrentRevision();
        self::assertInstanceOf(LearningContentRevision::class, $revision);
        $this->contents()->updateUnsealedRevision($revision, $teacher, LearningContentDocument::paragraph('Dolu metin'), 'edit_pkg');
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['revision_not_placeholder'], $plan->conflictReasons);
        self::assertSame(0, $plan->placeholderRevision);
    }

    public function testGradeMismatchReason(): void
    {
        $teacher = $this->teacher('pkg133-grade@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE learning_contents SET grade_level = ? WHERE id = ?',
            [GradeLevel::Grade2->value, $content->getId()->toBinary()],
            [ParameterType::INTEGER, ParameterType::BINARY],
        );
        $this->entityManager->clear();
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['grade_mismatch'], $plan->conflictReasons);
        self::assertSame(0, $plan->gradeMatch);
    }

    public function testContentTypeMismatchReason(): void
    {
        $teacher = $this->teacher('pkg133-type@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE learning_contents SET content_type = ? WHERE id = ?',
            [LearningContentType::Video->value, $content->getId()->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );
        $this->entityManager->clear();
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['content_type_mismatch'], $plan->conflictReasons);
        self::assertSame(0, $plan->contentTypeMatch);
    }

    public function testSubjectMismatchReason(): void
    {
        $teacher = $this->teacher('pkg133-subject@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $other = $this->subjects()->findOneByCode('hayat_bilgisi');
        if (!$other instanceof Subject) {
            $other = $this->subjectManager()->create($this->superAdmin(), 'hayat_bilgisi', 'Hayat Bilgisi', 'create_hb');
        }
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE learning_contents SET subject_id = ? WHERE id = ?',
            [$other->getId()->toBinary(), $content->getId()->toBinary()],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
        $this->entityManager->clear();
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertContains('subject_mismatch', $plan->conflictReasons);
        self::assertSame(0, $plan->subjectMatch);
    }

    public function testMissingContentIsUnsupportedExistingState(): void
    {
        $teacher = $this->teacher('pkg133-missing@example.com');
        $this->ensureOutcome();
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(['unsupported_existing_state'], $plan->conflictReasons);
        self::assertSame(0, $plan->contentFound);
        self::assertSame(0, $plan->applyReady);
    }

    public function testFailureInsideTheTransactionKeepsThePlaceholder(): void
    {
        $teacher = $this->teacher('pkg133-rollback@example.com');
        $content = $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        $listener = new class {
            public function onFlush(): void
            {
                throw new \RuntimeException('forced package failure');
            }
        };
        $this->entityManager->getEventManager()->addEventListener(Events::onFlush, $listener);
        try {
            $this->importer->execute(self::PACKAGE, 'apply', $plan->planFingerprint, $teacher);
            self::fail('forced failure');
        } catch (LearningContentPackageException $exception) {
            self::assertSame('Package import conflict.', $exception->getMessage());
        } finally {
            $this->entityManager->getEventManager()->removeEventListener(Events::onFlush, $listener);
        }
        $this->assertStillPlaceholder($content);
    }

    public function testCommandOutputAndAuditMetadataStayClosed(): void
    {
        $email = 'pkg133-command@example.com';
        $teacher = $this->teacher($email);
        $this->placeholder($teacher, $this->ensureOutcome(), LearningContentPackageTarget::mat133()->title, $this->packageSummary());
        putenv('TESTLIG_CONTENT_ACTOR_EMAIL='.$email);
        $_ENV['TESTLIG_CONTENT_ACTOR_EMAIL'] = $email;
        $_SERVER['TESTLIG_CONTENT_ACTOR_EMAIL'] = $email;
        try {
            $kernel = self::$kernel;
            self::assertNotNull($kernel);
            $application = new Application($kernel);
            $command = $application->find('app:learning-content:import-package');
            $tester = new CommandTester($command);
            $status = $tester->execute([
                '--package' => self::PACKAGE,
                '--mode' => 'dry-run',
                '--actor-email-env' => 'TESTLIG_CONTENT_ACTOR_EMAIL',
            ]);
            $display = $tester->getDisplay();
            self::assertSame(0, $status);
            self::assertStringNotContainsString($email, $display);
            self::assertStringNotContainsString('@', $display);
            self::assertStringNotContainsString('Nesnenin nasıl göründüğüne bak', $display);
            self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $display);
            self::assertStringContainsString('plan_fingerprint=', $display);
            self::assertStringContainsString('questions_touched=0', $display);
            self::assertStringContainsString('conflict_reason_count=0', $display);
            self::assertStringContainsString('conflict_reasons=', $display);
            self::assertStringContainsString('owner_match=1', $display);
            self::assertStringContainsString('summary_match=1', $display);
            self::assertStringNotContainsString('Nesnelerin Biçimini Ayırt Edelim', $display);
            self::assertStringNotContainsString($this->packageSummary(), $display);
            self::assertDoesNotMatchRegularExpression('/conflict_reasons=[^\n]*[A-Z]/', $display);

            $rejected = $tester->execute([
                '--package' => self::PACKAGE,
                '--mode' => 'dry-run',
                '--actor-email-env' => 'OTHER_ACTOR_EMAIL',
            ]);
            self::assertSame(1, $rejected);
            self::assertStringContainsString('Actor is not available.', $tester->getDisplay());
        } finally {
            putenv('TESTLIG_CONTENT_ACTOR_EMAIL');
            unset($_ENV['TESTLIG_CONTENT_ACTOR_EMAIL'], $_SERVER['TESTLIG_CONTENT_ACTOR_EMAIL']);
        }
    }

    public function testUnknownPackageIsRejected(): void
    {
        $teacher = $this->teacher('pkg133-unknown@example.com');
        $this->expectException(LearningContentPackageException::class);
        $this->importer->execute('data/content/tymm-2026/grade-1/matematik/mat-1-3-1', 'verify', null, $teacher);
    }

    public function testWrongPackageKeyDirectoryIsRejected(): void
    {
        $teacher = $this->teacher('pkg133-wrongdir@example.com');
        $this->expectException(LearningContentPackageException::class);
        $this->importer->execute('data/content/tymm-2026/grade-1/matematik/mat-1-3-2/../mat-1-3-3', 'verify', null, $teacher);
    }

    private function assertPackageAudit(string $operation): void
    {
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        /** @var list<\App\Entity\SecurityAuditEvent> $rows */
        $rows = $events->createQueryBuilder('event')
            ->andWhere('event.action = :action')
            ->setParameter('action', SecurityAuditAction::LearningContentPackageImported->value)
            ->getQuery()
            ->getResult();
        $matched = false;
        foreach ($rows as $row) {
            $metadata = $row->getMetadata();
            $encoded = json_encode($metadata);
            self::assertIsString($encoded);
            self::assertStringNotContainsString('@', $encoded);
            self::assertStringNotContainsString('Nesnenin nasıl', $encoded);
            self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $encoded);
            if (($metadata['operation'] ?? null) !== $operation) {
                continue;
            }
            $matched = true;
            self::assertSame([
                'block_count',
                'fixture_checksum',
                'operation',
                'package_key',
                'stable_code',
            ], $this->sortedKeys($metadata));
            self::assertSame(9, $metadata['block_count'] ?? null);
            self::assertSame('mat_1_3_3_nesnelerin_bicimsel_ozellikleri', $metadata['stable_code'] ?? null);
            self::assertSame('tymm-2026/grade-1/matematik/mat-1-3-3', $metadata['package_key'] ?? null);
        }
        self::assertTrue($matched, $operation);
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<string>
     */
    private function sortedKeys(array $metadata): array
    {
        $keys = array_keys($metadata);
        sort($keys);

        /* @var list<string> $keys */
        return $keys;
    }

    private function assertStillPlaceholder(LearningContent $content): void
    {
        $body = $this->entityManager->getConnection()->fetchOne(
            'SELECT structured_content FROM learning_content_revisions WHERE content_id = ? ORDER BY revision_number ASC LIMIT 1',
            [$content->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsString($body);
        self::assertStringContainsString('[Taslak]', $body);
        self::assertStringNotContainsString('Nesnenin nasıl göründüğüne bak', $body);
    }

    private function packageSummary(): string
    {
        $summary = LearningContentPackageTarget::mat133()->summary;
        self::assertIsString($summary);

        return $summary;
    }

    private function placeholder(
        User $teacher,
        CurriculumLearningOutcome $outcome,
        string $title,
        ?string $summary,
        string $code = 'mat_1_3_3_nesnelerin_bicimsel_ozellikleri',
    ): LearningContent {
        return $this->contents()->createDraft(
            $teacher,
            LearningContentScope::Platform,
            null,
            $outcome->getCurriculumProgram()->getSubject(),
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            $code,
            $title,
            $summary,
            LearningContentDocument::paragraph('[Taslak]'),
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            'create_pkg',
        );
    }

    private function ensureOutcome(): CurriculumLearningOutcome
    {
        $program = $this->program();
        $existing = $this->outcomes()->findOneByProgramAndCode($program, 'mat_1_3_3');
        if ($existing instanceof CurriculumLearningOutcome) {
            return $existing;
        }
        if (CurriculumStatus::Draft === $program->getStatus()) {
            return $this->outcomeManager()->create($this->freshTopic($program), $this->superAdmin(), 'mat_1_3_3', 'Kazanim', 1, 'create_lo');
        }

        return $this->attachOutcome($program, 'mat_1_3_3', 'pkg_import_unit_133', 82);
    }

    private function ensureExtraOutcome(): CurriculumLearningOutcome
    {
        $program = $this->program();
        $existing = $this->outcomes()->findOneByProgramAndCode($program, 'mat_1_3_1');
        if ($existing instanceof CurriculumLearningOutcome) {
            return $existing;
        }

        return $this->attachOutcome($program, 'mat_pkg_other', 'pkg_import_unit_b', 81);
    }

    private function attachOutcome(CurriculumProgram $program, string $code, string $unitCode, int $position): CurriculumLearningOutcome
    {
        $now = new \DateTimeImmutable('now');
        $unit = CurriculumUnit::create($program, $unitCode, 'Paket', 'paket', $position, null, $now);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();
        $topic = CurriculumTopic::createRoot($unit, $unitCode.'_t', 'Konu', 'konu', 1, null, $now);
        $this->entityManager->persist($topic);
        $this->entityManager->flush();
        $outcome = CurriculumLearningOutcome::create($topic, $program, $code, 'Kazanim', 'kazanim', 1, $now);
        $this->entityManager->persist($outcome);
        $this->entityManager->flush();

        return $outcome;
    }

    private function freshTopic(CurriculumProgram $program): CurriculumTopic
    {
        $unit = $this->units()->create($program, $this->superAdmin(), 'pkg_draft_unit', 'Unit', 2, 'create_u');

        return $this->topics()->createRoot($unit, $this->superAdmin(), 'pkg_draft_topic', 'Topic', 1, 'create_t');
    }

    private function program(): CurriculumProgram
    {
        $subject = $this->subject();
        $existing = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        if ($existing instanceof CurriculumProgram) {
            return $existing;
        }
        $program = $this->programManager()->createDraft(
            $subject,
            $this->superAdmin(),
            GradeLevel::Grade1,
            'mat_grade1_tymm',
            'Matematik 1',
            'TYMM-2026',
            'create_p',
        );
        $this->programManager()->publish($program, $this->superAdmin(), 'publish_p');

        return $program;
    }

    private function subject(): Subject
    {
        $existing = $this->subjects()->findOneByCode('matematik');
        if ($existing instanceof Subject) {
            return $existing;
        }

        return $this->subjectManager()->create($this->superAdmin(), 'matematik', 'Matematik', 'create_s');
    }

    private function teacher(string $email, UserRole $role = UserRole::Teacher): User
    {
        $user = $this->users()->findOneByNormalizedEmail(mb_strtolower($email, 'UTF-8'));
        if (!$user instanceof User) {
            $user = $this->factory()->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $role);
            $user->markEmailVerified(new \DateTimeImmutable('now'));
            $user->transitionTo(UserStatus::Active);
            $this->users()->save($user);
        }

        return $user;
    }

    private function superAdmin(): User
    {
        $user = $this->users()->findOneActiveVerifiedSuperAdmin();
        if ($user instanceof User) {
            return $user;
        }
        $user = $this->factory()->createAndPersist('pkg133-sa@example.com', 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users()->save($user);

        return $user;
    }

    /**
     * @return array<string, int>
     */
    private function evidence(): array
    {
        $connection = $this->entityManager->getConnection();
        $counts = [];
        foreach (['questions', 'question_answer_keys', 'assessments', 'assessment_attempts', 'catalog_topic_lessons', 'users'] as $table) {
            $counts[$table] = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $counts;
    }

    private function contents(): LearningContentManager
    {
        $manager = static::getContainer()->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $manager);

        return $manager;
    }

    private function users(): UserRepository
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private function factory(): UserFactory
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);

        return $factory;
    }

    private function subjects(): SubjectRepository
    {
        $repository = static::getContainer()->get(SubjectRepository::class);
        self::assertInstanceOf(SubjectRepository::class, $repository);

        return $repository;
    }

    private function subjectManager(): SubjectManager
    {
        $manager = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $manager);

        return $manager;
    }

    private function programs(): CurriculumProgramRepository
    {
        $repository = static::getContainer()->get(CurriculumProgramRepository::class);
        self::assertInstanceOf(CurriculumProgramRepository::class, $repository);

        return $repository;
    }

    private function programManager(): CurriculumProgramManager
    {
        $manager = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $manager);

        return $manager;
    }

    private function units(): CurriculumUnitManager
    {
        $manager = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $manager);

        return $manager;
    }

    private function topics(): CurriculumTopicManager
    {
        $manager = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $manager);

        return $manager;
    }

    private function outcomes(): CurriculumLearningOutcomeRepository
    {
        $repository = static::getContainer()->get(CurriculumLearningOutcomeRepository::class);
        self::assertInstanceOf(CurriculumLearningOutcomeRepository::class, $repository);

        return $repository;
    }

    private function outcomeManager(): CurriculumLearningOutcomeManager
    {
        $manager = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $manager);

        return $manager;
    }
}
