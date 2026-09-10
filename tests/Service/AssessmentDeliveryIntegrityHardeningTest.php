<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assessment;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentPublication;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\CurriculumProgram;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryFailureReason;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionOrderMode;
use App\Enum\ResultReleasePolicy;
use App\Enum\SecurityAuditAction;
use App\Enum\UserStatus;
use App\Exception\AssessmentDeliveryException;
use App\Repository\AssessmentPublicationRepository;
use App\Repository\QuestionRevisionRepository;
use App\Service\ClassroomCourseManager;
use App\Service\CourseTeacherAssignmentManager;
use App\Tests\Support\AssessmentDeliveryTestFixtures;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Stage 2.10 DB integrity hardening — scenarios A–AJ (DBAL + manager).
 */
final class AssessmentDeliveryIntegrityHardeningTest extends KernelTestCase
{
    use AssessmentDeliveryTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testTriggersHaveNoBypassSessionOrForeignKeyChecks(): void
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME LIKE 'trg_assessment_deliver%'
             ORDER BY TRIGGER_NAME",
        );
        self::assertNotEmpty($rows);
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row['TRIGGER_NAME'];
            $body = (string) $row['ACTION_STATEMENT'];
            self::assertStringNotContainsStringIgnoringCase('@', $body);
            self::assertStringNotContainsStringIgnoringCase('bypass', $body);
            self::assertStringNotContainsStringIgnoringCase('session', $body);
            self::assertStringNotContainsStringIgnoringCase('FOREIGN_KEY_CHECKS', $body);
            self::assertStringNotContainsStringIgnoringCase('testlig', $body);
            self::assertStringNotContainsStringIgnoringCase('test-only', $body);
        }
        self::assertContains('trg_assessment_deliveries_bu_identity', $names);
        self::assertContains('trg_assessment_deliveries_bi_scope', $names);
        self::assertContains('trg_assessment_delivery_recipients_bu_identity', $names);
        self::assertContains('trg_assessment_delivery_recipients_bi_eligibility', $names);

        $lifecycle = (int) $this->em->getConnection()->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.check_constraints
            WHERE constraint_schema = DATABASE()
              AND constraint_name = 'chk_ad_lifecycle_fields'
            SQL);
        self::assertGreaterThan(0, $lifecycle);
    }

    /** A–K delivery lifecycle transitions. */
    public function testDeliveryLifecycleTransitionsAtoK(): void
    {
        $ctx = $this->publishedDeliveryContext('adlc');
        [$opens, $closes] = $this->defaultWindow();
        $conn = $this->em->getConnection();
        $now = $opens->format('Y-m-d H:i:s');
        $later = $closes->format('Y-m-d H:i:s');

        $draft = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Institution,
            null,
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'lc_draft',
        );
        $draftId = $draft->getId()->toBinary();
        $ownerId = $ctx['owner']->getId()->toBinary();

        // A: draft→active with zero eligible recipients
        $this->expectDbalFailure(static function () use ($conn, $draftId, $ownerId, $now): void {
            $conn->executeStatement(
                'UPDATE assessment_deliveries
                 SET status = ?, activated_at = ?, activated_by_id = ?, updated_at = ?
                 WHERE id = ?',
                ['active', $now, $ownerId, $now, $draftId],
            );
        }, 'A activate without recipients');

        // B: active without activated fields (CHECK + trigger)
        $this->expectDbalFailure(static function () use ($conn, $draftId, $now): void {
            $conn->executeStatement(
                'UPDATE assessment_deliveries SET status = ?, updated_at = ? WHERE id = ?',
                ['active', $now, $draftId],
            );
        }, 'B active without activated fields');

        // C: draft→closed
        $this->expectDbalFailure(static function () use ($conn, $draftId, $ownerId, $now): void {
            $conn->executeStatement(
                'UPDATE assessment_deliveries
                 SET status = ?, closed_at = ?, closed_by_id = ?, updated_at = ?
                 WHERE id = ?',
                ['closed', $now, $ownerId, $now, $draftId],
            );
        }, 'C draft to closed');

        $this->insertEligibleInstitutionRecipient($draft, $ctx['studentMembership'], $ctx['student'], $opens);

        // H: valid draft→active
        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = ?, activated_at = ?, activated_by_id = ?, updated_at = ?
             WHERE id = ?',
            ['active', $now, $ownerId, $now, $draftId],
        );
        self::assertSame(
            'active',
            $conn->fetchOne('SELECT status FROM assessment_deliveries WHERE id = ?', [$draftId]),
        );

        // D: active→draft
        $this->expectDbalFailure(static function () use ($conn, $draftId, $now): void {
            $conn->executeStatement(
                'UPDATE assessment_deliveries
                 SET status = ?, activated_at = NULL, activated_by_id = NULL, updated_at = ?
                 WHERE id = ?',
                ['draft', $now, $draftId],
            );
        }, 'D active to draft');

        // G: mutate lifecycle while status unchanged
        $this->expectDbalFailure(static function () use ($conn, $draftId, $later): void {
            $conn->executeStatement(
                'UPDATE assessment_deliveries SET activated_at = ?, updated_at = ? WHERE id = ?',
                [$later, $later, $draftId],
            );
        }, 'G lifecycle mutate while active');

        // I: valid active→closed
        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = ?, closed_at = ?, closed_by_id = ?, updated_at = ?
             WHERE id = ?',
            ['closed', $later, $ownerId, $later, $draftId],
        );
        self::assertSame(
            'closed',
            $conn->fetchOne('SELECT status FROM assessment_deliveries WHERE id = ?', [$draftId]),
        );

        // E: closed→active
        $this->expectDbalFailure(static function () use ($conn, $draftId, $ownerId, $now, $later): void {
            $conn->executeStatement(
                'UPDATE assessment_deliveries
                 SET status = ?, closed_at = NULL, closed_by_id = NULL,
                     activated_at = ?, activated_by_id = ?, updated_at = ?
                 WHERE id = ?',
                ['active', $now, $ownerId, $later, $draftId],
            );
        }, 'E closed to active');

        $draft2 = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Institution,
            null,
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'lc_draft2',
        );
        $draft2Id = $draft2->getId()->toBinary();
        $this->insertEligibleInstitutionRecipient($draft2, $ctx['studentMembership'], $ctx['student'], $opens);

        // J: valid draft→cancelled
        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = ?, cancelled_at = ?, cancelled_by_id = ?, cancellation_reason_code = ?, updated_at = ?
             WHERE id = ?',
            ['cancelled', $now, $ownerId, 'cancel_test', $now, $draft2Id],
        );
        self::assertSame(
            'cancelled',
            $conn->fetchOne('SELECT status FROM assessment_deliveries WHERE id = ?', [$draft2Id]),
        );

        // F: cancelled→active
        $this->expectDbalFailure(static function () use ($conn, $draft2Id, $ownerId, $now): void {
            $conn->executeStatement(
                'UPDATE assessment_deliveries
                 SET status = ?, cancelled_at = NULL, cancelled_by_id = NULL, cancellation_reason_code = NULL,
                     activated_at = ?, activated_by_id = ?, updated_at = ?
                 WHERE id = ?',
                ['active', $now, $ownerId, $now, $draft2Id],
            );
        }, 'F cancelled to active');

        $draft3 = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Institution,
            null,
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'lc_draft3',
        );
        $draft3Id = $draft3->getId()->toBinary();
        $this->insertEligibleInstitutionRecipient($draft3, $ctx['studentMembership'], $ctx['student'], $opens);
        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = ?, activated_at = ?, activated_by_id = ?, updated_at = ?
             WHERE id = ?',
            ['active', $now, $ownerId, $now, $draft3Id],
        );

        // K: valid active→cancelled
        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = ?, cancelled_at = ?, cancelled_by_id = ?, cancellation_reason_code = ?, updated_at = ?
             WHERE id = ?',
            ['cancelled', $later, $ownerId, 'cancel_active', $later, $draft3Id],
        );
        self::assertSame(
            'cancelled',
            $conn->fetchOne('SELECT status FROM assessment_deliveries WHERE id = ?', [$draft3Id]),
        );
    }

    /** L–Y recipient insert/update rules. */
    public function testRecipientInsertAndUpdateLtoY(): void
    {
        $ctx = $this->publishedDeliveryContext('adrp');
        [$opens, $closes] = $this->defaultWindow();
        $conn = $this->em->getConnection();
        $assigned = $opens->format('Y-m-d H:i:s');

        $instDraft = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Institution,
            null,
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'rp_inst',
        );
        $clsDraft = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $ctx['classroom'],
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'rp_cls',
        );
        $stuDraft = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Student,
            null,
            $ctx['studentMembership'],
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'rp_stu',
        );

        $enrollment = $this->em->getRepository(ClassroomStudentEnrollment::class)->findOneBy([
            'classroom' => $ctx['classroom'],
            'studentMembership' => $ctx['studentMembership'],
        ]);
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $enrollment);

        // L: teacher/staff membership
        $this->expectDbalFailure(function () use ($instDraft, $ctx, $assigned): void {
            $this->insertRecipientRow(
                $instDraft,
                $ctx['teacherMembership'],
                $ctx['teacher'],
                null,
                null,
                $assigned,
            );
        }, 'L teacher membership');

        // M: inactive membership
        $conn->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            ['suspended', $ctx['studentMembership']->getId()->toBinary()],
        );
        $this->expectDbalFailure(function () use ($instDraft, $ctx, $assigned): void {
            $this->insertRecipientRow(
                $instDraft,
                $ctx['studentMembership'],
                $ctx['student'],
                null,
                null,
                $assigned,
            );
        }, 'M inactive membership');
        $conn->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            ['active', $ctx['studentMembership']->getId()->toBinary()],
        );

        // N: inactive user
        $conn->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $ctx['student']->getId()->toBinary()],
        );
        $this->expectDbalFailure(function () use ($instDraft, $ctx, $assigned): void {
            $this->insertRecipientRow(
                $instDraft,
                $ctx['studentMembership'],
                $ctx['student'],
                null,
                null,
                $assigned,
            );
        }, 'N inactive user');
        $conn->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Active->value, $ctx['student']->getId()->toBinary()],
        );

        // O: unverified user
        $conn->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$ctx['student']->getId()->toBinary()],
        );
        $this->expectDbalFailure(function () use ($instDraft, $ctx, $assigned): void {
            $this->insertRecipientRow(
                $instDraft,
                $ctx['studentMembership'],
                $ctx['student'],
                null,
                null,
                $assigned,
            );
        }, 'O unverified user');
        $conn->executeStatement(
            'UPDATE users SET email_verified_at = ? WHERE id = ?',
            [$assigned, $ctx['student']->getId()->toBinary()],
        );

        // P: other institution membership
        $otherOwner = $this->activeUser('adrp-other-owner@example.com');
        $otherInst = $this->institutionCreator()->create(
            $ctx['sa'],
            $otherOwner,
            'adrp Other School',
            InstitutionType::School,
            'platform_setup',
        );
        $this->institutionStatus()->activate($otherInst, $ctx['sa'], 'activate');
        $otherStudent = $this->activeUser('adrp-other-stu@example.com');
        $otherMembership = $this->membershipManager()->addMember(
            $otherInst,
            $otherOwner,
            $otherStudent,
            InstitutionMembershipRole::Student,
            'add_other',
        );
        $this->expectDbalFailure(function () use ($instDraft, $otherMembership, $otherStudent, $assigned): void {
            $this->insertRecipientRow($instDraft, $otherMembership, $otherStudent, null, null, $assigned);
        }, 'P other institution membership');

        // Q: classroom delivery with other classroom source (same institution)
        $otherClassroom = $this->classroomManager()->create(
            $ctx['classroom']->getAcademicYear(),
            $ctx['owner'],
            'adrp 9-B',
            GradeLevel::Grade9,
            'cls_b',
            'B',
            40,
        );
        $this->expectDbalFailure(function () use ($clsDraft, $ctx, $otherClassroom, $enrollment, $assigned): void {
            $this->insertRecipientRow(
                $clsDraft,
                $ctx['studentMembership'],
                $ctx['student'],
                $otherClassroom,
                $enrollment,
                $assigned,
            );
        }, 'Q other classroom source');

        // R: other enrollment (enrollment from wrong classroom lineage — use null enrollment id spoof)
        $fakeEnrollmentId = Uuid::v7();
        $this->expectDbalFailure(function () use ($clsDraft, $ctx, $fakeEnrollmentId, $assigned): void {
            $conn = $this->em->getConnection();
            $conn->insert('assessment_delivery_recipients', [
                'id' => Uuid::v7()->toBinary(),
                'delivery_id' => $clsDraft->getId()->toBinary(),
                'institution_id' => $clsDraft->getInstitution()->getId()->toBinary(),
                'student_membership_id' => $ctx['studentMembership']->getId()->toBinary(),
                'user_id' => $ctx['student']->getId()->toBinary(),
                'status' => 'eligible',
                'source_classroom_id' => $ctx['classroom']->getId()->toBinary(),
                'source_enrollment_id' => $fakeEnrollmentId->toBinary(),
                'assigned_at' => $assigned,
                'revoked_at' => null,
                'revoked_by_id' => null,
                'revocation_reason_code' => null,
            ]);
        }, 'R fake enrollment');

        // S: institution audience with source classroom/enrollment
        $this->expectDbalFailure(function () use ($instDraft, $ctx, $enrollment, $assigned): void {
            $this->insertRecipientRow(
                $instDraft,
                $ctx['studentMembership'],
                $ctx['student'],
                $ctx['classroom'],
                $enrollment,
                $assigned,
            );
        }, 'S institution with sources');

        // T: student audience with sources
        $this->expectDbalFailure(function () use ($stuDraft, $ctx, $enrollment, $assigned): void {
            $this->insertRecipientRow(
                $stuDraft,
                $ctx['studentMembership'],
                $ctx['student'],
                $ctx['classroom'],
                $enrollment,
                $assigned,
            );
        }, 'T student with sources');

        // U: student audience different membership
        $student2 = $this->activeUser('adrp-s2@example.com');
        $membership2 = $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $student2,
            InstitutionMembershipRole::Student,
            'add_s2',
        );
        $this->expectDbalFailure(function () use ($stuDraft, $membership2, $student2, $assigned): void {
            $this->insertRecipientRow($stuDraft, $membership2, $student2, null, null, $assigned);
        }, 'U student audience wrong membership');

        // Y: valid classroom / institution / student recipients
        $this->insertRecipientRow($instDraft, $ctx['studentMembership'], $ctx['student'], null, null, $assigned);
        $this->insertRecipientRow(
            $clsDraft,
            $ctx['studentMembership'],
            $ctx['student'],
            $ctx['classroom'],
            $enrollment,
            $assigned,
        );
        $this->insertRecipientRow($stuDraft, $ctx['studentMembership'], $ctx['student'], null, null, $assigned);

        $recipientId = $conn->fetchOne(
            'SELECT id FROM assessment_delivery_recipients WHERE delivery_id = ? LIMIT 1',
            [$stuDraft->getId()->toBinary()],
        );
        self::assertNotFalse($recipientId);

        // X: identity/source mutation
        $this->expectDbalFailure(static function () use ($conn, $recipientId, $ctx): void {
            $conn->executeStatement(
                'UPDATE assessment_delivery_recipients SET user_id = ? WHERE id = ?',
                [$ctx['teacher']->getId()->toBinary(), $recipientId],
            );
        }, 'X identity mutation');

        // W: revoked→eligible
        $conn->executeStatement(
            'UPDATE assessment_delivery_recipients
             SET status = ?, revoked_at = ?, revoked_by_id = ?, revocation_reason_code = ?
             WHERE id = ?',
            ['revoked', $assigned, $ctx['owner']->getId()->toBinary(), 'revoke_test', $recipientId],
        );
        $this->expectDbalFailure(static function () use ($conn, $recipientId): void {
            $conn->executeStatement(
                'UPDATE assessment_delivery_recipients
                 SET status = ?, revoked_at = NULL, revoked_by_id = NULL, revocation_reason_code = NULL
                 WHERE id = ?',
                ['eligible', $recipientId],
            );
        }, 'W revoked to eligible');

        // V: closed/cancelled delivery recipient INSERT
        $ownerBin = $ctx['owner']->getId()->toBinary();
        $closedAt = $closes->format('Y-m-d H:i:s');
        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = ?, activated_at = ?, activated_by_id = ?, updated_at = ?
             WHERE id = ?',
            ['active', $assigned, $ownerBin, $assigned, $instDraft->getId()->toBinary()],
        );
        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = ?, closed_at = ?, closed_by_id = ?, updated_at = ?
             WHERE id = ?',
            ['closed', $closedAt, $ownerBin, $closedAt, $instDraft->getId()->toBinary()],
        );
        $this->expectDbalFailure(function () use ($instDraft, $membership2, $student2, $assigned): void {
            $this->insertRecipientRow($instDraft, $membership2, $student2, null, null, $assigned);
        }, 'V closed delivery insert');

        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = ?, cancelled_at = ?, cancelled_by_id = ?, cancellation_reason_code = ?, updated_at = ?
             WHERE id = ?',
            ['cancelled', $assigned, $ownerBin, 'cancel_v', $assigned, $clsDraft->getId()->toBinary()],
        );
        $this->expectDbalFailure(function () use ($clsDraft, $membership2, $student2, $ctx, $enrollment, $assigned): void {
            $this->insertRecipientRow(
                $clsDraft,
                $membership2,
                $student2,
                $ctx['classroom'],
                $enrollment,
                $assigned,
            );
        }, 'V cancelled delivery insert');
    }

    /** Z–AE delivery scope insert rules. */
    public function testDeliveryScopeInsertZtoAE(): void
    {
        $ctx = $this->publishedDeliveryContext('adsc');
        [$opens, $closes] = $this->defaultWindow();
        $conn = $this->em->getConnection();

        // AE: platform publication on active institution accepted
        $okId = Uuid::v7()->toBinary();
        $this->insertDraftDeliveryRow(
            $okId,
            $ctx['institution'],
            $ctx['assessment'],
            $ctx['publication'],
            'institution',
            null,
            null,
            $ctx['owner'],
            $opens,
            $closes,
        );
        self::assertSame(
            1,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_deliveries WHERE id = ?', [$okId]),
        );

        // AD: fake publication number / mismatch
        $this->expectDbalFailure(function () use ($ctx, $opens, $closes): void {
            $this->insertDraftDeliveryRow(
                Uuid::v7()->toBinary(),
                $ctx['institution'],
                $ctx['assessment'],
                $ctx['publication'],
                'institution',
                null,
                null,
                $ctx['owner'],
                $opens,
                $closes,
                publicationNumberOverride: 999,
            );
        }, 'AD spoof publication number');

        // AA: archived assessment
        $this->assessments()->archive($ctx['assessment'], $ctx['sa'], 'arch_aa');
        $this->expectDbalFailure(function () use ($ctx, $opens, $closes): void {
            $this->insertDraftDeliveryRow(
                Uuid::v7()->toBinary(),
                $ctx['institution'],
                $ctx['assessment'],
                $ctx['publication'],
                'institution',
                null,
                null,
                $ctx['owner'],
                $opens,
                $closes,
            );
        }, 'AA archived assessment');

        // Fresh published context for remaining scope tests
        $this->cleanupDeliveryFixtures();
        $ctx = $this->publishedDeliveryContext('adsc2');
        [$opens, $closes] = $this->defaultWindow();

        // AB: inactive classroom
        $this->classroomManager()->archive($ctx['classroom'], $ctx['owner'], 'arch_cls');
        $this->expectDbalFailure(function () use ($ctx, $opens, $closes): void {
            $this->insertDraftDeliveryRow(
                Uuid::v7()->toBinary(),
                $ctx['institution'],
                $ctx['assessment'],
                $ctx['publication'],
                'classroom',
                $ctx['classroom'],
                null,
                $ctx['owner'],
                $opens,
                $closes,
            );
        }, 'AB inactive classroom');

        // AC: closed academic year (keep classroom row; force year closed via DBAL)
        $this->cleanupDeliveryFixtures();
        $ctx = $this->publishedDeliveryContext('adsc3');
        [$opens, $closes] = $this->defaultWindow();
        $yearId = $ctx['classroom']->getAcademicYear()->getId()->toBinary();
        $conn->executeStatement('UPDATE academic_years SET status = ? WHERE id = ?', ['closed', $yearId]);
        $this->expectDbalFailure(function () use ($ctx, $opens, $closes): void {
            $this->insertDraftDeliveryRow(
                Uuid::v7()->toBinary(),
                $ctx['institution'],
                $ctx['assessment'],
                $ctx['publication'],
                'classroom',
                $ctx['classroom'],
                null,
                $ctx['owner'],
                $opens,
                $closes,
            );
        }, 'AC closed academic year');

        // Z: institution-scoped assessment used by another tenant
        $this->cleanupDeliveryFixtures();
        $ctx = $this->publishedDeliveryContext('adsc4');
        [$opens, $closes] = $this->defaultWindow();
        [$instAssessment, $instPublication] = $this->publishInstitutionAssessment($ctx, 'adsc4i');
        $otherOwner = $this->activeUser('adsc4-other@example.com');
        $otherInst = $this->institutionCreator()->create(
            $ctx['sa'],
            $otherOwner,
            'adsc4 Other',
            InstitutionType::School,
            'platform_setup',
        );
        $this->institutionStatus()->activate($otherInst, $ctx['sa'], 'activate');
        $this->expectDbalFailure(function () use ($otherInst, $instAssessment, $instPublication, $otherOwner, $opens, $closes): void {
            $this->insertDraftDeliveryRow(
                Uuid::v7()->toBinary(),
                $otherInst,
                $instAssessment,
                $instPublication,
                'institution',
                null,
                null,
                $otherOwner,
                $opens,
                $closes,
            );
        }, 'Z institution assessment cross-tenant');
    }

    /** AF–AJ activation rollback integrity. */
    public function testActivationRollbackAFtoAJ(): void
    {
        $ctx = $this->publishedDeliveryContext('adrb');
        [$opens, $closes] = $this->defaultWindow();
        $assigned = $opens->format('Y-m-d H:i:s');

        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $ctx['classroom'],
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'rb_cls',
        );
        $enrollment = $this->em->getRepository(ClassroomStudentEnrollment::class)->findOneBy([
            'classroom' => $ctx['classroom'],
            'studentMembership' => $ctx['studentMembership'],
        ]);
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $enrollment);

        // Pre-insert the only eligible recipient so activate materialization hits UNIQUE.
        $this->insertRecipientRow(
            $delivery,
            $ctx['studentMembership'],
            $ctx['student'],
            $ctx['classroom'],
            $enrollment,
            $assigned,
        );
        $beforeCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = ?',
            [$delivery->getId()->toBinary()],
        );
        self::assertSame(1, $beforeCount);
        $auditBefore = $this->events->countByAction(SecurityAuditAction::AssessmentDeliveryActivated->value);

        try {
            $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_rb');
            self::fail('AF activate should fail on duplicate recipient');
        } catch (\Throwable $e) {
            self::assertTrue(
                $e instanceof AssessmentDeliveryException || $e instanceof DbalException || $e instanceof \RuntimeException,
                $e::class.': '.$e->getMessage(),
            );
        }

        $this->resetDoctrineDelivery();
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);

        // AG: stays draft
        self::assertSame(AssessmentDeliveryStatus::Draft, $delivery->getStatus());

        // AH: no half materialization from failed activate
        $afterCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = ?',
            [$delivery->getId()->toBinary()],
        );
        self::assertSame(1, $afterCount);

        // AI: no success audit
        self::assertSame(
            $auditBefore,
            $this->events->countByAction(SecurityAuditAction::AssessmentDeliveryActivated->value),
        );

        // AJ: failed activate must not leave delivery active (proxy for no post-commit cache side effects)
        self::assertNull($delivery->getActivatedAt());
        self::assertNull($delivery->getActivatedBy());
    }

    public function testTeacherFreshAuthorizationStaleDenials(): void
    {
        [$opens, $closes] = $this->defaultWindow();

        $ctx = $this->publishedDeliveryContext('tchsus');
        $this->assertTeacherClassroomDraftAllowed($ctx, $opens, $closes);
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            ['suspended', $ctx['teacherMembership']->getId()->toBinary()],
        );
        $this->assertTeacherClassroomDraftDenied($ctx, $opens, $closes, 'teacher membership suspended');
        $this->resetDoctrineDelivery();

        $ctx = $this->publishedDeliveryContext('tchstaff');
        $this->assertTeacherClassroomDraftAllowed($ctx, $opens, $closes);
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET role = ? WHERE id = ?',
            ['staff', $ctx['teacherMembership']->getId()->toBinary()],
        );
        $this->assertTeacherClassroomDraftDenied($ctx, $opens, $closes, 'teacher role staff');
        $this->resetDoctrineDelivery();

        $ctx = $this->publishedDeliveryContext('tchasn');
        $this->assertTeacherClassroomDraftAllowed($ctx, $opens, $closes);
        $this->em->getConnection()->executeStatement(
            'UPDATE classroom_teacher_assignments SET status = ? WHERE classroom_id = ? AND teacher_membership_id = ?',
            [
                'ended',
                $ctx['classroom']->getId()->toBinary(),
                $ctx['teacherMembership']->getId()->toBinary(),
            ],
        );
        $this->assertTeacherClassroomDraftDenied($ctx, $opens, $closes, 'assignment ended');
        $this->resetDoctrineDelivery();

        $ctx = $this->publishedDeliveryContext('tchcls');
        $this->assertTeacherClassroomDraftAllowed($ctx, $opens, $closes);
        $this->em->getConnection()->executeStatement(
            'UPDATE classrooms SET status = ? WHERE id = ?',
            ['archived', $ctx['classroom']->getId()->toBinary()],
        );
        $this->assertTeacherClassroomDraftDenied($ctx, $opens, $closes, 'classroom archived');
        $this->resetDoctrineDelivery();

        $ctx = $this->publishedDeliveryContext('tchcrs');
        $this->em->getConnection()->executeStatement(
            'UPDATE classroom_teacher_assignments SET status = ? WHERE classroom_id = ? AND teacher_membership_id = ?',
            [
                'ended',
                $ctx['classroom']->getId()->toBinary(),
                $ctx['teacherMembership']->getId()->toBinary(),
            ],
        );
        $subject = $this->subjects()->create($ctx['sa'], 'tchcrs_subj', 'Tch Course Subj', 'create_subj');
        $program = $this->programs()->createDraft(
            $subject,
            $ctx['sa'],
            GradeLevel::Grade9,
            'tchcrs_code',
            'Tch Course Prog',
            '1.0',
            'prog',
        );
        $this->programs()->publish($program, $ctx['sa'], 'pub_curr');
        $program = $this->em->find(CurriculumProgram::class, $program->getId());
        self::assertInstanceOf(CurriculumProgram::class, $program);
        $subject = $this->em->find(Subject::class, $subject->getId());
        self::assertInstanceOf(Subject::class, $subject);
        $classroom = $this->em->find(Classroom::class, $ctx['classroom']->getId());
        self::assertInstanceOf(Classroom::class, $classroom);
        $owner = $this->users->find($ctx['owner']->getId());
        self::assertInstanceOf(User::class, $owner);
        /** @var ClassroomCourseManager $courseManager */
        $courseManager = static::getContainer()->get(ClassroomCourseManager::class);
        $course = $courseManager->create($classroom, $owner, $subject, $program, 'create_course', 3);
        $teacherMembership = $this->em->find(InstitutionMembership::class, $ctx['teacherMembership']->getId());
        self::assertInstanceOf(InstitutionMembership::class, $teacherMembership);
        /** @var CourseTeacherAssignmentManager $courseTeacherManager */
        $courseTeacherManager = static::getContainer()->get(CourseTeacherAssignmentManager::class);
        $courseTeacherManager->assign($course, $owner, $teacherMembership, 'assign_ct');
        $this->assertTeacherClassroomDraftAllowed($ctx, $opens, $closes);
        $this->em->getConnection()->executeStatement(
            'UPDATE classroom_courses SET status = ? WHERE id = ?',
            ['archived', $course->getId()->toBinary()],
        );
        $this->assertTeacherClassroomDraftDenied($ctx, $opens, $closes, 'course archived');
        $this->resetDoctrineDelivery();

        $ctx = $this->publishedDeliveryContext('tchstx');
        $otherOwner = $this->activeUser('tchstx-oo@example.com');
        $other = $this->institutionCreator()->create(
            $ctx['sa'],
            $otherOwner,
            'Other School TX',
            InstitutionType::School,
            'platform_setup',
        );
        $this->institutionStatus()->activate($other, $ctx['sa'], 'activate');
        $foreignStudent = $this->activeUser('tchstx-fs@example.com');
        $foreignMembership = $this->membershipManager()->addMember(
            $other,
            $otherOwner,
            $foreignStudent,
            InstitutionMembershipRole::Student,
            'add_fs',
        );
        $this->assertTeacherStudentDraftDenied($ctx, $foreignMembership, $opens, $closes, 'student other tenant');
        $this->resetDoctrineDelivery();

        $ctx = $this->publishedDeliveryContext('tchenr');
        $this->assertTeacherStudentDraftAllowed($ctx, $opens, $closes);
        $this->em->getConnection()->executeStatement(
            'UPDATE classroom_student_enrollments SET status = ?
             WHERE classroom_id = ? AND student_membership_id = ?',
            [
                'ended',
                $ctx['classroom']->getId()->toBinary(),
                $ctx['studentMembership']->getId()->toBinary(),
            ],
        );
        $this->assertTeacherStudentDraftDenied($ctx, $ctx['studentMembership'], $opens, $closes, 'student enrollment ended');
    }

    /**
     * @param array{
     *     owner: User,
     *     institution: Institution,
     *     classroom: Classroom,
     *     teacher: User,
     *     teacherMembership: InstitutionMembership,
     *     student: User,
     *     studentMembership: InstitutionMembership,
     *     publication: AssessmentPublication
     * } $ctx
     */
    private function assertTeacherClassroomDraftAllowed(array $ctx, \DateTimeImmutable $opens, \DateTimeImmutable $closes): void
    {
        $teacher = $this->users->find($ctx['teacher']->getId());
        self::assertInstanceOf(User::class, $teacher);
        $institution = $this->em->find(Institution::class, $ctx['institution']->getId());
        self::assertInstanceOf(Institution::class, $institution);
        $classroom = $this->em->find(Classroom::class, $ctx['classroom']->getId());
        self::assertInstanceOf(Classroom::class, $classroom);
        $publication = $this->em->find(AssessmentPublication::class, $ctx['publication']->getId());
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        $delivery = $this->deliveries()->createDraft(
            $institution,
            $publication,
            AssessmentDeliveryAudienceType::Classroom,
            $classroom,
            null,
            $teacher,
            $opens,
            $closes,
            1,
            null,
            null,
            'teacher_ok',
        );
        self::assertSame(AssessmentDeliveryStatus::Draft, $delivery->getStatus());
    }

    /**
     * @param array{
     *     institution: Institution,
     *     classroom: Classroom,
     *     teacher: User,
     *     publication: AssessmentPublication
     * } $ctx
     */
    private function assertTeacherClassroomDraftDenied(
        array $ctx,
        \DateTimeImmutable $opens,
        \DateTimeImmutable $closes,
        string $label,
    ): void {
        $teacher = $this->users->find($ctx['teacher']->getId());
        self::assertInstanceOf(User::class, $teacher);
        $institution = $this->em->find(Institution::class, $ctx['institution']->getId());
        self::assertInstanceOf(Institution::class, $institution);
        $classroom = $this->em->find(Classroom::class, $ctx['classroom']->getId());
        self::assertInstanceOf(Classroom::class, $classroom);
        $publication = $this->em->find(AssessmentPublication::class, $ctx['publication']->getId());
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        try {
            $this->deliveries()->createDraft(
                $institution,
                $publication,
                AssessmentDeliveryAudienceType::Classroom,
                $classroom,
                null,
                $teacher,
                $opens,
                $closes,
                1,
                null,
                null,
                'teacher_denied',
            );
            self::fail($label);
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::Unauthorized, $e->getReason(), $label);
        }
    }

    /**
     * @param array{
     *     institution: Institution,
     *     teacher: User,
     *     studentMembership: InstitutionMembership,
     *     publication: AssessmentPublication
     * } $ctx
     */
    private function assertTeacherStudentDraftAllowed(array $ctx, \DateTimeImmutable $opens, \DateTimeImmutable $closes): void
    {
        $teacher = $this->users->find($ctx['teacher']->getId());
        self::assertInstanceOf(User::class, $teacher);
        $institution = $this->em->find(Institution::class, $ctx['institution']->getId());
        self::assertInstanceOf(Institution::class, $institution);
        $membership = $this->em->find(InstitutionMembership::class, $ctx['studentMembership']->getId());
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $publication = $this->em->find(AssessmentPublication::class, $ctx['publication']->getId());
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        $delivery = $this->deliveries()->createDraft(
            $institution,
            $publication,
            AssessmentDeliveryAudienceType::Student,
            null,
            $membership,
            $teacher,
            $opens,
            $closes,
            1,
            null,
            null,
            'teacher_student_ok',
        );
        self::assertSame(AssessmentDeliveryStatus::Draft, $delivery->getStatus());
    }

    /**
     * @param array{
     *     institution: Institution,
     *     teacher: User,
     *     publication: AssessmentPublication
     * } $ctx
     */
    private function assertTeacherStudentDraftDenied(
        array $ctx,
        InstitutionMembership $studentMembership,
        \DateTimeImmutable $opens,
        \DateTimeImmutable $closes,
        string $label,
    ): void {
        $teacher = $this->users->find($ctx['teacher']->getId());
        self::assertInstanceOf(User::class, $teacher);
        $institution = $this->em->find(Institution::class, $ctx['institution']->getId());
        self::assertInstanceOf(Institution::class, $institution);
        $membership = $this->em->find(InstitutionMembership::class, $studentMembership->getId());
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $publication = $this->em->find(AssessmentPublication::class, $ctx['publication']->getId());
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        try {
            $this->deliveries()->createDraft(
                $institution,
                $publication,
                AssessmentDeliveryAudienceType::Student,
                null,
                $membership,
                $teacher,
                $opens,
                $closes,
                1,
                null,
                null,
                'teacher_student_denied',
            );
            self::fail($label);
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::Unauthorized, $e->getReason(), $label);
        }
    }

    /**
     * @param array{
     *     owner: User,
     *     sa: User,
     *     reviewer: User,
     *     institution: Institution
     * } $ctx
     *
     * @return array{0: Assessment, 1: AssessmentPublication}
     */
    private function publishInstitutionAssessment(array $ctx, string $suffix): array
    {
        $subject = $this->subjects()->create($ctx['sa'], 'math_'.$suffix, 'Math '.$suffix, 'create_subj');
        $draft = $this->programs()->createDraft($subject, $ctx['sa'], GradeLevel::Grade9, 'math_'.$suffix, 'Math', '1.0', 'prog');
        $unit = $this->units()->create($draft, $ctx['sa'], 'u1', 'Unit', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $ctx['sa'], 't1', 'Topic', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $ctx['sa'], 'lo_'.$suffix, 'Outcome', 1, 'create_lo');
        $this->programs()->publish($draft, $ctx['sa'], 'pub_curr');

        $question = $this->createPublishedPlatformQuestion($ctx['sa'], $ctx['reviewer'], $subject, $lo, $suffix);
        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qRevision = $qRevisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $qRevision);

        $assessment = $this->assessments()->createDraftAssessment(
            $ctx['owner'],
            AssessmentScope::Institution,
            $ctx['institution'],
            AssessmentType::Quiz,
            GradeLevel::Grade9,
            'Institution Blueprint '.$suffix,
            null,
            null,
            3600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [$this->sectionWithItem($question, $qRevision)],
            'create_ia',
        );
        $this->assessments()->submitForReview($assessment, $ctx['owner'], 'submit_ia');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $publisher = $this->users->find($ctx['sa']->getId());
        self::assertInstanceOf(User::class, $publisher);
        $this->assessments()->publish($assessment, $publisher, 'publish_ia');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

        /** @var AssessmentPublicationRepository $pubs */
        $pubs = static::getContainer()->get(AssessmentPublicationRepository::class);
        $publication = $pubs->findOneBy(['assessment' => $assessment, 'publicationNumber' => 1]);
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        return [$assessment, $publication];
    }

    private function insertEligibleInstitutionRecipient(
        AssessmentDelivery $delivery,
        InstitutionMembership $membership,
        User $user,
        \DateTimeImmutable $assignedAt,
    ): void {
        $this->insertRecipientRow($delivery, $membership, $user, null, null, $assignedAt->format('Y-m-d H:i:s'));
    }

    private function insertRecipientRow(
        AssessmentDelivery $delivery,
        InstitutionMembership $membership,
        User $user,
        ?Classroom $sourceClassroom,
        ?ClassroomStudentEnrollment $sourceEnrollment,
        string $assignedAt,
    ): void {
        $this->em->getConnection()->insert('assessment_delivery_recipients', [
            'id' => Uuid::v7()->toBinary(),
            'delivery_id' => $delivery->getId()->toBinary(),
            'institution_id' => $delivery->getInstitution()->getId()->toBinary(),
            'student_membership_id' => $membership->getId()->toBinary(),
            'user_id' => $user->getId()->toBinary(),
            'status' => 'eligible',
            'source_classroom_id' => $sourceClassroom?->getId()->toBinary(),
            'source_enrollment_id' => $sourceEnrollment?->getId()->toBinary(),
            'assigned_at' => $assignedAt,
            'revoked_at' => null,
            'revoked_by_id' => null,
            'revocation_reason_code' => null,
        ]);
    }

    private function insertDraftDeliveryRow(
        string $id,
        Institution $institution,
        Assessment $assessment,
        AssessmentPublication $publication,
        string $audienceType,
        ?Classroom $classroom,
        ?InstitutionMembership $studentMembership,
        User $createdBy,
        \DateTimeImmutable $opens,
        \DateTimeImmutable $closes,
        ?int $publicationNumberOverride = null,
    ): void {
        $this->em->getConnection()->insert('assessment_deliveries', [
            'id' => $id,
            'institution_id' => $institution->getId()->toBinary(),
            'assessment_id' => $assessment->getId()->toBinary(),
            'assessment_publication_id' => $publication->getId()->toBinary(),
            'publication_number' => $publicationNumberOverride ?? $publication->getPublicationNumber(),
            'audience_type' => $audienceType,
            'classroom_id' => $classroom?->getId()->toBinary(),
            'student_membership_id' => $studentMembership?->getId()->toBinary(),
            'status' => 'draft',
            'opens_at' => $opens->format('Y-m-d H:i:s'),
            'closes_at' => $closes->format('Y-m-d H:i:s'),
            'max_attempts' => 1,
            'title_override' => null,
            'instructions_override' => null,
            'created_by_id' => $createdBy->getId()->toBinary(),
            'activated_by_id' => null,
            'activated_at' => null,
            'closed_by_id' => null,
            'closed_at' => null,
            'cancelled_by_id' => null,
            'cancelled_at' => null,
            'cancellation_reason_code' => null,
            'created_at' => $opens->format('Y-m-d H:i:s'),
            'updated_at' => $opens->format('Y-m-d H:i:s'),
        ]);
    }

    private function expectDbalFailure(callable $callback, string $label): void
    {
        try {
            $callback();
            self::fail($label);
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage(), $label);
        }
    }
}
