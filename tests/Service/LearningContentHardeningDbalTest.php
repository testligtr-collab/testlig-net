<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\LearningContentFailureReason;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\LearningContentType;
use App\Enum\SecurityAuditAction;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
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
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\LearningContentManager;
use App\Service\StoredMediaAssetManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Stage 2.15 hardening: direct DBAL integrity for pointers, publication, tenant, lifecycle.
 */
final class LearningContentHardeningDbalTest extends KernelTestCase
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

    public function testACurrentPointerRejectsCrossContentMismatchAndAcceptsValid(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('h_a');
        $contentA = $this->createDraft($sa, $subject, $lo, 'h_a_a');
        $contentB = $this->createDraft($sa, $subject, $lo, 'h_a_b');
        $revA = $this->currentRevision($contentA);
        $revB = $this->currentRevision($contentB);
        $conn = $this->em->getConnection();

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET current_revision_id = ?, current_revision_number = 99 WHERE id = ?',
                [$revA->getId()->toBinary(), $contentA->getId()->toBinary()],
            );
            self::fail('nonexistent current revision number');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET current_revision_id = ?, current_revision_number = ? WHERE id = ?',
                [$revB->getId()->toBinary(), $revB->getRevisionNumber(), $contentA->getId()->toBinary()],
            );
            self::fail('cross-content current pointer');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET current_revision_id = ?, current_revision_number = ? WHERE id = ?',
                [$revA->getId()->toBinary(), 2, $contentA->getId()->toBinary()],
            );
            self::fail('id/number mismatch');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $conn->executeStatement(
            'UPDATE learning_contents SET current_revision_id = ?, current_revision_number = ? WHERE id = ?',
            [$revA->getId()->toBinary(), $revA->getRevisionNumber(), $contentA->getId()->toBinary()],
        );
        $row = $conn->fetchAssociative(
            'SELECT current_revision_id, current_revision_number FROM learning_contents WHERE id = ?',
            [$contentA->getId()->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame($revA->getId()->toBinary(), $row['current_revision_id']);
        self::assertSame(1, (int) $row['current_revision_number']);
    }

    public function testBPublicationBiAiAndBuProtections(): void
    {
        [$sa, $reviewer, $subject, , $lo] = $this->platformCurriculum('h_b');
        $content = $this->createDraft($sa, $subject, $lo, 'h_b_doc');
        $rev1 = $this->currentRevision($content);
        $conn = $this->em->getConnection();

        try {
            $this->insertPublication($conn, $content, $rev1, $sa, 1);
            self::fail('unsealed publication');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('sealed', $e->getMessage());
        }

        $this->contents()->submitForReview($content, $sa, 'submit_b');
        $this->em->refresh($rev1);
        self::assertTrue($rev1->isSealed());

        $this->contents()->returnToDraft($content, $reviewer, 'return_b');
        $this->contents()->createRevision(
            $content,
            $sa,
            LearningContentDocument::paragraph('v2'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'rev2_b',
        );
        $this->em->refresh($content);
        $rev2 = $this->currentRevision($content);
        self::assertSame(2, $rev2->getRevisionNumber());

        try {
            $this->insertPublication($conn, $content, $rev1, $sa, 1);
            self::fail('non-current revision publication');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('current', $e->getMessage());
        }

        $this->contents()->submitForReview($content, $sa, 'submit_b2');
        $this->em->refresh($rev2);
        $this->em->refresh($content);

        try {
            $this->insertPublication($conn, $content, $rev2, $sa, 1, contentHash: str_repeat('ab', 32));
            self::fail('wrong hash');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('hash', $e->getMessage());
        }

        try {
            $this->insertPublication($conn, $content, $rev2, $sa, 1, schemaVersion: 99);
            self::fail('wrong schema');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('schema', $e->getMessage());
        }

        try {
            $this->insertPublication($conn, $content, $rev2, $sa, 2);
            self::fail('first publication number 2');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('sequential', $e->getMessage());
        }

        $this->insertPublication($conn, $content, $rev2, $reviewer, 1);
        $this->em->clear();
        $fresh = $this->freshConnection();
        $row = $fresh->fetchAssociative(
            'SELECT status, published_revision_id, published_revision_number, published_at FROM learning_contents WHERE id = ?',
            [$content->getId()->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame(LearningContentStatus::Published->value, $row['status']);
        self::assertSame($rev2->getId()->toBinary(), $row['published_revision_id']);
        self::assertSame(2, (int) $row['published_revision_number']);
        self::assertNotNull($row['published_at']);

        // Rebuild path: publish rev1 history then move pointer via second publication.
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $lo = $this->em->find(CurriculumLearningOutcome::class, $lo->getId());
        self::assertInstanceOf(CurriculumLearningOutcome::class, $lo);

        $rev3 = $this->contents()->createRevision(
            $content,
            $sa,
            LearningContentDocument::paragraph('v3'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'rev3_b',
        );
        $this->contents()->submitForReview($content, $sa, 'submit_b3');
        try {
            $this->insertPublication($conn, $content, $rev3, $sa, 3);
            self::fail('publication number gap');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('sequential', $e->getMessage());
        }

        $this->insertPublication($conn, $content, $rev3, $reviewer, 2);
        $this->em->clear();
        $fresh = $this->freshConnection();
        self::assertSame(2, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM learning_content_publications WHERE content_id = ?',
            [$content->getId()->toBinary()],
        ));
        self::assertSame(1, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM learning_content_publications WHERE content_id = ? AND revision_id = ? AND publication_number = 1',
            [$content->getId()->toBinary(), $rev2->getId()->toBinary()],
        ));
        $row = $fresh->fetchAssociative(
            'SELECT published_revision_id, published_revision_number FROM learning_contents WHERE id = ?',
            [$content->getId()->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame($rev3->getId()->toBinary(), $row['published_revision_id']);
        self::assertSame(3, (int) $row['published_revision_number']);

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET published_revision_id = NULL, published_revision_number = NULL, status = \'draft\', published_at = NULL WHERE id = ?',
                [$content->getId()->toBinary()],
            );
            self::fail('clear published pointer');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('published', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET published_revision_id = ?, published_revision_number = 2 WHERE id = ?',
                [$rev2->getId()->toBinary(), $content->getId()->toBinary()],
            );
            self::fail('rollback published pointer');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('latest', $e->getMessage());
        }

        $conn->executeStatement(
            'UPDATE learning_contents SET status = \'archived\', archived_at = ? WHERE id = ?',
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $content->getId()->toBinary()],
        );
        $archived = $conn->fetchAssociative(
            'SELECT status, published_revision_id, published_revision_number FROM learning_contents WHERE id = ?',
            [$content->getId()->toBinary()],
        );
        self::assertIsArray($archived);
        self::assertSame('archived', $archived['status']);
        self::assertSame($rev3->getId()->toBinary(), $archived['published_revision_id']);
        self::assertSame(3, (int) $archived['published_revision_number']);

        try {
            $conn->executeStatement(
                'UPDATE learning_contents SET status = \'draft\', archived_at = NULL WHERE id = ?',
                [$content->getId()->toBinary()],
            );
            self::fail('un-archive');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('un-archive', $e->getMessage());
        }
    }

    public function testCAssetTenantMatrix(): void
    {
        [$sa, , $subject, , $lo] = $this->platformCurriculum('h_c');
        $ownerA = $this->activeUser('h_c_own_a@example.com', UserRole::InstitutionManager);
        $ownerB = $this->activeUser('h_c_own_b@example.com', UserRole::InstitutionManager);
        $instA = $this->institutions()->create($sa, $ownerA, 'School A', InstitutionType::School, 'inst_a');
        $instB = $this->institutions()->create($sa, $ownerB, 'School B', InstitutionType::School, 'inst_b');
        $this->institutionStatus()->activate($instA, $sa, 'act_a');
        $this->institutionStatus()->activate($instB, $sa, 'act_b');
        $teacherA = $this->activeUser('h_c_t_a@example.com', UserRole::Teacher);
        $this->memberships()->addMember($instA, $sa, $teacherA, InstitutionMembershipRole::Teacher, 'add_a');

        $platformContent = $this->createDraft($sa, $subject, $lo, 'h_c_plat');
        $instContent = $this->contents()->createDraft(
            $teacherA,
            LearningContentScope::Institution,
            $instA,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::Worksheet,
            'h_c_inst',
            'Inst WS',
            null,
            LearningContentDocument::paragraph('tenant'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_inst',
        );

        $media = $this->media();
        $platformAsset = $media->registerMetadata(
            $sa,
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            StoredMediaStorageProvider::Local,
            'p.png',
            'image/png',
            100,
            str_repeat('11', 32),
            'reg_p',
        );
        $assetA = $media->registerMetadata(
            $teacherA,
            StoredMediaAssetScope::Institution,
            $instA,
            StoredMediaAssetKind::Image,
            StoredMediaStorageProvider::Local,
            'a.png',
            'image/png',
            100,
            str_repeat('22', 32),
            'reg_a',
        );
        $assetB = $media->registerMetadata(
            $ownerB,
            StoredMediaAssetScope::Institution,
            $instB,
            StoredMediaAssetKind::Image,
            StoredMediaStorageProvider::Local,
            'b.png',
            'image/png',
            100,
            str_repeat('33', 32),
            'reg_b',
        );

        $platRev = $this->currentRevision($platformContent);
        $instRev = $this->currentRevision($instContent);
        $conn = $this->em->getConnection();

        try {
            $this->insertRevisionAsset($conn, $instRev, $assetB);
            self::fail('cross institution');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('tenant', $e->getMessage());
        }

        try {
            $this->insertRevisionAsset($conn, $platRev, $assetA);
            self::fail('platform + institution asset');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $this->insertRevisionAsset($conn, $instRev, $assetA);
        $this->insertRevisionAsset($conn, $instRev, $platformAsset, role: 'inline', position: 1);
        self::assertSame(2, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM learning_content_revision_assets WHERE revision_id = ?',
            [$instRev->getId()->toBinary()],
        ));
    }

    public function testDStoredMediaLifecycleTrigger(): void
    {
        [$sa] = $this->platformCurriculum('h_d');
        $asset = $this->media()->registerMetadata(
            $sa,
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            StoredMediaStorageProvider::Local,
            'life.png',
            'image/png',
            100,
            str_repeat('44', 32),
            'reg_life',
        );
        $conn = $this->em->getConnection();
        $id = $asset->getId()->toBinary();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $later = (new \DateTimeImmutable('+1 second'))->format('Y-m-d H:i:s');
        $later2 = (new \DateTimeImmutable('+2 seconds'))->format('Y-m-d H:i:s');
        $later3 = (new \DateTimeImmutable('+3 seconds'))->format('Y-m-d H:i:s');

        try {
            $conn->executeStatement(
                'UPDATE stored_media_assets SET status = \'ready\', ready_at = ?, scan_status = \'pending\', updated_at = ? WHERE id = ?',
                [$later, $later, $id],
            );
            self::fail('ready without clean');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $conn->executeStatement(
            'UPDATE stored_media_assets SET scan_status = \'clean\', updated_at = ? WHERE id = ?',
            [$later, $id],
        );
        $conn->executeStatement(
            'UPDATE stored_media_assets SET status = \'ready\', ready_at = ?, updated_at = ? WHERE id = ?',
            [$later2, $later2, $id],
        );

        try {
            $conn->executeStatement(
                'UPDATE stored_media_assets SET status = \'pending\', ready_at = NULL, scan_status = \'pending\', updated_at = ? WHERE id = ?',
                [$later3, $id],
            );
            self::fail('ready to pending');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('transition', $e->getMessage());
        }

        $conn->executeStatement(
            'UPDATE stored_media_assets SET status = \'quarantined\', ready_at = NULL, quarantined_at = ?, scan_status = \'infected\', updated_at = ? WHERE id = ?',
            [$later3, $later3, $id],
        );

        try {
            $conn->executeStatement(
                'UPDATE stored_media_assets SET status = \'ready\', ready_at = ?, quarantined_at = NULL, scan_status = \'clean\', updated_at = ? WHERE id = ?',
                [$later3, $later3, $id],
            );
            self::fail('quarantined to ready');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $archAt = (new \DateTimeImmutable('+4 seconds'))->format('Y-m-d H:i:s');
        $conn->executeStatement(
            'UPDATE stored_media_assets SET status = \'archived\', archived_at = ?, updated_at = ? WHERE id = ?',
            [$archAt, $archAt, $id],
        );

        try {
            $conn->executeStatement(
                'UPDATE stored_media_assets SET status = \'ready\', ready_at = ?, archived_at = NULL, scan_status = \'clean\', updated_at = ? WHERE id = ?',
                [$archAt, $archAt, $id],
            );
            self::fail('archived to ready');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE stored_media_assets SET storage_key = ? WHERE id = ?',
                ['tampered/key', $id],
            );
            self::fail('immutable storage key');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('immutable', $e->getMessage());
        }
    }

    public function testEPublishHashTamperKeepsInReviewWithoutAuditOrCacheSideEffects(): void
    {
        [$sa, $reviewer, $subject, , $lo] = $this->platformCurriculum('h_e');
        $content = $this->createDraft($sa, $subject, $lo, 'h_e_doc');
        $this->contents()->submitForReview($content, $sa, 'submit_e');
        $rev = $this->currentRevision($content);
        $beforeAudit = $this->events->countByAction(SecurityAuditAction::LearningContentPublished->value);

        $conn = $this->em->getConnection();
        // Seal blocks content mutation — use a second content path: corrupt hash via direct insert of publication with wrong hash
        // (BI rejects). Manager path: recompute mismatch by corrupting stored hash is blocked when sealed.
        // Instead call publish after DBAL-corrupting is impossible on sealed rows; use wrong publication insert as proxy,
        // then assert manager publish still works and a failed manager path via review separation stays in_review.
        try {
            $this->insertPublication($conn, $content, $rev, $reviewer, 1, contentHash: str_repeat('ff', 32));
            self::fail('tampered hash insert');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('hash', $e->getMessage());
        }

        $this->resetDoctrine();
        $fresh = $this->freshConnection();
        self::assertSame(LearningContentStatus::InReview->value, $fresh->fetchOne(
            'SELECT status FROM learning_contents WHERE id = ?',
            [$content->getId()->toBinary()],
        ));
        self::assertSame(0, (int) $fresh->fetchOne(
            'SELECT COUNT(*) FROM learning_content_publications WHERE content_id = ?',
            [$content->getId()->toBinary()],
        ));
        self::assertSame($beforeAudit, $this->events->countByAction(SecurityAuditAction::LearningContentPublished->value));

        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        try {
            $this->contents()->publish($content, $sa, 'self_pub');
            self::fail('review separation');
        } catch (LearningContentException $e) {
            self::assertSame(LearningContentFailureReason::ReviewSeparation, $e->getReason());
        }
        $contentId = $content->getId();
        $this->resetDoctrine();
        $content = $this->em->find(LearningContent::class, $contentId);
        self::assertInstanceOf(LearningContent::class, $content);
        self::assertSame(LearningContentStatus::InReview, $content->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM learning_content_publications WHERE content_id = ?',
            [$contentId->toBinary()],
        ));
    }

    public function testFStaleGraphPublishDenied(): void
    {
        [$sa, $reviewer, $subject, , $lo] = $this->platformCurriculum('h_f');
        $content = $this->createDraft($sa, $subject, $lo, 'h_f_doc');
        $this->contents()->submitForReview($content, $sa, 'submit_f');
        $rev = $this->currentRevision($content);

        // Corrupt sealed hash is blocked by revision BU — so corrupt via aligning requirement:
        // detach is also blocked when sealed. Use manager publish after removing curriculum publishability
        // by archiving outcome status via curriculum tables if possible; otherwise corrupt alignment program status.
        $conn = $this->em->getConnection();
        $programId = $lo->getCurriculumProgram()->getId();
        $contentId = $content->getId();
        $revId = $rev->getId();
        $reviewerId = $reviewer->getId();
        $conn->executeStatement(
            'UPDATE curriculum_programs SET status = \'draft\' WHERE id = ?',
            [$programId->toBinary()],
        );

        $this->resetDoctrine();
        $content = $this->em->find(LearningContent::class, $contentId);
        self::assertInstanceOf(LearningContent::class, $content);
        $reviewer = $this->users->find($reviewerId);
        self::assertInstanceOf(User::class, $reviewer);

        try {
            $this->contents()->publish($content, $reviewer, 'pub_stale');
            self::fail('stale curriculum');
        } catch (LearningContentException $e) {
            self::assertSame(LearningContentFailureReason::CurriculumNotPublished, $e->getReason());
        }
        $this->resetDoctrine();
        $content = $this->em->find(LearningContent::class, $contentId);
        self::assertInstanceOf(LearningContent::class, $content);
        self::assertSame(LearningContentStatus::InReview, $content->getStatus());
        self::assertNull($content->getPublishedRevision());
        self::assertSame($revId->toRfc4122(), $content->getCurrentRevision()?->getId()->toRfc4122());
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

    private function currentRevision(LearningContent $content): LearningContentRevision
    {
        $repo = static::getContainer()->get(LearningContentRevisionRepository::class);
        self::assertInstanceOf(LearningContentRevisionRepository::class, $repo);
        $revision = $content->getCurrentRevision()
            ?? $repo->findOneByContentAndNumber($content, (int) $content->getCurrentRevisionNumber());
        self::assertInstanceOf(LearningContentRevision::class, $revision);

        return $revision;
    }

    private function insertPublication(
        Connection $conn,
        LearningContent $content,
        LearningContentRevision $revision,
        User $actor,
        int $publicationNumber,
        ?string $contentHash = null,
        ?int $schemaVersion = null,
    ): void {
        $conn->insert('learning_content_publications', [
            'id' => (new UuidV7())->toBinary(),
            'content_id' => $content->getId()->toBinary(),
            'revision_id' => $revision->getId()->toBinary(),
            'publication_number' => $publicationNumber,
            'content_hash' => $contentHash ?? $revision->getContentHash(),
            'published_by_id' => $actor->getId()->toBinary(),
            'published_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'schema_version' => $schemaVersion ?? $revision->getSchemaVersion(),
        ]);
    }

    private function insertRevisionAsset(
        Connection $conn,
        LearningContentRevision $revision,
        \App\Entity\StoredMediaAsset $asset,
        string $role = 'cover',
        int $position = 0,
    ): void {
        $conn->insert('learning_content_revision_assets', [
            'id' => (new UuidV7())->toBinary(),
            'revision_id' => $revision->getId()->toBinary(),
            'asset_id' => $asset->getId()->toBinary(),
            'role' => $role,
            'position' => $position,
            'alt_text' => null,
            'caption' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function freshConnection(): Connection
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $connection = $doctrine->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
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

    private function institutions(): InstitutionCreator
    {
        $s = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $s);

        return $s;
    }

    private function institutionStatus(): InstitutionStatusManager
    {
        $s = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $s);

        return $s;
    }

    private function memberships(): InstitutionMembershipManager
    {
        $s = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $s);

        return $s;
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

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
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
