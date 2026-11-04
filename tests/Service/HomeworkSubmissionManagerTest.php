<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Classroom;
use App\Entity\HomeworkAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\HomeworkSubmissionStatus;
use App\Enum\UserRole;
use App\Exception\HomeworkSubmissionException;
use App\Service\Homework\HomeworkText;
use App\Tests\Support\HomeworkTestFixtures;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Student text draft/submit. The clock is frozen at 2026-11-10 09:00 UTC; the default homework is
 * due 2026-11-12 18:30 Europe/Istanbul (15:30 UTC). Revision protocol: the first write expects 0,
 * the first stored draft has revision 1, every successful write increments it.
 */
final class HomeworkSubmissionManagerTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    private const DUE_UTC = '2026-11-12 15:30:00';
    private const ABSENT_ID = '0192f000-0000-7000-8000-000000000001';

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
            if (!$this->em->isOpen()) {
                $this->resetDoctrineDelivery();
            }
            $this->cleanupHomework();
        }
        parent::tearDown();
    }

    public function testEmptyDraftIsSavedAndRevisionsStartAtOne(): void
    {
        $s = $this->scenario('hws-empty');

        $draft = $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $s['assignment']->getId(), '', 0);

        self::assertSame(HomeworkSubmissionStatus::Draft, $draft->getStatus());
        self::assertSame('', $draft->getBody());
        self::assertSame(1, $draft->getRevisionNumber());
        self::assertNull($draft->getSubmittedAt());
        self::assertSame(
            ['body' => '', 'status' => 'draft', 'revision_number' => 1, 'submitted_at' => null],
            $this->submissionRow($s['assignment']->getId()),
        );

        // Whitespace-only is the same empty draft; the next write expects revision 1.
        $again = $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $s['assignment']->getId(), "  \n\t ", 1);
        self::assertSame('', $again->getBody());
        self::assertSame(2, $again->getRevisionNumber());
        self::assertSame(1, $this->submissionRowCount($s['assignment']->getId()));
        self::assertSame(2, $this->auditCount('homework_submission_draft_saved'));
        self::assertSame(0, $this->auditCount('homework_submission_submitted'));
    }

    public function testSubmitWithEmptyBodyIsRejectedAndWritesNothing(): void
    {
        $s = $this->scenario('hws-subempty');

        foreach (['', '   ', "\r\n\t"] as $blank) {
            $failure = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($s['users'][0], $s['assignment']->getId(), $blank, 0, true));
            self::assertSame(HomeworkSubmissionException::REASON_INVALID_INPUT, $failure->getReason());
            self::assertArrayHasKey('body', $failure->getFieldErrors());
            self::assertStringContainsString('boş', $failure->getFieldErrors()['body']);
        }

        self::assertSame(0, $this->submissionRowCount());
        self::assertSame([], $this->submissionAuditRows());
    }

    public function testUnicodeAndEmojiAreCountedAsCodePointsAtTheLimit(): void
    {
        $s = $this->scenario('hws-unicode');
        $manager = $this->homeworkSubmissionManager();
        $id = $s['assignment']->getId();
        $emoji = "\u{1F600}";

        $max = str_repeat($emoji, HomeworkText::BODY_MAX);
        self::assertSame(4 * HomeworkText::BODY_MAX, \strlen($max));
        $draft = $manager->saveDraft($s['users'][0], $id, $max, 0);
        self::assertSame(HomeworkText::BODY_MAX, HomeworkText::length($draft->getBody()));
        self::assertSame($max, $this->submissionRow($id)['body']);

        $failure = $this->failure(static fn () => $manager->saveDraft($s['users'][0], $id, $max.$emoji, 1));
        self::assertSame(HomeworkSubmissionException::REASON_INVALID_INPUT, $failure->getReason());
        self::assertStringContainsString('en fazla 20.000 karakter', $failure->getFieldErrors()['body']);
        self::assertSame(1, $this->submissionRow($id)['revision_number'], 'rejected write must not bump the revision');

        // Turkish letters and CRLF: stored as LF, counted once per code point.
        $turkish = "ğüşiöçİı\r\nÇAĞRI";
        $draft = $manager->saveDraft($s['users'][0], $id, $turkish, 1);
        self::assertSame("ğüşiöçİı\nÇAĞRI", $draft->getBody());
        self::assertSame(2, $draft->getRevisionNumber());
    }

    public function testHtmlControlCharactersAndInvalidUtf8AreRejectedNotStripped(): void
    {
        $s = $this->scenario('hws-html');
        $id = $s['assignment']->getId();

        foreach (['<script>alert(1)</script>', 'a > b', "abc\x07", "abc\x00def", "abc\xC3\x28"] as $unsafe) {
            $draftFailure = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $id, $unsafe, 0));
            self::assertSame(HomeworkSubmissionException::REASON_INVALID_INPUT, $draftFailure->getReason(), bin2hex($unsafe));
            self::assertArrayHasKey('body', $draftFailure->getFieldErrors());

            $submitFailure = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($s['users'][0], $id, $unsafe, 0, true));
            self::assertSame(HomeworkSubmissionException::REASON_INVALID_INPUT, $submitFailure->getReason(), bin2hex($unsafe));
        }

        $html = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $id, 'merhaba <b>dünya</b>', 0));
        self::assertSame('Yanıtın HTML içeremez.', $html->getFieldErrors()['body']);
        self::assertSame(0, $this->submissionRowCount());
        self::assertSame([], $this->submissionAuditRows());
    }

    public function testDueInstantIsInclusiveForDraftAndSubmit(): void
    {
        $s = $this->scenario('hws-dueok');
        $id = $s['assignment']->getId();
        $manager = $this->homeworkSubmissionManager();

        $s['clock']->modify(self::DUE_UTC);
        $draft = $manager->saveDraft($s['users'][0], $id, 'Tam son anda taslak', 0);
        self::assertSame(1, $draft->getRevisionNumber());

        $submitted = $manager->submit($s['users'][0], $id, 'Tam son anda teslim', 1, true);
        self::assertSame(HomeworkSubmissionStatus::Submitted, $submitted->getStatus());
        self::assertSame('Tam son anda teslim', $submitted->getBody());
        self::assertSame(self::DUE_UTC, $submitted->getSubmittedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(1, $this->auditCount('homework_submission_submitted'));
    }

    public function testAfterDueInstantDraftAndSubmitAreRejectedAndKeepTheStoredDraft(): void
    {
        $s = $this->scenario('hws-duelate', 2);
        $id = $s['assignment']->getId();
        $manager = $this->homeworkSubmissionManager();
        $manager->saveDraft($s['users'][0], $id, 'Süre dolmadan yazdım', 0);

        $s['clock']->modify('2026-11-12 15:30:01');

        foreach ([
            static fn () => $manager->saveDraft($s['users'][0], $id, 'Geç taslak', 1),
            static fn () => $manager->submit($s['users'][0], $id, 'Geç teslim', 1, true),
            // A student without any row cannot start a draft after the deadline either.
            static fn () => $manager->saveDraft($s['users'][1], $id, 'Geç ilk taslak', 0),
            static fn () => $manager->submit($s['users'][1], $id, 'Geç ilk teslim', 0, true),
        ] as $attempt) {
            $failure = $this->failure($attempt);
            self::assertSame(HomeworkSubmissionException::REASON_DUE_PASSED, $failure->getReason());
            self::assertArrayHasKey('due', $failure->getFieldErrors());
        }

        self::assertSame(
            ['body' => 'Süre dolmadan yazdım', 'status' => 'draft', 'revision_number' => 1, 'submitted_at' => null],
            $this->submissionRow($id, $s['users'][0]),
        );
        self::assertSame(1, $this->submissionRowCount($id));
        self::assertSame(0, $this->auditCount('homework_submission_submitted'));
        self::assertSame(1, $this->auditCount('homework_submission_draft_saved'));
    }

    public function testDoubleSubmitKeepsFirstBodyAndWritesOneSuccessAudit(): void
    {
        $s = $this->scenario('hws-double');
        $id = $s['assignment']->getId();
        $manager = $this->homeworkSubmissionManager();

        $first = $manager->submit($s['users'][0], $id, 'Birinci teslim', 0, true);
        self::assertSame(HomeworkSubmissionStatus::Submitted, $first->getStatus());
        self::assertSame(2, $first->getRevisionNumber());

        foreach ([2, 0, 1] as $revision) {
            $second = $this->failure(static fn () => $manager->submit($s['users'][0], $id, 'İkinci teslim', $revision, true));
            self::assertSame(HomeworkSubmissionException::REASON_ALREADY_SUBMITTED, $second->getReason(), (string) $revision);
        }

        self::assertSame(1, $this->submissionRowCount($id));
        self::assertSame(
            ['body' => 'Birinci teslim', 'status' => 'submitted', 'revision_number' => 2, 'submitted_at' => '2026-11-10 09:00:00'],
            $this->submissionRow($id),
        );
        self::assertSame(1, $this->auditCount('homework_submission_submitted'));
        self::assertSame(0, $this->auditCount('homework_submission_draft_saved'));
    }

    public function testSuccessAuditCarriesMetadataOnlyAndNoStudentText(): void
    {
        $s = $this->scenario('hws-audit');
        $manager = $this->homeworkSubmissionManager();
        $manager->saveDraft($s['users'][0], $s['assignment']->getId(), 'Çok gizli taslak metni', 0);
        $submission = $manager->submit($s['users'][0], $s['assignment']->getId(), 'Çok gizli nihai metin', 1, true);

        $rows = $this->submissionAuditRows();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertStringNotContainsString('gizli', $row['metadata']);
            $metadata = json_decode($row['metadata'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($metadata);
            self::assertSame($submission->getId()->toRfc4122(), $metadata['resource_id']);
            self::assertSame($s['assignment']->getId()->toRfc4122(), $metadata['assignment_id']);
            self::assertSame($s['ctx']['classroom']->getId()->toRfc4122(), $metadata['classroom_id']);
            self::assertArrayNotHasKey('body', $metadata);
        }
        $actions = array_column($rows, 'action');
        sort($actions);
        self::assertSame(['homework_submission_draft_saved', 'homework_submission_submitted'], $actions);
    }

    public function testStaleRevisionOnDraftDoesNotOverwriteTheStoredDraft(): void
    {
        $s = $this->scenario('hws-stale');
        $id = $s['assignment']->getId();
        $manager = $this->homeworkSubmissionManager();
        $manager->saveDraft($s['users'][0], $id, 'ilk', 0);
        $manager->saveDraft($s['users'][0], $id, 'ikinci', 1);

        foreach ([1, 0, 99] as $stale) {
            $failure = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $id, 'eski sekme', $stale));
            self::assertSame(HomeworkSubmissionException::REASON_STALE_REVISION, $failure->getReason(), (string) $stale);
        }

        self::assertSame(
            ['body' => 'ikinci', 'status' => 'draft', 'revision_number' => 2, 'submitted_at' => null],
            $this->submissionRow($id),
        );
        self::assertSame(2, $this->auditCount('homework_submission_draft_saved'));

        // The current revision still works after the rejected attempts.
        $ok = $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $id, 'üçüncü', 2);
        self::assertSame(3, $ok->getRevisionNumber());
    }

    public function testStaleRevisionOnSubmitAndOnFirstCreateWritesNothing(): void
    {
        $s = $this->scenario('hws-stalesub', 2);
        $id = $s['assignment']->getId();
        $manager = $this->homeworkSubmissionManager();
        $manager->saveDraft($s['users'][0], $id, 'taslak', 0);

        $stale = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($s['users'][0], $id, 'nihai', 0, true));
        self::assertSame(HomeworkSubmissionException::REASON_STALE_REVISION, $stale->getReason());
        self::assertSame('draft', $this->submissionRow($id, $s['users'][0])['status']);
        self::assertSame('taslak', $this->submissionRow($id, $s['users'][0])['body']);
        self::assertSame(0, $this->auditCount('homework_submission_submitted'));

        // First create must expect revision 0 (there is no prior version to be "current").
        foreach ([1, 5] as $revision) {
            $first = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($s['users'][1], $id, 'ilk yazım', $revision));
            self::assertSame(HomeworkSubmissionException::REASON_STALE_REVISION, $first->getReason(), (string) $revision);
            $firstSubmit = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($s['users'][1], $id, 'ilk teslim', $revision, true));
            self::assertSame(HomeworkSubmissionException::REASON_STALE_REVISION, $firstSubmit->getReason(), (string) $revision);
        }
        self::assertSame(1, $this->submissionRowCount($id), 'only the first student has a row');
    }

    public function testStaleRevisionLeavesTheEntityManagerUsableForTheErrorPage(): void
    {
        $s = $this->scenario('hws-emopen');
        $id = $s['assignment']->getId();
        $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $id, 'ilk', 0);

        try {
            $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $id, 'eski', 0);
            self::fail('Stale revision must be rejected.');
        } catch (HomeworkSubmissionException $exception) {
            self::assertSame(HomeworkSubmissionException::REASON_STALE_REVISION, $exception->getReason());
        }

        // The controller re-reads the stored draft with the same entity manager after a stale write.
        $this->rebindDeliveryFixtures();
        self::assertTrue($this->em->isOpen(), 'a domain rejection must not close the shared EntityManager');
        $detail = $this->homeworkStudentQuery()->detailFor($s['users'][0], $id);
        self::assertNotNull($detail);
        self::assertSame('ilk', $detail->body);
        self::assertSame(1, $detail->revisionNumber);
    }

    public function testDraftAfterSubmitIsRejectedAndSubmittedBodyIsKept(): void
    {
        $s = $this->scenario('hws-after');
        $id = $s['assignment']->getId();
        $manager = $this->homeworkSubmissionManager();
        $manager->saveDraft($s['users'][0], $id, 'taslak', 0);
        $manager->submit($s['users'][0], $id, 'nihai metin', 1, true);

        foreach ([2, 1, 0] as $revision) {
            $failure = $this->failure(static fn () => $manager->saveDraft($s['users'][0], $id, 'taslağa dönüş', $revision));
            self::assertSame(HomeworkSubmissionException::REASON_ALREADY_SUBMITTED, $failure->getReason(), (string) $revision);
        }

        // openDraft starts at revision 1; markSubmitted increments once → 2.
        self::assertSame(
            ['body' => 'nihai metin', 'status' => 'submitted', 'revision_number' => 2, 'submitted_at' => '2026-11-10 09:00:00'],
            $this->submissionRow($id),
        );
        self::assertSame(1, $this->auditCount('homework_submission_draft_saved'));
        self::assertSame(1, $this->auditCount('homework_submission_submitted'));
    }

    public function testStudentWhoLeftTheClassCannotWriteButKeepsReadOnlyAccessToOwnSubmission(): void
    {
        $s = $this->scenario('hws-left', 3);
        $id = $s['assignment']->getId();
        $manager = $this->homeworkSubmissionManager();
        [$submitter, $drafter, $untouched] = $s['users'];
        $manager->submit($submitter, $id, 'Gönderdiğim yanıt', 0, true);
        $manager->saveDraft($drafter, $id, 'Yarım kalan taslak', 0);

        foreach ($s['students'] as $membership) {
            $this->enrollmentManager()->endEnrollment($this->activeEnrollment($s['ctx']['classroom'], $membership), $s['ctx']['owner'], 'left_class');
        }

        foreach ([$drafter, $untouched] as $user) {
            $draftFailure = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($user, $id, 'ayrıldıktan sonra', $user === $drafter ? 1 : 0));
            self::assertSame(HomeworkSubmissionException::REASON_CLOSED, $draftFailure->getReason());
            $submitFailure = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($user, $id, 'ayrıldıktan sonra', $user === $drafter ? 1 : 0, true));
            self::assertSame(HomeworkSubmissionException::REASON_CLOSED, $submitFailure->getReason());
        }
        $submittedFailure = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($submitter, $id, 'sonradan', 2));
        self::assertContains($submittedFailure->getReason(), [HomeworkSubmissionException::REASON_CLOSED, HomeworkSubmissionException::REASON_ALREADY_SUBMITTED]);

        self::assertSame(2, $this->submissionRowCount($id), 'untouched student must not get a row');
        self::assertSame('Yarım kalan taslak', $this->submissionRow($id, $drafter)['body']);
        self::assertSame(1, $this->auditCount('homework_submission_submitted'));
        self::assertSame(1, $this->auditCount('homework_submission_draft_saved'));

        $query = $this->homeworkStudentQuery();
        $detail = $query->detailFor($submitter, $id);
        self::assertNotNull($detail, 'own submitted answer stays readable');
        self::assertTrue($detail->submitted);
        self::assertFalse($detail->writable);
        self::assertSame('Gönderdiğim yanıt', $detail->body);
        self::assertSame('submitted', $detail->statusKey);

        $rows = $query->listFor($submitter);
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->outsideCurrentScope);
        self::assertSame('submitted', $rows[0]->statusKey);

        // Drafts and never-started homework are not readable once the student left.
        self::assertNull($query->detailFor($drafter, $id));
        self::assertNull($query->detailFor($untouched, $id));
        self::assertSame([], $query->listFor($drafter));
        self::assertSame([], $query->listFor($untouched));
    }

    public function testOtherStudentsAndLateJoinersCannotAccessSomeoneElsesHomework(): void
    {
        $s = $this->scenario('hws-other', 2);
        $id = $s['assignment']->getId();
        $other = $this->homeworkContext('hws-other-ctx');
        $outsider = $this->enrollStudents($other, 1)[0]->getUser();
        $lateJoiner = $this->enrollStudents($s['ctx'], 1)[0]->getUser();
        $manager = $this->homeworkSubmissionManager();
        $manager->submit($s['users'][0], $id, 'Sadece benim yanıtım', 0, true);

        foreach ([$outsider, $lateJoiner] as $intruder) {
            $draft = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($intruder, $id, 'sızma', 0));
            self::assertSame(HomeworkSubmissionException::REASON_NOT_FOUND, $draft->getReason(), $intruder->getEmail());
            $submit = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($intruder, $id, 'sızma', 0, true));
            self::assertSame(HomeworkSubmissionException::REASON_NOT_FOUND, $submit->getReason(), $intruder->getEmail());
            self::assertNull($this->homeworkStudentQuery()->detailFor($intruder, $id));
            self::assertSame([], $this->homeworkStudentQuery()->listFor($intruder));
        }

        // A classmate who is a recipient sees an empty own answer, never the submitter's text.
        $classmate = $this->homeworkStudentQuery()->detailFor($s['users'][1], $id);
        self::assertNotNull($classmate);
        self::assertSame('', $classmate->body);
        self::assertSame('none', $classmate->statusKey);
        self::assertTrue($classmate->writable);

        $absent = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($s['users'][1], Uuid::fromString(self::ABSENT_ID), 'yok', 0));
        self::assertSame(HomeworkSubmissionException::REASON_NOT_FOUND, $absent->getReason());
        self::assertNull($this->homeworkStudentQuery()->detailFor($s['users'][1], Uuid::fromString(self::ABSENT_ID)));

        self::assertSame(1, $this->submissionRowCount());
        self::assertSame(1, $this->auditCount('homework_submission_submitted'));
    }

    public function testTeacherAdminOwnerAndSuperAdminCannotUseTheStudentManager(): void
    {
        $s = $this->scenario('hws-staff');
        $id = $s['assignment']->getId();
        $admin = $this->activeUser('hws-staff-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $this->homeworkSubmissionManager()->saveDraft($s['users'][0], $id, 'öğrenci taslağı', 0);

        foreach ([$s['ctx']['teacher'], $s['ctx']['owner'], $s['ctx']['sa'], $admin] as $intruder) {
            $draft = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($intruder, $id, 'yetkisiz', 0));
            self::assertContains($draft->getReason(), [HomeworkSubmissionException::REASON_NOT_FOUND, HomeworkSubmissionException::REASON_FORBIDDEN], $intruder->getEmail());
            $submit = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($intruder, $id, 'yetkisiz', 0, true));
            self::assertContains($submit->getReason(), [HomeworkSubmissionException::REASON_NOT_FOUND, HomeworkSubmissionException::REASON_FORBIDDEN], $intruder->getEmail());
            self::assertNull($this->homeworkStudentQuery()->detailFor($intruder, $id), $intruder->getEmail());
            self::assertSame([], $this->homeworkStudentQuery()->listFor($intruder), $intruder->getEmail());
        }

        self::assertSame(1, $this->submissionRowCount());
        self::assertSame('öğrenci taslağı', $this->submissionRow($id)['body']);
        self::assertSame(0, $this->auditCount('homework_submission_submitted'));
    }

    public function testFailedInvalidSubmitLeavesNoSuccessAuditAndNoStatusChange(): void
    {
        $s = $this->scenario('hws-atomic');
        $id = $s['assignment']->getId();
        $manager = $this->homeworkSubmissionManager();
        $manager->saveDraft($s['users'][0], $id, 'sağlam taslak', 0);

        $confirm = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($s['users'][0], $id, 'sağlam taslak', 1, false));
        self::assertSame(HomeworkSubmissionException::REASON_CONFIRMATION_REQUIRED, $confirm->getReason());
        self::assertArrayHasKey('confirm', $confirm->getFieldErrors());

        $html = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($s['users'][0], $id, '<b>kalın</b>', 1, true));
        self::assertSame(HomeworkSubmissionException::REASON_INVALID_INPUT, $html->getReason());

        $empty = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($s['users'][0], $id, '   ', 1, true));
        self::assertSame(HomeworkSubmissionException::REASON_INVALID_INPUT, $empty->getReason());

        $stale = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($s['users'][0], $id, 'başka', 7, true));
        self::assertSame(HomeworkSubmissionException::REASON_STALE_REVISION, $stale->getReason());

        self::assertSame(
            ['body' => 'sağlam taslak', 'status' => 'draft', 'revision_number' => 1, 'submitted_at' => null],
            $this->submissionRow($id),
        );
        self::assertSame(['homework_submission_draft_saved'], array_column($this->submissionAuditRows(), 'action'));
        $detail = $this->homeworkStudentQuery()->detailFor($s['users'][0], $id);
        self::assertNotNull($detail);
        self::assertFalse($detail->submitted);
        self::assertTrue($detail->writable);
    }

    public function testReadModelCallsNeverCreateSubmissionRowsOrAudit(): void
    {
        $s = $this->scenario('hws-read', 2);
        $query = $this->homeworkStudentQuery();

        foreach ($s['users'] as $user) {
            $rows = $query->listFor($user);
            self::assertCount(1, $rows);
            self::assertSame('none', $rows[0]->statusKey);
            $detail = $query->detailFor($user, $s['assignment']->getId());
            self::assertNotNull($detail);
            self::assertSame('none', $detail->statusKey);
            self::assertSame(0, $detail->revisionNumber);
            self::assertSame('', $detail->body);
            self::assertTrue($detail->writable);
        }

        self::assertSame(0, $this->submissionRowCount());
        self::assertSame([], $this->submissionAuditRows());
    }

    public function testListQueryCountDoesNotGrowWithRowCount(): void
    {
        $s = $this->scenario('hws-bound');
        $student = $s['users'][0];
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $query = $this->homeworkStudentQuery();

        $extra = $this->assignExtra($s['ctx'], 1);
        $this->homeworkSubmissionManager()->saveDraft($student, $s['assignment']->getId(), 'bir', 0);
        $query->listFor($student); // warm-up

        $this->em->clear();
        $fresh = $this->users->findOneById($student->getId());
        self::assertInstanceOf(User::class, $fresh);
        $holder->reset();
        $small = $query->listFor($fresh);
        $smallStatements = $this->recordedStatements($holder);
        self::assertCount(2, $small);
        self::assertEqualsCanonicalizing(
            ['draft', 'none'],
            array_map(static fn ($row): string => $row->statusKey, $small),
            'list must hydrate draft rows (typed studentUser load, not broken UUID IN)',
        );

        // clear() detached fixture entities; reload before further writes.
        $this->rebindDeliveryFixtures();
        $teacher = $this->users->findOneById($s['ctx']['teacher']->getId());
        $classroom = $this->em->find(Classroom::class, $s['ctx']['classroom']->getId());
        $membership = $this->em->find(InstitutionMembership::class, $s['ctx']['teacherMembership']->getId());
        self::assertInstanceOf(User::class, $teacher);
        self::assertInstanceOf(Classroom::class, $classroom);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $liveCtx = [
            'teacher' => $teacher,
            'teacherMembership' => $membership,
            'classroom' => $classroom,
        ];
        $writer = $this->users->findOneById($student->getId());
        self::assertInstanceOf(User::class, $writer);
        $more = $this->assignExtra($liveCtx, 3, 'Çok ödev');
        $this->homeworkSubmissionManager()->submit($writer, $more[0]->getId(), 'teslim', 0, true);
        $this->homeworkSubmissionManager()->saveDraft($writer, $extra[0]->getId(), 'iki', 0);

        $this->em->clear();
        $fresh = $this->users->findOneById($student->getId());
        self::assertInstanceOf(User::class, $fresh);
        $holder->reset();
        $large = $query->listFor($fresh);
        $largeStatements = $this->recordedStatements($holder);

        self::assertCount(5, $large, 'five assignments, one open classroom lookup');
        self::assertEqualsCanonicalizing(
            ['draft', 'draft', 'submitted', 'none', 'none'],
            array_map(static fn ($row): string => $row->statusKey, $large),
        );
        self::assertSame($smallStatements, $largeStatements, 'List SQL must not grow with assignment rows (no N+1).');
        self::assertLessThanOrEqual(6, $largeStatements);
        self::assertCount(5, array_unique(array_map(static fn ($row): string => $row->assignmentId, $large)));
    }

    /**
     * @return array{
     *     ctx: array{owner: User, sa: User, institution: \App\Entity\Institution, year: \App\Entity\AcademicYear, classroom: Classroom, teacher: User, teacherMembership: InstitutionMembership},
     *     students: list<InstitutionMembership>,
     *     users: list<User>,
     *     assignment: HomeworkAssignment,
     *     clock: MockClock
     * }
     */
    private function scenario(string $prefix, int $students = 1): array
    {
        $ctx = $this->homeworkContext($prefix);
        $memberships = $this->enrollStudents($ctx, $students);
        $clock = $this->freezeClock();
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $users = array_map(static fn (InstitutionMembership $membership): User => $membership->getUser(), $memberships);

        return ['ctx' => $ctx, 'students' => $memberships, 'users' => $users, 'assignment' => $assignment, 'clock' => $clock];
    }

    /**
     * More assignments for the classroom of an existing scenario (due 2026-12-01, clock stays frozen).
     *
     * @param array{teacher: User, teacherMembership: InstitutionMembership, classroom: Classroom} $ctx
     *
     * @return list<HomeworkAssignment>
     */
    private function assignExtra(array $ctx, int $count, string $title = 'Ek ödev'): array
    {
        $assignments = [];
        for ($i = 1; $i <= $count; ++$i) {
            $draft = $this->persistDraft($ctx, $title.' '.$i);
            $assignments[] = $this->assignHomework($ctx['teacher'], $draft->getId(), 1);
        }

        return $assignments;
    }

    private function failure(\Closure $action): HomeworkSubmissionException
    {
        try {
            $action();
        } catch (HomeworkSubmissionException $exception) {
            // Entity-level rejections thrown inside wrapInTransaction close the EntityManager
            // (Doctrine ORM 3). Reboot so the next assertion in the same test can still run;
            // testStaleRevisionLeavesTheEntityManagerUsableForTheErrorPage pins the contract.
            if (isset($this->em) && !$this->em->isOpen()) {
                $this->resetDoctrineDelivery();
            }

            return $exception;
        }
        self::fail('Expected HomeworkSubmissionException.');
    }

    /**
     * @return array{body: string, status: string, revision_number: int, submitted_at: ?string}
     */
    private function submissionRow(Uuid $assignmentId, ?User $student = null): array
    {
        $sql = 'SELECT body, status, revision_number, submitted_at FROM homework_submissions WHERE assignment_id = :id';
        $params = ['id' => $assignmentId->toBinary()];
        if (null !== $student) {
            $sql .= ' AND student_user_id = :student';
            $params['student'] = $student->getId()->toBinary();
        }
        $row = $this->em->getConnection()->fetchAssociative($sql, $params);
        self::assertIsArray($row, 'submission row expected');

        return [
            'body' => (string) $row['body'],
            'status' => (string) $row['status'],
            'revision_number' => (int) $row['revision_number'],
            'submitted_at' => null === $row['submitted_at'] ? null : (string) $row['submitted_at'],
        ];
    }

    private function auditCount(string $action): int
    {
        return \count(array_filter(
            $this->submissionAuditRows(),
            static fn (array $row): bool => $action === $row['action'],
        ));
    }

    private function recordedStatements(DebugDataHolder $holder): int
    {
        $count = 0;
        foreach ($holder->getData() as $queries) {
            if (!\is_array($queries)) {
                continue;
            }
            foreach ($queries as $item) {
                $statement = \is_array($item) ? ($item['sql'] ?? null) : null;
                if (\is_string($statement) && '' !== trim($statement)) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}
