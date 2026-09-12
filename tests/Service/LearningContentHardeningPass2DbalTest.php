<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Entity\StoredMediaAsset;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\LearningContentFailureReason;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\LearningContentType;
use App\Enum\SecurityAuditAction;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaScanStatus;
use App\Enum\StoredMediaStorageProvider;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\LearningContentException;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\LearningContentRevisionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\StoredMediaAssetManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\LearningContentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Stage 2.15 pass-2: media archive timestamps, revision sequential/current sync, published_at match.
 */
final class LearningContentHardeningPass2DbalTest extends KernelTestCase
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

    public function testReadyToArchivedClearsReadyAt(): void
    {
        [$sa] = $this->platformCurriculum('p2_a');
        $asset = $this->registerReadyAsset($sa, 'p2_a.png', str_repeat('a1', 32));
        $conn = $this->em->getConnection();
        $id = $asset->getId()->toBinary();
        $at = (new \DateTimeImmutable('+1 second'))->format('Y-m-d H:i:s');

        $conn->executeStatement(
            'UPDATE stored_media_assets SET status = \'archived\', ready_at = NULL, archived_at = ?, updated_at = ? WHERE id = ?',
            [$at, $at, $id],
        );

        $this->em->clear();
        $fresh = $this->em->find(StoredMediaAsset::class, $asset->getId());
        self::assertInstanceOf(StoredMediaAsset::class, $fresh);
        self::assertSame(StoredMediaAssetStatus::Archived, $fresh->getStatus());
        self::assertNull($fresh->getReadyAt());
        self::assertNotNull($fresh->getArchivedAt());
        self::assertNull($fresh->getQuarantinedAt());
    }

    public function testQuarantinedToArchivedKeepsQuarantinedAt(): void
    {
        [$sa] = $this->platformCurriculum('p2_b');
        $asset = $this->registerAsset($sa, 'p2_b.png', str_repeat('b1', 32));
        $conn = $this->em->getConnection();
        $id = $asset->getId()->toBinary();
        $qAt = (new \DateTimeImmutable('+1 second'))->format('Y-m-d H:i:s');
        $aAt = (new \DateTimeImmutable('+2 seconds'))->format('Y-m-d H:i:s');

        $conn->executeStatement(
            'UPDATE stored_media_assets SET status = \'quarantined\', quarantined_at = ?, scan_status = \'infected\', updated_at = ? WHERE id = ?',
            [$qAt, $qAt, $id],
        );
        $conn->executeStatement(
            'UPDATE stored_media_assets SET status = \'archived\', archived_at = ?, updated_at = ? WHERE id = ?',
            [$aAt, $aAt, $id],
        );

        $this->em->clear();
        $fresh = $this->em->find(StoredMediaAsset::class, $asset->getId());
        self::assertInstanceOf(StoredMediaAsset::class, $fresh);
        self::assertSame(StoredMediaAssetStatus::Archived, $fresh->getStatus());
        self::assertNull($fresh->getReadyAt());
        self::assertNotNull($fresh->getQuarantinedAt());
        self::assertNotNull($fresh->getArchivedAt());
    }

    public function testArchivedToReadyRejectedAndPendingToArchivedTypedFailure(): void
    {
        [$sa] = $this->platformCurriculum('p2_c');
        $asset = $this->registerReadyAsset($sa, 'p2_c.png', str_repeat('c1', 32));
        $assetId = $asset->getId();
        $saId = $sa->getId();
        $this->media()->archive($asset, $sa, 'arch_c');

        try {
            $this->media()->markReady($asset, $sa, 'ready_again');
            self::fail('archived to ready');
        } catch (LearningContentException $e) {
            self::assertSame(LearningContentFailureReason::InvalidTransition, $e->getReason());
        }

        $this->resetDoctrine();
        $sa = $this->users->find($saId);
        self::assertInstanceOf(User::class, $sa);

        $pending = $this->registerAsset($sa, 'p2_c_pend.png', str_repeat('c2', 32));
        self::assertFalse(StoredMediaAssetStatus::Pending->canTransitionTo(StoredMediaAssetStatus::Archived));
        try {
            $this->media()->archive($pending, $sa, 'arch_pending');
            self::fail('pending to archived');
        } catch (LearningContentException $e) {
            self::assertSame(LearningContentFailureReason::InvalidTransition, $e->getReason());
        }

        $this->resetDoctrine();
        $still = $this->em->find(StoredMediaAsset::class, $assetId);
        self::assertInstanceOf(StoredMediaAsset::class, $still);
        self::assertSame(StoredMediaAssetStatus::Archived, $still->getStatus());
    }

    public function testManagerReadyArchiveAuditsWithoutStorageKey(): void
    {
        [$sa] = $this->platformCurriculum('p2_d');
        $beforeScan = $this->events->countByAction(SecurityAuditAction::StoredMediaAssetScanUpdated->value);
        $beforeReady = $this->events->countByAction(SecurityAuditAction::StoredMediaAssetMarkedReady->value);
        $beforeArch = $this->events->countByAction(SecurityAuditAction::StoredMediaAssetArchived->value);

        $asset = $this->registerAsset($sa, 'p2_d.png', str_repeat('d1', 32));
        $storageKey = $asset->getStorageKey();
        $this->media()->markScanStatus($asset, $sa, StoredMediaScanStatus::Clean, 'scan_d');
        $this->media()->markReady($asset, $sa, 'ready_d');
        $this->media()->archive($asset, $sa, 'arch_d');

        self::assertSame($beforeScan + 1, $this->events->countByAction(SecurityAuditAction::StoredMediaAssetScanUpdated->value));
        self::assertSame($beforeReady + 1, $this->events->countByAction(SecurityAuditAction::StoredMediaAssetMarkedReady->value));
        self::assertSame($beforeArch + 1, $this->events->countByAction(SecurityAuditAction::StoredMediaAssetArchived->value));

        $this->em->clear();
        $fresh = $this->em->find(StoredMediaAsset::class, $asset->getId());
        self::assertInstanceOf(StoredMediaAsset::class, $fresh);
        self::assertSame(StoredMediaAssetStatus::Archived, $fresh->getStatus());
        self::assertNull($fresh->getReadyAt());

        $metaRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT metadata FROM security_audit_events WHERE action IN (?, ?, ?) ORDER BY occurred_at DESC LIMIT 10',
            [
                SecurityAuditAction::StoredMediaAssetRegistered->value,
                SecurityAuditAction::StoredMediaAssetMarkedReady->value,
                SecurityAuditAction::StoredMediaAssetArchived->value,
            ],
        );
        foreach ($metaRows as $row) {
            $json = \is_string($row['metadata']) ? $row['metadata'] : json_encode($row['metadata'], \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($storageKey, $json);
            self::assertStringNotContainsString('storage_key', $json);
            self::assertStringNotContainsString('storageKey', $json);
        }

        $beforeFail = $this->events->countByAction(SecurityAuditAction::StoredMediaAssetMarkedReady->value);
        $assetId = $asset->getId();
        try {
            $this->media()->markReady($fresh, $sa, 'tamper_ready');
            self::fail('archived ready after archive');
        } catch (LearningContentException $e) {
            self::assertSame(LearningContentFailureReason::InvalidTransition, $e->getReason());
        }
        $this->resetDoctrine();
        self::assertSame($beforeFail, $this->events->countByAction(SecurityAuditAction::StoredMediaAssetMarkedReady->value));
        $still = $this->em->find(StoredMediaAsset::class, $assetId);
        self::assertInstanceOf(StoredMediaAsset::class, $still);
        self::assertSame(StoredMediaAssetStatus::Archived, $still->getStatus());
    }

    public function testRevisionSequentialAndCurrentLatest(): void
    {
        [$sa, , $subject] = $this->platformCurriculum('p2_e');
        $conn = $this->em->getConnection();
        $contentId = new UuidV7();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->insert('learning_contents', [
            'id' => $contentId->toBinary(),
            'scope' => 'platform',
            'institution_id' => null,
            'subject_id' => $subject->getId()->toBinary(),
            'grade_level' => GradeLevel::Grade9->value,
            'content_type' => LearningContentType::Document->value,
            'status' => LearningContentStatus::Draft->value,
            'code' => 'p2_e_bare',
            'slug' => 'p2-e-bare',
            'title' => 'Bare',
            'normalized_title' => 'bare',
            'summary' => null,
            'current_revision_id' => null,
            'current_revision_number' => null,
            'published_revision_id' => null,
            'published_revision_number' => null,
            'created_by_id' => $sa->getId()->toBinary(),
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => null,
            'archived_at' => null,
        ]);

        try {
            $this->insertRevision($conn, $contentId->toBinary(), $sa->getId()->toBinary(), 2);
            self::fail('first revision must be 1');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('sequential', $e->getMessage());
        }

        $rev1Id = $this->insertRevision($conn, $contentId->toBinary(), $sa->getId()->toBinary(), 1);
        $row = $conn->fetchAssociative(
            'SELECT current_revision_id, current_revision_number FROM learning_contents WHERE id = ?',
            [$contentId->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame($rev1Id, $row['current_revision_id']);
        self::assertSame(1, (int) $row['current_revision_number']);

        try {
            $this->insertRevision($conn, $contentId->toBinary(), $sa->getId()->toBinary(), 3);
            self::fail('gap revision 3');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('sequential', $e->getMessage());
        }

        $rev2Id = $this->insertRevision($conn, $contentId->toBinary(), $sa->getId()->toBinary(), 2);
        $row = $conn->fetchAssociative(
            'SELECT current_revision_id, current_revision_number FROM learning_contents WHERE id = ?',
            [$contentId->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame($rev2Id, $row['current_revision_id']);
        self::assertSame(2, (int) $row['current_revision_number']);

        try {
            $this->insertRevision($conn, $contentId->toBinary(), $sa->getId()->toBinary(), 2);
            self::fail('duplicate revision 2');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET current_revision_id = ?, current_revision_number = 1 WHERE id = ?',
                [$rev1Id, $contentId->toBinary()],
            );
            self::fail('current to older revision');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('latest', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET current_revision_id = ?, current_revision_number = 2 WHERE id = ?',
                [$rev1Id, $contentId->toBinary()],
            );
            self::fail('id/number mismatch');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET current_revision_id = NULL, current_revision_number = NULL WHERE id = ?',
                [$contentId->toBinary()],
            );
            self::fail('null current with revisions');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('current', $e->getMessage());
        }
    }

    public function testCrossContentCurrentAndPublicationCurrentOnly(): void
    {
        [$sa, $reviewer, $subject, , $lo] = $this->platformCurriculum('p2_f');
        $contentA = $this->createDraft($sa, $subject, $lo, 'p2_f_a');
        $contentB = $this->createDraft($sa, $subject, $lo, 'p2_f_b');
        $revA = $this->currentRevision($contentA);
        $revB = $this->currentRevision($contentB);
        $conn = $this->em->getConnection();

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET current_revision_id = ?, current_revision_number = ? WHERE id = ?',
                [$revB->getId()->toBinary(), $revB->getRevisionNumber(), $contentA->getId()->toBinary()],
            );
            self::fail('cross-content current');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $this->contents()->submitForReview($contentA, $sa, 'submit_f');
        $this->em->refresh($revA);
        $this->insertPublication($conn, $contentA, $revA, $reviewer, 1);

        $this->contents()->returnToDraft($contentA, $reviewer, 'return_f');
        $this->contents()->createRevision(
            $contentA,
            $sa,
            LearningContentDocument::paragraph('v2'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'rev2_f',
        );
        $this->em->refresh($contentA);
        $rev2 = $this->currentRevision($contentA);
        self::assertSame(2, $rev2->getRevisionNumber());

        try {
            $this->insertPublication($conn, $contentA, $revA, $reviewer, 2);
            self::fail('old revision publication');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('current', $e->getMessage());
        }
    }

    public function testPublishedAtMustMatchLatestAndArchiveKeepsIt(): void
    {
        [$sa, $reviewer, $subject, , $lo] = $this->platformCurriculum('p2_g');
        $content = $this->createDraft($sa, $subject, $lo, 'p2_g');
        $this->contents()->submitForReview($content, $sa, 'submit_g');
        $rev = $this->currentRevision($content);
        $this->em->refresh($rev);
        $conn = $this->em->getConnection();
        $this->insertPublication($conn, $content, $rev, $reviewer, 1);

        $row = $conn->fetchAssociative(
            'SELECT published_revision_id, published_revision_number, published_at FROM learning_contents WHERE id = ?',
            [$content->getId()->toBinary()],
        );
        self::assertIsArray($row);
        $publishedAt = $row['published_at'];
        self::assertNotNull($publishedAt);

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET published_at = ? WHERE id = ?',
                [(new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'), $content->getId()->toBinary()],
            );
            self::fail('change published_at');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('latest', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET published_at = NULL, status = \'draft\' WHERE id = ?',
                [$content->getId()->toBinary()],
            );
            self::fail('null published_at');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $conn->executeStatement(
            'UPDATE learning_contents SET status = \'archived\', archived_at = ? WHERE id = ?',
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $content->getId()->toBinary()],
        );
        $archived = $conn->fetchAssociative(
            'SELECT status, published_revision_id, published_revision_number, published_at FROM learning_contents WHERE id = ?',
            [$content->getId()->toBinary()],
        );
        self::assertIsArray($archived);
        self::assertSame('archived', $archived['status']);
        self::assertSame($row['published_revision_id'], $archived['published_revision_id']);
        self::assertSame((int) $row['published_revision_number'], (int) $archived['published_revision_number']);
        self::assertSame($publishedAt, $archived['published_at']);
    }

    public function testCreateDraftCommitsWithCurrentPointingToRev1(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('p2_h');
        $content = $this->createDraft($sa, $subject, $lo, 'p2_h');
        $this->em->clear();
        $fresh = $this->em->getConnection()->fetchAssociative(
            'SELECT current_revision_id, current_revision_number FROM learning_contents WHERE id = ?',
            [$content->getId()->toBinary()],
        );
        self::assertIsArray($fresh);
        self::assertNotNull($fresh['current_revision_id']);
        self::assertSame(1, (int) $fresh['current_revision_number']);
        $revId = $this->em->getConnection()->fetchOne(
            'SELECT id FROM learning_content_revisions WHERE content_id = ? AND revision_number = 1',
            [$content->getId()->toBinary()],
        );
        self::assertSame($revId, $fresh['current_revision_id']);
    }

    /**
     * @return array{0: User, 1: User, 2: Subject, 3: \App\Entity\CurriculumProgram, 4: CurriculumLearningOutcome}
     */
    private function platformCurriculum(string $suffix): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $reviewer = $this->activeUser($suffix.'-rev@example.com', UserRole::HeadTeacher);
        $subject = $this->subjects()->create($sa, $suffix.'_sub', 'Subject '.$suffix, 'create_s');
        $program = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade9, $suffix.'_p', 'P '.$suffix, '1.0', 'create_p');
        $unit = $this->units()->create($program, $sa, 'u1', 'U', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'T', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $sa, 'lo_'.$suffix, 'Outcome', 1, 'create_lo');
        $this->programs()->publish($program, $sa, 'publish_p');

        return [$sa, $reviewer, $subject, $program, $lo];
    }

    private function createDraft(User $sa, Subject $subject, CurriculumLearningOutcome $lo, string $code): LearningContent
    {
        return $this->contents()->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::Document,
            $code,
            'Title '.$code,
            null,
            LearningContentDocument::paragraph('Body '.$code),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_'.$code,
        );
    }

    private function registerAsset(User $sa, string $filename, string $sha): StoredMediaAsset
    {
        return $this->media()->registerMetadata(
            $sa,
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            StoredMediaStorageProvider::Local,
            $filename,
            'image/png',
            100,
            $sha,
            'reg_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($filename)),
        );
    }

    private function registerReadyAsset(User $sa, string $filename, string $sha): StoredMediaAsset
    {
        $asset = $this->registerAsset($sa, $filename, $sha);
        $this->media()->markScanStatus($asset, $sa, StoredMediaScanStatus::Clean, 'scan_ready');
        $this->media()->markReady($asset, $sa, 'mark_ready');

        return $asset;
    }

    private function currentRevision(LearningContent $content): LearningContentRevision
    {
        $repo = static::getContainer()->get(LearningContentRevisionRepository::class);
        self::assertInstanceOf(LearningContentRevisionRepository::class, $repo);
        $revision = $content->getCurrentRevision()
            ?? $repo->findOneByContentAndNumber($content, (int) $content->getCurrentRevisionNumber());
        self::assertInstanceOf(LearningContentRevision::class, $revision);

        return $revision;
    }

    private function insertRevision(Connection $conn, string $contentIdBinary, string $actorIdBinary, int $number): string
    {
        $id = (new UuidV7())->toBinary();
        $conn->insert('learning_content_revisions', [
            'id' => $id,
            'content_id' => $contentIdBinary,
            'revision_number' => $number,
            'schema_version' => LearningContentDocument::SCHEMA_VERSION,
            'structured_content' => json_encode(LearningContentDocument::paragraph('r'.$number)->toArray(), \JSON_THROW_ON_ERROR),
            'estimated_minutes' => null,
            'language' => 'tr',
            'source_type' => 'original',
            'source_reference' => null,
            'accessibility_metadata' => null,
            'content_hash' => hash('sha256', 'r'.$number),
            'created_by_id' => $actorIdBinary,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'sealed_at' => null,
            'is_sealed' => 0,
        ]);

        return $id;
    }

    private function insertPublication(
        Connection $conn,
        LearningContent $content,
        LearningContentRevision $revision,
        User $actor,
        int $publicationNumber,
    ): void {
        $conn->insert('learning_content_publications', [
            'id' => (new UuidV7())->toBinary(),
            'content_id' => $content->getId()->toBinary(),
            'revision_id' => $revision->getId()->toBinary(),
            'publication_number' => $publicationNumber,
            'content_hash' => $revision->getContentHash(),
            'published_by_id' => $actor->getId()->toBinary(),
            'published_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'schema_version' => $revision->getSchemaVersion(),
        ]);
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

    private function contents(): LearningContentManager
    {
        $s = static::getContainer()->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $s);

        return $s;
    }

    private function media(): StoredMediaAssetManager
    {
        $s = static::getContainer()->get(StoredMediaAssetManager::class);
        self::assertInstanceOf(StoredMediaAssetManager::class, $s);

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

    private function resetDoctrine(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebind();
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        LearningContentDbCleanup::deleteLearningContents($connection);
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
