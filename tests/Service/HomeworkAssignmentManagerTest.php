<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkDefinition;
use App\Enum\GradeLevel;
use App\Enum\HomeworkAssignmentStatus;
use App\Enum\HomeworkDefinitionStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\HomeworkDefinitionException;
use App\Tests\Support\HomeworkTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Classroom assign + recipient snapshot. The clock is frozen at 2026-11-10 09:00 UTC and the
 * default draft is due 2026-11-12 18:30 Europe/Istanbul (15:30 UTC).
 */
final class HomeworkAssignmentManagerTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        if (isset($this->em)) {
            $this->cleanupHomework();
        }
        parent::tearDown();
    }

    public function testAuthorizedAssignFreezesTextAndSnapshotsEveryEligibleStudent(): void
    {
        $ctx = $this->homeworkContext('hwa-ok');
        $students = $this->enrollStudents($ctx, 3);
        $this->freezeClock();
        $definition = $this->draft($ctx);

        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);

        self::assertSame(HomeworkAssignmentStatus::Assigned, $assignment->getStatus());
        self::assertSame(3, $assignment->getRecipientCount());
        self::assertSame('Kesirler çalışma kâğıdı', $assignment->getTitle());
        self::assertSame('Sayfa 12 ve 13’teki soruları çöz.', $assignment->getDescription());
        self::assertSame('2026-11-12 15:30:00', $assignment->getDueAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-10 09:00:00', $assignment->getAssignedAt()->format('Y-m-d H:i:s'));
        self::assertTrue($assignment->getDefinition()->getId()->equals($definition->getId()));
        self::assertTrue($assignment->getClassroom()->getId()->equals($ctx['classroom']->getId()));
        self::assertTrue($assignment->getInstitution()->getId()->equals($ctx['institution']->getId()));
        self::assertTrue($assignment->getAcademicYear()->getId()->equals($ctx['year']->getId()));
        self::assertTrue($assignment->getAssignedByUser()->getId()->equals($ctx['teacher']->getId()));
        self::assertTrue($assignment->getAssignedByMembership()->getId()->equals($ctx['teacherMembership']->getId()));

        // Stored values, straight from the database.
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT title, description, due_at, status, recipient_count FROM homework_assignments WHERE id = :id',
            ['id' => $assignment->getId()->toBinary()],
        );
        self::assertSame([
            'title' => 'Kesirler çalışma kâğıdı',
            'description' => 'Sayfa 12 ve 13’teki soruları çöz.',
            'due_at' => '2026-11-12 15:30:00',
            'status' => 'assigned',
            'recipient_count' => 3,
        ], $row);

        // One recipient per eligible student, tied to membership, user and enrollment.
        self::assertSame(3, $this->recipientRowCount($assignment->getId()));
        $recipientMemberships = $this->em->getConnection()->fetchFirstColumn(
            'SELECT student_membership_id FROM homework_recipients WHERE assignment_id = :id',
            ['id' => $assignment->getId()->toBinary()],
        );
        $expected = array_map(static fn ($membership): string => $membership->getId()->toBinary(), $students);
        sort($expected, \SORT_STRING);
        sort($recipientMemberships, \SORT_STRING);
        self::assertSame($expected, $recipientMemberships);
        $recipientUsers = $this->em->getConnection()->fetchFirstColumn(
            'SELECT student_user_id FROM homework_recipients WHERE assignment_id = :id',
            ['id' => $assignment->getId()->toBinary()],
        );
        $expectedUsers = array_map(static fn ($membership): string => $membership->getUser()->getId()->toBinary(), $students);
        sort($expectedUsers, \SORT_STRING);
        sort($recipientUsers, \SORT_STRING);
        self::assertSame($expectedUsers, $recipientUsers);
        $enrollments = [];
        foreach ($students as $membership) {
            $enrollments[] = $this->activeEnrollment($ctx['classroom'], $membership)->getId()->toBinary();
        }
        $recipientEnrollments = $this->em->getConnection()->fetchFirstColumn(
            'SELECT enrollment_id FROM homework_recipients WHERE assignment_id = :id',
            ['id' => $assignment->getId()->toBinary()],
        );
        sort($enrollments, \SORT_STRING);
        sort($recipientEnrollments, \SORT_STRING);
        self::assertSame($enrollments, $recipientEnrollments);

        // Definition stays a draft row; "Atandı" comes from the assignment, not from status.
        $this->em->clear();
        $stored = $this->em->find(HomeworkDefinition::class, $definition->getId());
        self::assertInstanceOf(HomeworkDefinition::class, $stored);
        self::assertSame(HomeworkDefinitionStatus::Draft, $stored->getStatus());
        self::assertSame(1, $stored->getRevisionNumber());
    }

    public function testSuccessAuditCarriesMetadataOnlyAndNoHomeworkText(): void
    {
        $ctx = $this->homeworkContext('hwa-audit');
        $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);

        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);

        $audit = $this->assignmentAuditRows();
        self::assertCount(1, $audit);
        $metadata = json_decode($audit[0]['metadata'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame($assignment->getId()->toRfc4122(), $metadata['resource_id']);
        self::assertSame($definition->getId()->toRfc4122(), $metadata['definition_id']);
        self::assertSame($ctx['classroom']->getId()->toRfc4122(), $metadata['classroom_id']);
        self::assertSame($ctx['institution']->getId()->toRfc4122(), $metadata['institution_id']);
        self::assertSame(2, $metadata['recipient_count']);
        self::assertSame(1, $metadata['definition_revision']);
        self::assertSame('assigned', $metadata['status']);
        self::assertArrayNotHasKey('title', $metadata);
        self::assertArrayNotHasKey('description', $metadata);
        self::assertStringNotContainsString('Kesirler', $audit[0]['metadata']);
        self::assertStringNotContainsString('Sayfa 12', $audit[0]['metadata']);
    }

    public function testZeroEligibleStudentsIsNoRecipientsAndWritesNothing(): void
    {
        $ctx = $this->homeworkContext('hwa-zero');
        $this->freezeClock();
        $definition = $this->draft($ctx);

        $failure = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));

        self::assertSame(HomeworkDefinitionException::REASON_NO_RECIPIENTS, $failure->getReason());
        self::assertArrayHasKey('recipients', $failure->getFieldErrors());
        $this->assertNothingAssigned();
        self::assertSame(0, $this->homeworkQuery()->countEligibleRecipients($ctx['classroom']->getId()));
    }

    public function testDueNotInTheFutureIsRejectedIncludingTheExactInstant(): void
    {
        $ctx = $this->homeworkContext('hwa-due');
        $this->enrollStudents($ctx, 1);
        $clock = $this->freezeClock();
        $definition = $this->draft($ctx);

        // Exactly the due instant (18:30 Europe/Istanbul = 15:30 UTC): not strictly in the future.
        $clock->modify('2026-11-12 15:30:00');
        $atDue = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));
        self::assertSame(HomeworkDefinitionException::REASON_DUE_NOT_FUTURE, $atDue->getReason());
        self::assertArrayHasKey('due', $atDue->getFieldErrors());

        $clock->modify('2026-11-13 09:00:00');
        $afterDue = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));
        self::assertSame(HomeworkDefinitionException::REASON_DUE_NOT_FUTURE, $afterDue->getReason());
        $this->assertNothingAssigned();

        // One second before the due instant is still allowed.
        $clock->modify('2026-11-12 15:29:59');
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        self::assertSame(1, $assignment->getRecipientCount());
    }

    public function testSecondAssignIsAlreadyAssignedAndKeepsTheFirstSnapshot(): void
    {
        $ctx = $this->homeworkContext('hwa-twice');
        $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $first = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);

        $failure = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));

        self::assertSame(HomeworkDefinitionException::REASON_ALREADY_ASSIGNED, $failure->getReason());
        self::assertSame(1, $this->assignmentRowCount());
        self::assertSame(2, $this->recipientRowCount());
        self::assertSame(2, $this->recipientRowCount($first->getId()));
        self::assertCount(1, $this->assignmentAuditRows());
    }

    public function testCoTeacherOtherTeacherOwnerAndAdminsCannotAssignSomeoneElsesDraft(): void
    {
        $ctx = $this->homeworkContext('hwa-intruder');
        $other = $this->homeworkContext('hwa-intruder-other');
        $co = $this->addCoTeacher($ctx, 'hwa-intruder-co@example.com');
        $admin = $this->activeUser('hwa-intruder-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);

        foreach ([$co['user'], $other['teacher'], $ctx['sa'], $admin, $ctx['owner']] as $intruder) {
            $failure = $this->failure(fn () => $this->assignHomework($intruder, $definition->getId(), 1));
            self::assertContains(
                $failure->getReason(),
                [HomeworkDefinitionException::REASON_NOT_FOUND, HomeworkDefinitionException::REASON_FORBIDDEN],
                $intruder->getEmail(),
            );
        }
        $this->assertNothingAssigned();

        // Owner teacher is unaffected by the failed attempts.
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        self::assertSame(2, $assignment->getRecipientCount());
    }

    public function testCoTeacherWithOwnDraftCanAssignOnlyThatDraft(): void
    {
        $ctx = $this->homeworkContext('hwa-co');
        $co = $this->addCoTeacher($ctx, 'hwa-co-assist@example.com');
        $this->enrollStudents($ctx, 1);
        $this->freezeClock();
        $teachers = $this->draft($ctx);
        $own = $this->homeworkManager()->createDraft($co['user'], $this->draftInput($ctx['classroom'], title: 'Yardımcı ödevi'));

        $denied = $this->failure(fn () => $this->assignHomework($co['user'], $teachers->getId(), 1));
        self::assertSame(HomeworkDefinitionException::REASON_NOT_FOUND, $denied->getReason());
        self::assertSame(0, $this->assignmentRowCount());

        $assignment = $this->assignHomework($co['user'], $own->getId(), 1);
        self::assertTrue($assignment->getAssignedByUser()->getId()->equals($co['user']->getId()));
        self::assertTrue($assignment->getAssignedByMembership()->getId()->equals($co['membership']->getId()));
        self::assertSame(1, $this->assignmentRowCount());
    }

    public function testEndedTeacherAssignmentCannotAssign(): void
    {
        $ctx = $this->homeworkContext('hwa-ended');
        $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $this->teacherManager()->endAssignment($ctx['assignment'], $ctx['owner'], 'ended');

        $failure = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));

        self::assertSame(HomeworkDefinitionException::REASON_NOT_FOUND, $failure->getReason());
        $this->assertNothingAssigned();
    }

    public function testSuspendedMembershipCannotAssign(): void
    {
        $ctx = $this->homeworkContext('hwa-susp');
        $this->enrollStudents($ctx, 1);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $this->em->getConnection()->executeStatement(
            "UPDATE institution_memberships SET status = 'suspended' WHERE id = :id",
            ['id' => $ctx['teacherMembership']->getId()->toBinary()],
        );

        $failure = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));

        self::assertSame(HomeworkDefinitionException::REASON_NOT_FOUND, $failure->getReason());
        $this->assertNothingAssigned();
    }

    public function testInactiveOrUnverifiedTeacherIsForbidden(): void
    {
        $ctx = $this->homeworkContext('hwa-actor');
        $this->enrollStudents($ctx, 1);
        $this->freezeClock();
        $definition = $this->draft($ctx);

        $this->setUserStatusRaw($ctx['teacher'], UserStatus::Suspended);
        $suspended = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));
        self::assertSame(HomeworkDefinitionException::REASON_FORBIDDEN, $suspended->getReason());

        $this->setUserStatusRaw($ctx['teacher'], UserStatus::Active);
        $this->clearEmailVerificationRaw($ctx['teacher']);
        $unverified = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));
        self::assertSame(HomeworkDefinitionException::REASON_FORBIDDEN, $unverified->getReason());

        $this->assertNothingAssigned();
    }

    public function testUnknownDefinitionIsNotFound(): void
    {
        $ctx = $this->homeworkContext('hwa-absent');
        $this->enrollStudents($ctx, 1);
        $this->freezeClock();

        $failure = $this->failure(fn () => $this->assignHomework(
            $ctx['teacher'],
            Uuid::fromString('0192f000-0000-7000-8000-000000000001'),
            1,
        ));

        self::assertSame(HomeworkDefinitionException::REASON_NOT_FOUND, $failure->getReason());
        $this->assertNothingAssigned();
    }

    public function testStaleRevisionIsRejected(): void
    {
        $ctx = $this->homeworkContext('hwa-stale');
        $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $this->homeworkManager()->updateDraft(
            $ctx['teacher'],
            $definition->getId(),
            $this->draftInput($ctx['classroom'], title: 'Güncel başlık', expectedRevision: 1),
        );

        foreach ([1, 0, 99] as $stale) {
            $failure = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), $stale));
            self::assertSame(HomeworkDefinitionException::REASON_STALE_REVISION, $failure->getReason(), (string) $stale);
        }
        $this->assertNothingAssigned();

        // The current revision assigns the edited text.
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 2);
        self::assertSame('Güncel başlık', $assignment->getTitle());
    }

    public function testConfirmationFalseIsRejectedBeforeAnyWrite(): void
    {
        $ctx = $this->homeworkContext('hwa-confirm');
        $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);

        $failure = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1, false));

        self::assertSame(HomeworkDefinitionException::REASON_CONFIRMATION_REQUIRED, $failure->getReason());
        self::assertArrayHasKey('confirm', $failure->getFieldErrors());
        $this->assertNothingAssigned();
    }

    public function testDraftCannotBeUpdatedAfterAssign(): void
    {
        $ctx = $this->homeworkContext('hwa-frozen');
        $this->enrollStudents($ctx, 1);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);

        foreach ([1, null, 2] as $revision) {
            $failure = $this->failure(fn () => $this->homeworkManager()->updateDraft(
                $ctx['teacher'],
                $definition->getId(),
                $this->draftInput($ctx['classroom'], title: 'Atamadan sonra', description: 'Değişti', dueTime: '23:00', expectedRevision: $revision),
            ));
            self::assertSame(HomeworkDefinitionException::REASON_NOT_EDITABLE, $failure->getReason(), (string) $revision);
        }

        $definitionRow = $this->em->getConnection()->fetchAssociative(
            'SELECT title, description, due_at, revision_number FROM homework_definitions WHERE id = :id',
            ['id' => $definition->getId()->toBinary()],
        );
        self::assertSame([
            'title' => 'Kesirler çalışma kâğıdı',
            'description' => 'Sayfa 12 ve 13’teki soruları çöz.',
            'due_at' => '2026-11-12 15:30:00',
            'revision_number' => 1,
        ], $definitionRow);
        $assignmentRow = $this->em->getConnection()->fetchAssociative(
            'SELECT title, due_at FROM homework_assignments WHERE id = :id',
            ['id' => $assignment->getId()->toBinary()],
        );
        self::assertSame(['title' => 'Kesirler çalışma kâğıdı', 'due_at' => '2026-11-12 15:30:00'], $assignmentRow);
        self::assertCount(0, array_filter(
            $this->homeworkAuditRows(),
            static fn (array $row): bool => 'homework_definition_draft_updated' === $row['action'],
        ));
    }

    public function testStudentEndedBeforeAssignIsExcludedFromTheSnapshot(): void
    {
        $ctx = $this->homeworkContext('hwa-endenroll');
        $students = $this->enrollStudents($ctx, 3);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        self::assertSame(3, $this->homeworkQuery()->countEligibleRecipients($ctx['classroom']->getId()));

        $this->enrollmentManager()->endEnrollment($this->activeEnrollment($ctx['classroom'], $students[0]), $ctx['owner'], 'left_class');
        self::assertSame(2, $this->homeworkQuery()->countEligibleRecipients($ctx['classroom']->getId()));

        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);

        self::assertSame(2, $assignment->getRecipientCount());
        self::assertSame(2, $this->recipientRowCount($assignment->getId()));
        $excluded = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_recipients WHERE student_membership_id = :id',
            ['id' => $students[0]->getId()->toBinary()],
        );
        self::assertSame(0, $excluded);
    }

    public function testMembershipEndedBeforeAssignIsExcludedEvenIfTheEnrollmentRowIsStillActive(): void
    {
        $ctx = $this->homeworkContext('hwa-endmember');
        $students = $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);

        // MembershipManager refuses to end a membership that still has an active enrollment, so
        // simulate the late/inconsistent state directly: the assign re-check under the membership
        // lock and the eligibility query must both drop it.
        $this->em->getConnection()->executeStatement(
            "UPDATE institution_memberships SET status = 'ended' WHERE id = :id",
            ['id' => $students[1]->getId()->toBinary()],
        );
        $enrollmentStatus = $this->em->getConnection()->fetchOne(
            'SELECT status FROM classroom_student_enrollments WHERE student_membership_id = :id',
            ['id' => $students[1]->getId()->toBinary()],
        );
        self::assertSame('active', $enrollmentStatus);

        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);

        self::assertSame(1, $assignment->getRecipientCount());
        self::assertSame(1, $this->recipientRowCount());
        $recipient = $this->em->getConnection()->fetchOne('SELECT student_membership_id FROM homework_recipients');
        self::assertSame($students[0]->getId()->toBinary(), $recipient);
    }

    public function testEveryStudentEndedBeforeAssignFailsWithNoRecipientsAndLeavesNoRows(): void
    {
        $ctx = $this->homeworkContext('hwa-allgone');
        $students = $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);

        $this->enrollmentManager()->endEnrollment($this->activeEnrollment($ctx['classroom'], $students[0]), $ctx['owner'], 'left_class');
        $this->enrollmentManager()->endEnrollment($this->activeEnrollment($ctx['classroom'], $students[1]), $ctx['owner'], 'left_class');
        $this->membershipManager()->endMembership($students[1], $ctx['owner'], 'left_school');

        $failure = $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));

        self::assertSame(HomeworkDefinitionException::REASON_NO_RECIPIENTS, $failure->getReason());
        $this->assertNothingAssigned();

        // Once a student returns, the very same draft can still be assigned.
        $this->enrollStudents($ctx, 1);
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        self::assertSame(1, $assignment->getRecipientCount());
    }

    public function testSuspendedStudentMembershipIsNotAnEligibleRecipient(): void
    {
        $ctx = $this->homeworkContext('hwa-suspstu');
        $students = $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $this->em->getConnection()->executeStatement(
            "UPDATE institution_memberships SET status = 'suspended' WHERE id = :id",
            ['id' => $students[0]->getId()->toBinary()],
        );

        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);

        self::assertSame(1, $assignment->getRecipientCount());
        self::assertSame(1, $this->recipientRowCount());
    }

    public function testOnlyTheDraftsClassroomContributesRecipients(): void
    {
        $ctx = $this->homeworkContext('hwa-scope');
        $second = $this->classroomManager()->create($ctx['year'], $ctx['owner'], 'hwa-scope 9-B', GradeLevel::Grade9, 'cls2', 'B', 40);
        $this->enrollStudents($ctx, 2);
        $this->enrollStudents(['owner' => $ctx['owner'], 'institution' => $ctx['institution'], 'classroom' => $second], 3);
        $this->freezeClock();
        $definition = $this->draft($ctx);

        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);

        self::assertSame(2, $assignment->getRecipientCount());
        self::assertSame(2, $this->recipientRowCount());
    }

    public function testLateEnrollmentAfterAssignDoesNotChangeTheSnapshot(): void
    {
        $ctx = $this->homeworkContext('hwa-late');
        $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $assignmentId = $assignment->getId();
        $recipientIds = $this->recipientIds($assignmentId);
        self::assertCount(2, $recipientIds);

        $this->enrollStudents($ctx, 2);

        self::assertSame(4, $this->homeworkQuery()->countEligibleRecipients($ctx['classroom']->getId()), 'live count moves');
        $this->em->clear();
        $stored = $this->em->find(HomeworkAssignment::class, $assignmentId);
        self::assertInstanceOf(HomeworkAssignment::class, $stored);
        self::assertSame(2, $stored->getRecipientCount(), 'snapshot count is frozen');
        self::assertSame(2, $this->recipientRowCount($assignmentId));
        self::assertSame($recipientIds, $this->recipientIds($assignmentId));
    }

    public function testEndingEnrollmentOrMembershipAfterAssignDoesNotRewriteTheSnapshot(): void
    {
        $ctx = $this->homeworkContext('hwa-after');
        $students = $this->enrollStudents($ctx, 3);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $assignmentId = $assignment->getId();
        $recipientIds = $this->recipientIds($assignmentId);

        $this->enrollmentManager()->endEnrollment($this->activeEnrollment($ctx['classroom'], $students[0]), $ctx['owner'], 'left_class');
        $this->enrollmentManager()->endEnrollment($this->activeEnrollment($ctx['classroom'], $students[1]), $ctx['owner'], 'left_class');
        $this->membershipManager()->endMembership($students[1], $ctx['owner'], 'left_school');

        self::assertSame(3, (int) $this->em->getConnection()->fetchOne(
            'SELECT recipient_count FROM homework_assignments WHERE id = :id',
            ['id' => $assignmentId->toBinary()],
        ));
        self::assertSame($recipientIds, $this->recipientIds($assignmentId));
        self::assertSame(1, $this->homeworkQuery()->countEligibleRecipients($ctx['classroom']->getId()));
        self::assertSame(3, $this->recipientRowCount($assignmentId));
    }

    public function testFailedAssignAttemptsLeaveNoAssignmentsRecipientsOrSuccessAudit(): void
    {
        $ctx = $this->homeworkContext('hwa-atomic');
        $co = $this->addCoTeacher($ctx, 'hwa-atomic-co@example.com');
        $this->enrollStudents($ctx, 2);
        $clock = $this->freezeClock();
        $definition = $this->draft($ctx);

        $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1, false));
        $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 7));
        $this->failure(fn () => $this->assignHomework($co['user'], $definition->getId(), 1));
        $clock->modify('2026-11-13 00:00:00');
        $this->failure(fn () => $this->assignHomework($ctx['teacher'], $definition->getId(), 1));

        $this->assertNothingAssigned();
        $this->em->clear();
        $stored = $this->em->find(HomeworkDefinition::class, $definition->getId());
        self::assertInstanceOf(HomeworkDefinition::class, $stored);
        self::assertSame(1, $stored->getRevisionNumber());
        self::assertSame(HomeworkDefinitionStatus::Draft, $stored->getStatus());
    }

    public function testReadModelCallsDoNotWriteAssignmentRows(): void
    {
        $ctx = $this->homeworkContext('hwa-read');
        $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $definition = $this->draft($ctx);
        $query = $this->homeworkQuery();

        $query->page($ctx['teacher'], '', null, 1);
        $query->statusCounts($ctx['teacher']);
        $query->countEligibleRecipients($ctx['classroom']->getId());
        self::assertNull($query->findAssignment($ctx['teacher'], $definition->getId()));
        self::assertInstanceOf(HomeworkDefinition::class, $query->findOwned($ctx['teacher'], $definition->getId(), true));

        $this->assertNothingAssigned();
    }

    /**
     * @param array{teacher: \App\Entity\User, classroom: \App\Entity\Classroom} $ctx
     */
    private function draft(array $ctx): HomeworkDefinition
    {
        return $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
    }

    /**
     * @return list<string> sorted recipient row ids (binary)
     */
    private function recipientIds(Uuid $assignmentId): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM homework_recipients WHERE assignment_id = :id ORDER BY id ASC',
            ['id' => $assignmentId->toBinary()],
        );

        return $ids;
    }

    private function assertNothingAssigned(): void
    {
        self::assertSame(0, $this->assignmentRowCount(), 'homework_assignments must be empty');
        self::assertSame(0, $this->recipientRowCount(), 'homework_recipients must be empty');
        self::assertSame([], $this->assignmentAuditRows(), 'no success audit');
    }

    private function failure(\Closure $action): HomeworkDefinitionException
    {
        try {
            $action();
        } catch (HomeworkDefinitionException $exception) {
            return $exception;
        }
        self::fail('Expected HomeworkDefinitionException.');
    }
}
