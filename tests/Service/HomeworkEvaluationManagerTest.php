<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\HomeworkAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\HomeworkEvaluationLabel;
use App\Enum\HomeworkSubmissionStatus;
use App\Enum\UserRole;
use App\Exception\HomeworkEvaluationException;
use App\Exception\HomeworkSubmissionException;
use App\Service\Homework\HomeworkText;
use App\Tests\Support\HomeworkTestFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher evaluation of submitted homework (X01). The clock is frozen at 2026-11-10 09:00 UTC; the
 * default homework is due 2026-11-12 18:30 Europe/Istanbul (15:30 UTC).
 *
 * Contract under test: only the assigning teacher (current active membership + classroom
 * assignment, open classroom/year) evaluates, only SUBMITTED work, evaluations are append-only,
 * the submission keeps a single current pointer, and every failure writes nothing. A GET never
 * evaluates: the manager has no read path, so "GET writes nothing" is covered by
 * TeacherHomeworkEvaluationHttpTest and by the read-model test below.
 */
final class HomeworkEvaluationManagerTest extends KernelTestCase
{
    use HomeworkTestFixtures;

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

    public function testAssigningTeacherEvaluatesSubmittedWorkAndWritesPointerAndAudit(): void
    {
        $s = $this->scenario('hwe-ok');
        $submission = $this->studentSubmit($s['users'][0], $s['assignment'], 'Çok gizli öğrenci yanıtı');
        $id = $submission->getId();

        $evaluation = $this->homeworkEvaluationManager()->record($s['ctx']['teacher'], $id, 'reviewed', "Aferin!\nÇok iyi ğüşiöç.", null);

        self::assertSame(HomeworkEvaluationLabel::Reviewed, $evaluation->getLabel());
        self::assertSame("Aferin!\nÇok iyi ğüşiöç.", $evaluation->getFeedback());
        self::assertNull($evaluation->getSupersedes());
        $rows = $this->evaluationRows($id);
        self::assertCount(1, $rows);
        self::assertSame($evaluation->getId()->toRfc4122(), $rows[0]['id']);
        self::assertSame('reviewed', $rows[0]['label']);
        self::assertSame("Aferin!\nÇok iyi ğüşiöç.", $rows[0]['feedback']);
        self::assertNull($rows[0]['supersedes']);
        self::assertSame('2026-11-10 09:00:00', $rows[0]['recorded_at']);
        self::assertSame($s['ctx']['teacher']->getId()->toRfc4122(), $rows[0]['evaluator']);
        self::assertSame(
            $s['ctx']['teacherMembership']->getId()->toBinary(),
            $this->em->getConnection()->fetchOne('SELECT evaluated_by_membership_id FROM homework_evaluations'),
        );
        self::assertSame($evaluation->getId()->toRfc4122(), $this->currentEvaluationId($id));

        $audit = $this->evaluationAuditRows();
        self::assertCount(1, $audit, 'one success audit per recorded evaluation');
        $metadata = json_decode($audit[0]['metadata'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame($evaluation->getId()->toRfc4122(), $metadata['resource_id']);
        self::assertSame($id->toRfc4122(), $metadata['submission_id']);
        self::assertSame($s['assignment']->getId()->toRfc4122(), $metadata['assignment_id']);
        self::assertSame($s['ctx']['classroom']->getId()->toRfc4122(), $metadata['classroom_id']);
        self::assertSame('reviewed', $metadata['label']);
        self::assertNull($metadata['supersedes_evaluation_id']);
        self::assertArrayNotHasKey('feedback', $metadata);
        self::assertStringNotContainsString('Aferin', $audit[0]['metadata'], 'audit carries identifiers only');
        self::assertStringNotContainsString('gizli', $audit[0]['metadata']);
    }

    public function testEvaluationShowsToTheAuthorOnlyAndStatsAndRowsFollowThePointer(): void
    {
        $s = $this->scenario('hwe-read', 3);
        [$author, $mate, $quiet] = $s['users'];
        $evaluated = $this->studentSubmit($author, $s['assignment'], 'Yazar yanıtı');
        $plain = $this->studentSubmit($mate, $s['assignment'], 'Sınıf arkadaşı yanıtı');
        $draft = $this->studentDraft($quiet, $s['assignment'], 'Gizli taslak metni');
        $teacher = $s['ctx']['teacher'];
        $query = $this->homeworkTeacherSubmissionQuery();

        $stats = $query->statsFor($teacher, $s['assignment']);
        self::assertNotNull($stats);
        self::assertSame([3, 2, 0], [$stats->recipientCount, $stats->submittedCount, $stats->evaluatedCount]);

        $this->homeworkEvaluationManager()->record($teacher, $evaluated->getId(), 'needs_rework', 'Bir daha dene.', null);

        $stats = $query->statsFor($teacher, $s['assignment']);
        self::assertNotNull($stats);
        self::assertSame([3, 2, 1], [$stats->recipientCount, $stats->submittedCount, $stats->evaluatedCount]);

        $rows = $query->recipientRows($teacher, $s['assignment']);
        self::assertNotNull($rows);
        self::assertCount(3, $rows);
        $byState = [];
        foreach ($rows as $row) {
            $byState[$row->stateKey][] = $row;
        }
        self::assertCount(1, $byState['evaluated']);
        self::assertSame('needs_rework', $byState['evaluated'][0]->evaluationLabelKey);
        self::assertSame('Yeniden çalışılmalı', $byState['evaluated'][0]->evaluationLabelTr);
        self::assertSame($evaluated->getId()->toRfc4122(), $byState['evaluated'][0]->submissionId);
        self::assertCount(1, $byState['submitted']);
        self::assertSame($plain->getId()->toRfc4122(), $byState['submitted'][0]->submissionId);
        self::assertNull($byState['submitted'][0]->evaluationLabelKey);
        // A draft is "awaiting": no submission id, nothing to inspect, never its text.
        self::assertCount(1, $byState['awaiting']);
        self::assertNull($byState['awaiting'][0]->submissionId);
        self::assertFalse($byState['awaiting'][0]->canInspect);
        self::assertStringNotContainsString('Gizli taslak', (string) json_encode($rows, \JSON_UNESCAPED_UNICODE));
        self::assertNull($query->submittedDetail($teacher, $draft->getId()));

        $detail = $query->submittedDetail($teacher, $evaluated->getId());
        self::assertNotNull($detail);
        self::assertSame('Yazar yanıtı', $detail->body);
        self::assertSame('needs_rework', $detail->evaluationLabelKey);
        self::assertSame('Bir daha dene.', $detail->feedback);
        self::assertNotNull($detail->currentEvaluationId);

        // The evaluated student reads label + feedback; the classmate sees nothing of it.
        $own = $this->homeworkStudentQuery()->detailFor($author, $s['assignment']->getId());
        self::assertNotNull($own);
        self::assertSame('needs_rework', $own->evaluationLabelKey);
        self::assertSame('Yeniden çalışılmalı', $own->evaluationLabelTr);
        self::assertSame('Bir daha dene.', $own->feedback);
        $other = $this->homeworkStudentQuery()->detailFor($mate, $s['assignment']->getId());
        self::assertNotNull($other);
        self::assertTrue($other->submitted);
        self::assertNull($other->evaluationLabelKey);
        self::assertNull($other->feedback);
        $untouched = $this->homeworkStudentQuery()->detailFor($quiet, $s['assignment']->getId());
        self::assertNotNull($untouched);
        self::assertFalse($untouched->submitted);
        self::assertNull($untouched->evaluationLabelKey);
    }

    public function testReviewedFeedbackIsOptionalAndBlankIsStoredAsNull(): void
    {
        $s = $this->scenario('hwe-opt', 2);
        $manager = $this->homeworkEvaluationManager();

        foreach ([$s['users'][0], $s['users'][1]] as $index => $student) {
            $submission = $this->studentSubmit($student, $s['assignment']);
            $evaluation = $manager->record($s['ctx']['teacher'], $submission->getId(), 'reviewed', 0 === $index ? '' : "  \r\n\t ", null);
            self::assertNull($evaluation->getFeedback());
            self::assertNull($this->evaluationRows($submission->getId())[0]['feedback']);
        }

        self::assertSame(2, $this->evaluationRowCount());
        self::assertCount(2, $this->evaluationAuditRows());
    }

    public function testNeedsReworkRequiresFeedbackAndWritesNothingWithout(): void
    {
        $s = $this->scenario('hwe-rework');
        $id = $this->studentSubmit($s['users'][0], $s['assignment'])->getId();
        $manager = $this->homeworkEvaluationManager();

        foreach (['', '   ', "\r\n\t "] as $blank) {
            $failure = $this->failure(static fn () => $manager->record($s['ctx']['teacher'], $id, 'needs_rework', $blank, null));
            self::assertSame(HomeworkEvaluationException::REASON_INVALID_INPUT, $failure->getReason());
            self::assertSame(['feedback' => 'Geri bildirim boş olamaz.'], $failure->getFieldErrors());
        }
        self::assertSame(0, $this->evaluationRowCount());
        self::assertNull($this->currentEvaluationId($id));
        self::assertSame([], $this->evaluationAuditRows());

        $ok = $manager->record($s['ctx']['teacher'], $id, 'needs_rework', 'Çözümleri defterine de yaz.', null);
        self::assertSame(HomeworkEvaluationLabel::NeedsRework, $ok->getLabel());
        self::assertSame(1, $this->evaluationRowCount());
        self::assertCount(1, $this->evaluationAuditRows());
    }

    public function testLabelIsAnAllowlistNotAScore(): void
    {
        $s = $this->scenario('hwe-label');
        $id = $this->studentSubmit($s['users'][0], $s['assignment'])->getId();
        $manager = $this->homeworkEvaluationManager();

        foreach (['', 'approved', 'REVIEWED', 'Reviewed', 'needs-rework', 'graded', '10', '85.5', "reviewed\0", 'reviewed;needs_rework'] as $label) {
            $failure = $this->failure(static fn () => $manager->record($s['ctx']['teacher'], $id, $label, 'Geri bildirim', null));
            self::assertSame(HomeworkEvaluationException::REASON_INVALID_INPUT, $failure->getReason(), bin2hex($label));
            self::assertArrayHasKey('label', $failure->getFieldErrors(), bin2hex($label));
        }

        self::assertSame(0, $this->evaluationRowCount());
        self::assertNull($this->currentEvaluationId($id));
        self::assertSame([], $this->evaluationAuditRows());
        foreach (HomeworkEvaluationLabel::cases() as $label) {
            self::assertContains($label->value, ['reviewed', 'needs_rework'], 'only two labels exist; no numeric score');
        }
    }

    public function testDraftSubmissionCannotBeEvaluatedAndItsBodyIsNeverExposedToTheTeacher(): void
    {
        $s = $this->scenario('hwe-draft');
        $draft = $this->studentDraft($s['users'][0], $s['assignment'], 'Gizli taslak metni');
        $teacher = $s['ctx']['teacher'];
        $id = $draft->getId();
        self::assertSame(HomeworkSubmissionStatus::Draft, $draft->getStatus());

        foreach (['reviewed', 'needs_rework'] as $label) {
            $failure = $this->failure(fn () => $this->homeworkEvaluationManager()->record($teacher, $id, $label, 'Taslağı değerlendirmeye çalışma', null));
            self::assertSame(HomeworkEvaluationException::REASON_NOT_SUBMITTED, $failure->getReason());
        }

        $query = $this->homeworkTeacherSubmissionQuery();
        self::assertNull($query->submittedDetail($teacher, $id), 'draft body must not be readable by the teacher');
        $rows = $query->recipientRows($teacher, $s['assignment']);
        self::assertNotNull($rows);
        self::assertCount(1, $rows);
        self::assertSame('awaiting', $rows[0]->stateKey);
        self::assertNull($rows[0]->submissionId);
        self::assertFalse($rows[0]->canInspect);
        self::assertStringNotContainsString('Gizli taslak', (string) json_encode($rows, \JSON_UNESCAPED_UNICODE));

        self::assertSame(0, $this->evaluationRowCount());
        self::assertNull($this->currentEvaluationId($id));
        self::assertSame([], $this->evaluationAuditRows());
        self::assertSame('Gizli taslak metni', $this->submissionRow($id)['body'], 'draft untouched');
    }

    public function testUnknownSubmissionAndRecipientIdsAreNotFound(): void
    {
        $s = $this->scenario('hwe-none');
        $manager = $this->homeworkEvaluationManager();

        $failure = $this->failure(static fn () => $manager->record($s['ctx']['teacher'], Uuid::fromString(self::ABSENT_ID), 'reviewed', '', null));
        self::assertSame(HomeworkEvaluationException::REASON_NOT_FOUND, $failure->getReason());
        // The recipient id (a real id, but not a submission id) is not a submission either.
        $recipientId = Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM homework_recipients'));
        $failure = $this->failure(static fn () => $manager->record($s['ctx']['teacher'], $recipientId, 'reviewed', '', null));
        self::assertSame(HomeworkEvaluationException::REASON_NOT_FOUND, $failure->getReason());

        self::assertSame(0, $this->submissionRowCount());
        self::assertSame(0, $this->evaluationRowCount());
        self::assertSame([], $this->evaluationAuditRows());
    }

    public function testStudentWhoLeftTheClassCanStillBeEvaluatedAndReadsTheFeedback(): void
    {
        $s = $this->scenario('hwe-left', 2);
        [$leaver, $stayer] = $s['users'];
        $leaverSubmission = $this->studentSubmit($leaver, $s['assignment'], 'Ayrılmadan önce teslim ettim');
        $stayerSubmission = $this->studentSubmit($stayer, $s['assignment']);
        $this->endStudentEnrollment($s['ctx'], $s['students'][0]);

        $evaluation = $this->homeworkEvaluationManager()->record($s['ctx']['teacher'], $leaverSubmission->getId(), 'reviewed', 'Sınıftan ayrıldın ama ödevin değerlendirildi.', null);

        self::assertSame($evaluation->getId()->toRfc4122(), $this->currentEvaluationId($leaverSubmission->getId()));
        self::assertCount(1, $this->evaluationAuditRows());
        $detail = $this->homeworkStudentQuery()->detailFor($leaver, $s['assignment']->getId());
        self::assertNotNull($detail, 'own submitted answer stays readable after leaving');
        self::assertFalse($detail->writable);
        self::assertSame('reviewed', $detail->evaluationLabelKey);
        self::assertSame('Sınıftan ayrıldın ama ödevin değerlendirildi.', $detail->feedback);
        $row = $this->homeworkTeacherSubmissionQuery()->submittedDetail($s['ctx']['teacher'], $leaverSubmission->getId());
        self::assertNotNull($row, 'the teacher still reads the submitted answer of a student who left');

        // Student membership ended as well: the teacher can still correct the evaluation.
        $this->enrollmentManager()->endEnrollment($this->activeEnrollment($s['ctx']['classroom'], $s['students'][1]), $s['ctx']['owner'], 'left_class');
        $this->membershipManager()->endMembership($s['students'][1], $s['ctx']['owner'], 'end_student');
        $second = $this->homeworkEvaluationManager()->record($s['ctx']['teacher'], $stayerSubmission->getId(), 'needs_rework', 'Üyelik bitti, yine de değerlendirildi.', null);
        self::assertSame(HomeworkEvaluationLabel::NeedsRework, $second->getLabel());
        self::assertSame(2, $this->evaluationRowCount());
    }

    public function testEndedTeacherAssignmentOrMembershipDeniesEvaluateAndRead(): void
    {
        $s = $this->scenario('hwe-ended', 2);
        $first = $this->studentSubmit($s['users'][0], $s['assignment']);
        $second = $this->studentSubmit($s['users'][1], $s['assignment']);
        $teacher = $s['ctx']['teacher'];
        $this->homeworkEvaluationManager()->record($teacher, $first->getId(), 'reviewed', 'Önce değerlendirildi.', null);
        $pointerBefore = $this->currentEvaluationId($first->getId());

        $this->teacherManager()->endAssignment($s['ctx']['assignment'], $s['ctx']['owner'], 'ended');

        $this->assertTeacherLocksOut($s, $teacher, $first->getId(), $second->getId(), HomeworkEvaluationException::REASON_FORBIDDEN);
        self::assertSame($pointerBefore, $this->currentEvaluationId($first->getId()), 'correction by an ended teacher must not move the pointer');

        // Ending the teacher membership as well keeps the lock-out.
        $this->membershipManager()->endMembership($s['ctx']['teacherMembership'], $s['ctx']['owner'], 'end_teacher');
        $this->assertTeacherLocksOut($s, $teacher, $first->getId(), $second->getId(), HomeworkEvaluationException::REASON_FORBIDDEN);

        self::assertSame(1, $this->evaluationRowCount(), 'only the evaluation recorded while the teacher was active');
        self::assertCount(1, $this->evaluationAuditRows());
        self::assertNull($this->currentEvaluationId($second->getId()));
    }

    public function testAdminSuperAdminOwnerCoTeacherStudentAndOtherInstitutionAreDenied(): void
    {
        $s = $this->scenario('hwe-deny');
        $submission = $this->studentSubmit($s['users'][0], $s['assignment'], 'Sadece öğretmenim görsün');
        $id = $submission->getId();
        $co = $this->addCoTeacher($s['ctx'], 'hwe-deny-co@example.com')['user'];
        $admin = $this->activeUser('hwe-deny-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $other = $this->homeworkContext('hwe-deny2');
        $otherTeacher = $other['teacher'];
        $query = $this->homeworkTeacherSubmissionQuery();

        $intruders = [
            'co-teacher' => $co,
            'admin' => $admin,
            'super admin' => $s['ctx']['sa'],
            'institution owner' => $s['ctx']['owner'],
            'student author' => $s['users'][0],
            'other institution teacher' => $otherTeacher,
        ];
        foreach ($intruders as $name => $intruder) {
            $failure = $this->failure(fn () => $this->homeworkEvaluationManager()->record($intruder, $id, 'reviewed', 'Yetkisiz', null));
            self::assertSame(HomeworkEvaluationException::REASON_NOT_FOUND, $failure->getReason(), $name);
            self::assertNull($query->submittedDetail($intruder, $id), $name);
            self::assertNull($query->recipientRows($intruder, $s['assignment']), $name);
            self::assertNull($query->statsFor($intruder, $s['assignment']), $name);
        }

        self::assertSame(0, $this->evaluationRowCount());
        self::assertNull($this->currentEvaluationId($id));
        self::assertSame([], $this->evaluationAuditRows());
        // The assigning teacher still works afterwards.
        $this->homeworkEvaluationManager()->record($s['ctx']['teacher'], $id, 'reviewed', '', null);
        self::assertSame(1, $this->evaluationRowCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeFeedback(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'angle bracket' => ['a > b'];
        yield 'bell control' => ["abc\x07"];
        yield 'nul byte' => ["abc\x00def"];
        yield 'invalid utf8' => ["abc\xC3\x28"];
    }

    #[DataProvider('unsafeFeedback')]
    public function testHtmlControlCharactersAndInvalidUtf8AreRejectedNotStripped(string $unsafe): void
    {
        $s = $this->scenario('hwe-html');
        $id = $this->studentSubmit($s['users'][0], $s['assignment'])->getId();

        foreach (['reviewed', 'needs_rework'] as $label) {
            $failure = $this->failure(fn () => $this->homeworkEvaluationManager()->record($s['ctx']['teacher'], $id, $label, $unsafe, null));
            self::assertSame(HomeworkEvaluationException::REASON_INVALID_INPUT, $failure->getReason(), $label);
            self::assertArrayHasKey('feedback', $failure->getFieldErrors());
            self::assertStringStartsWith('Geri bildirim ', $failure->getFieldErrors()['feedback']);
        }

        self::assertSame(0, $this->evaluationRowCount());
        self::assertNull($this->currentEvaluationId($id));
        self::assertSame([], $this->evaluationAuditRows());
    }

    public function testHtmlMessageIsTheSafeTurkishTextAndAmpersandsAndQuotesStayPlainText(): void
    {
        $s = $this->scenario('hwe-plain');
        $id = $this->studentSubmit($s['users'][0], $s['assignment'])->getId();

        $failure = $this->failure(fn () => $this->homeworkEvaluationManager()->record($s['ctx']['teacher'], $id, 'reviewed', 'merhaba <b>dünya</b>', null));
        self::assertSame(['feedback' => 'Geri bildirim HTML içeremez.'], $failure->getFieldErrors());

        // Plain-text punctuation is stored as typed (escaping is the template's job, not storage's).
        $text = 'Tom & Jerry "tırnak" \'tek\' 5 € ~ 100%';
        $this->homeworkEvaluationManager()->record($s['ctx']['teacher'], $id, 'reviewed', $text, null);
        self::assertSame($text, $this->evaluationRows($id)[0]['feedback']);
    }

    public function testFeedbackAtFiveThousandUnicodeCodePointsIsAcceptedAndOneMoreIsRejected(): void
    {
        $s = $this->scenario('hwe-unicode', 2);
        $manager = $this->homeworkEvaluationManager();
        $emoji = "\u{1F600}";
        $max = str_repeat($emoji, HomeworkText::FEEDBACK_MAX);
        self::assertSame(4 * HomeworkText::FEEDBACK_MAX, \strlen($max));

        $first = $this->studentSubmit($s['users'][0], $s['assignment']);
        $failure = $this->failure(static fn () => $manager->record($s['ctx']['teacher'], $first->getId(), 'needs_rework', $max.$emoji, null));
        self::assertSame(HomeworkEvaluationException::REASON_INVALID_INPUT, $failure->getReason());
        self::assertSame(['feedback' => 'Geri bildirim en fazla 5.000 karakter olabilir.'], $failure->getFieldErrors());
        self::assertSame(0, $this->evaluationRowCount());

        $evaluation = $manager->record($s['ctx']['teacher'], $first->getId(), 'needs_rework', $max, null);
        self::assertSame(HomeworkText::FEEDBACK_MAX, HomeworkText::length((string) $evaluation->getFeedback()));
        self::assertSame($max, $this->evaluationRows($first->getId())[0]['feedback'], 'four-byte characters survive storage');

        // Turkish letters and CRLF: stored as LF, counted once per code point.
        $second = $this->studentSubmit($s['users'][1], $s['assignment']);
        $turkish = $manager->record($s['ctx']['teacher'], $second->getId(), 'reviewed', "ğüşiöçİı\r\nÇAĞRI", null);
        self::assertSame("ğüşiöçİı\nÇAĞRI", $turkish->getFeedback());
    }

    public function testStaleExpectedCurrentEvaluationIdIsRejectedAndKeepsTheCurrentPointer(): void
    {
        $s = $this->scenario('hwe-stale');
        $id = $this->studentSubmit($s['users'][0], $s['assignment'])->getId();
        $manager = $this->homeworkEvaluationManager();
        $teacher = $s['ctx']['teacher'];

        // No evaluation exists yet: a concrete expected id cannot be "current".
        $failure = $this->failure(static fn () => $manager->record($teacher, $id, 'reviewed', 'x', Uuid::fromString(self::ABSENT_ID)));
        self::assertSame(HomeworkEvaluationException::REASON_STALE, $failure->getReason());
        self::assertSame(0, $this->evaluationRowCount());

        $first = $manager->record($teacher, $id, 'reviewed', 'İlk değerlendirme', null);

        // The first tab still believes nothing exists, another tab holds a foreign id.
        foreach ([null, Uuid::fromString(self::ABSENT_ID)] as $expected) {
            $failure = $this->failure(static fn () => $manager->record($teacher, $id, 'needs_rework', 'Eski sekme', $expected));
            self::assertSame(HomeworkEvaluationException::REASON_STALE, $failure->getReason());
        }
        self::assertSame(1, $this->evaluationRowCount());
        self::assertSame($first->getId()->toRfc4122(), $this->currentEvaluationId($id));

        $second = $manager->record($teacher, $id, 'needs_rework', 'Düzeltme', $first->getId());
        // The superseded id is stale from now on, however recent it was.
        $failure = $this->failure(static fn () => $manager->record($teacher, $id, 'reviewed', 'Eski sekme yine', $first->getId()));
        self::assertSame(HomeworkEvaluationException::REASON_STALE, $failure->getReason());
        self::assertSame(2, $this->evaluationRowCount());
        self::assertSame($second->getId()->toRfc4122(), $this->currentEvaluationId($id));
        self::assertCount(2, $this->evaluationAuditRows());
        self::assertSame('Düzeltme', $this->evaluationRows($id)[1]['feedback']);

        // The current id still works.
        $third = $manager->record($teacher, $id, 'reviewed', '', $second->getId());
        self::assertSame($third->getId()->toRfc4122(), $this->currentEvaluationId($id));
        self::assertSame(3, $this->evaluationRowCount());
    }

    public function testCorrectionIsAppendOnlyAndNeverRewritesTheOldEvaluation(): void
    {
        $s = $this->scenario('hwe-append');
        $id = $this->studentSubmit($s['users'][0], $s['assignment'])->getId();
        $manager = $this->homeworkEvaluationManager();
        $teacher = $s['ctx']['teacher'];

        $first = $manager->record($teacher, $id, 'needs_rework', 'Eksik kalmış, tamamla.', null);
        $before = $this->evaluationRows($id);
        self::assertCount(1, $before);

        $s['clock']->modify('2026-11-11 10:30:00');
        $second = $manager->record($teacher, $id, 'reviewed', '', $first->getId());

        $after = $this->evaluationRows($id);
        self::assertCount(2, $after, 'a correction adds a row');
        self::assertSame($before[0], $after[0], 'the superseded evaluation is byte-for-byte unchanged');
        self::assertSame('needs_rework', $after[0]['label']);
        self::assertSame('Eksik kalmış, tamamla.', $after[0]['feedback']);
        self::assertSame('2026-11-10 09:00:00', $after[0]['recorded_at']);
        self::assertNull($after[0]['supersedes']);
        self::assertSame($second->getId()->toRfc4122(), $after[1]['id']);
        self::assertSame('reviewed', $after[1]['label']);
        self::assertNull($after[1]['feedback'], 'the correction does not inherit the old feedback');
        self::assertSame($first->getId()->toRfc4122(), $after[1]['supersedes']);
        self::assertSame('2026-11-11 10:30:00', $after[1]['recorded_at']);
        self::assertSame($second->getId()->toRfc4122(), $this->currentEvaluationId($id));

        // The audit trail has one success row per evaluation, the second names the one it supersedes.
        $audit = $this->evaluationAuditRows();
        self::assertCount(2, $audit);
        $last = json_decode($audit[1]['metadata'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($last);
        self::assertSame($second->getId()->toRfc4122(), $last['resource_id']);
        self::assertSame($first->getId()->toRfc4122(), $last['supersedes_evaluation_id']);

        // Read models show only the current evaluation.
        $detail = $this->homeworkTeacherSubmissionQuery()->submittedDetail($teacher, $id);
        self::assertNotNull($detail);
        self::assertSame($second->getId()->toRfc4122(), $detail->currentEvaluationId);
        self::assertSame('reviewed', $detail->evaluationLabelKey);
        self::assertNull($detail->feedback);
        $student = $this->homeworkStudentQuery()->detailFor($s['users'][0], $s['assignment']->getId());
        self::assertNotNull($student);
        self::assertSame('reviewed', $student->evaluationLabelKey);
        self::assertNull($student->feedback, 'a superseded evaluation text never leaks to the student');
    }

    public function testFailedPathsLeaveNoSuccessAuditAndNoRows(): void
    {
        $s = $this->scenario('hwe-atomic', 2);
        $id = $this->studentSubmit($s['users'][0], $s['assignment'])->getId();
        $draftId = $this->studentDraft($s['users'][1], $s['assignment'])->getId();
        $manager = $this->homeworkEvaluationManager();
        $teacher = $s['ctx']['teacher'];
        $first = $manager->record($teacher, $id, 'reviewed', 'Sağlam değerlendirme', null);
        $baseline = $this->evaluationRows($id);
        self::assertCount(1, $this->evaluationAuditRows());

        $attempts = [
            'bad label' => static fn () => $manager->record($teacher, $id, 'approved', 'x', $first->getId()),
            'blank rework' => static fn () => $manager->record($teacher, $id, 'needs_rework', '  ', $first->getId()),
            'html' => static fn () => $manager->record($teacher, $id, 'reviewed', '<b>x</b>', $first->getId()),
            'too long' => static fn () => $manager->record($teacher, $id, 'reviewed', str_repeat('a', HomeworkText::FEEDBACK_MAX + 1), $first->getId()),
            'stale null' => static fn () => $manager->record($teacher, $id, 'reviewed', 'x', null),
            'stale foreign' => static fn () => $manager->record($teacher, $id, 'reviewed', 'x', Uuid::fromString(self::ABSENT_ID)),
            'draft' => static fn () => $manager->record($teacher, $draftId, 'reviewed', 'x', null),
            'unknown' => static fn () => $manager->record($teacher, Uuid::fromString(self::ABSENT_ID), 'reviewed', 'x', null),
            'student' => static fn () => $manager->record($s['users'][0], $id, 'reviewed', 'x', $first->getId()),
        ];
        foreach ($attempts as $name => $attempt) {
            $this->failure($attempt);
            self::assertSame($baseline, $this->evaluationRows($id), $name);
            self::assertSame($first->getId()->toRfc4122(), $this->currentEvaluationId($id), $name);
            self::assertCount(1, $this->evaluationAuditRows(), $name.': no success audit for a failed evaluate');
        }

        self::assertSame(1, $this->evaluationRowCount());
        self::assertSame(0, $this->evaluationRowCount($draftId));
        // The shared EntityManager survives every domain rejection (the controller re-reads after one).
        $this->rebindDeliveryFixtures();
        self::assertTrue($this->em->isOpen());
        $detail = $this->homeworkTeacherSubmissionQuery()->submittedDetail($teacher, $id);
        self::assertNotNull($detail);
        self::assertSame('Sağlam değerlendirme', $detail->feedback);
    }

    public function testEvaluateNeverChangesTheSubmittedBodyOrSubmittedAtAndNeverReopensIt(): void
    {
        $s = $this->scenario('hwe-immut');
        $student = $s['users'][0];
        $this->homeworkSubmissionManager()->saveDraft($student, $s['assignment']->getId(), 'ilk taslak', 0);
        $submission = $this->homeworkSubmissionManager()->submit($student, $s['assignment']->getId(), "Nihai yanıt\r\nsatır 2", 1, true);
        $id = $submission->getId();
        $before = $this->submissionRow($id);
        self::assertSame(['body' => "Nihai yanıt\nsatır 2", 'status' => 'submitted', 'revision_number' => 2, 'submitted_at' => '2026-11-10 09:00:00'], $before);

        $s['clock']->modify('2026-11-11 08:00:00');
        $manager = $this->homeworkEvaluationManager();
        $first = $manager->record($s['ctx']['teacher'], $id, 'needs_rework', 'Yeniden çalış.', null);
        $manager->record($s['ctx']['teacher'], $id, 'reviewed', 'Tamam.', $first->getId());

        self::assertSame($before, $this->submissionRow($id), 'evaluate must not touch body, status, revision or submitted_at');
        // Neither evaluation label gives the student another write.
        foreach ([2, 1, 0] as $revision) {
            $draftAgain = $this->failure(fn () => $this->homeworkSubmissionManager()->saveDraft($student, $s['assignment']->getId(), 'taslağa dönüş', $revision));
            self::assertSame(HomeworkSubmissionException::REASON_ALREADY_SUBMITTED, $draftAgain->getReason(), (string) $revision);
            $resubmit = $this->failure(fn () => $this->homeworkSubmissionManager()->submit($student, $s['assignment']->getId(), 'yeniden teslim', $revision, true));
            self::assertSame(HomeworkSubmissionException::REASON_ALREADY_SUBMITTED, $resubmit->getReason(), (string) $revision);
        }
        self::assertSame($before, $this->submissionRow($id));
        $detail = $this->homeworkStudentQuery()->detailFor($student, $s['assignment']->getId());
        self::assertNotNull($detail);
        self::assertFalse($detail->writable);
        self::assertSame("Nihai yanıt\nsatır 2", $detail->body);
    }

    public function testReadModelCallsNeverCreateEvaluationRowsOrAudit(): void
    {
        $s = $this->scenario('hwe-getless', 2);
        $first = $this->studentSubmit($s['users'][0], $s['assignment']);
        $this->studentDraft($s['users'][1], $s['assignment']);
        $query = $this->homeworkTeacherSubmissionQuery();
        $teacher = $s['ctx']['teacher'];

        for ($i = 0; $i < 3; ++$i) {
            self::assertNotNull($query->statsFor($teacher, $s['assignment']));
            self::assertNotNull($query->recipientRows($teacher, $s['assignment']));
            self::assertNotNull($query->submittedDetail($teacher, $first->getId()));
            self::assertNotNull($this->homeworkStudentQuery()->detailFor($s['users'][0], $s['assignment']->getId()));
        }

        self::assertSame(0, $this->evaluationRowCount());
        self::assertNull($this->currentEvaluationId($first->getId()));
        self::assertSame([], $this->evaluationAuditRows());
    }

    public function testTeacherListQueryCountDoesNotGrowWithRecipientCount(): void
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $query = $this->homeworkTeacherSubmissionQuery();
        $manager = $this->homeworkEvaluationManager();

        $measure = function (string $prefix, int $students) use ($query, $manager, $holder): int {
            $s = $this->scenario($prefix, $students);
            $teacher = $s['ctx']['teacher'];
            foreach ($s['users'] as $index => $user) {
                $submission = $this->studentSubmit($user, $s['assignment'], 'Yanıt '.$index);
                if (0 === $index % 2) {
                    $manager->record($teacher, $submission->getId(), 'reviewed', 'Geri bildirim '.$index, null);
                }
            }
            $query->statsFor($teacher, $s['assignment']);
            $query->recipientRows($teacher, $s['assignment']); // warm-up

            $holder->reset();
            $stats = $query->statsFor($teacher, $s['assignment']);
            $rows = $query->recipientRows($teacher, $s['assignment']);
            $statements = $this->recordedStatements($holder);

            self::assertNotNull($stats);
            self::assertSame([$students, $students, intdiv($students + 1, 2)], [$stats->recipientCount, $stats->submittedCount, $stats->evaluatedCount]);
            self::assertNotNull($rows);
            self::assertCount($students, $rows);

            return $statements;
        };

        $small = $measure('hwe-nplus-s', 2);
        $large = $measure('hwe-nplus-l', 6);

        self::assertSame($small, $large, 'Teacher list SQL must not grow with recipient rows (no N+1).');
        self::assertLessThanOrEqual(8, $large);
    }

    /**
     * @return array{
     *     ctx: array{owner: User, sa: User, institution: \App\Entity\Institution, year: \App\Entity\AcademicYear, classroom: \App\Entity\Classroom, teacher: User, teacherMembership: InstitutionMembership, assignment: \App\Entity\ClassroomTeacherAssignment},
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
        $assignment = $this->assignClassroomHomework($ctx);
        $users = array_map(static fn (InstitutionMembership $membership): User => $membership->getUser(), $memberships);

        return ['ctx' => $ctx, 'students' => $memberships, 'users' => $users, 'assignment' => $assignment, 'clock' => $clock];
    }

    /**
     * @param array{assignment: HomeworkAssignment} $s
     */
    private function assertTeacherLocksOut(array $s, User $teacher, Uuid $evaluatedId, Uuid $otherId, string $reason): void
    {
        $manager = $this->homeworkEvaluationManager();
        $pointer = $this->currentEvaluationId($evaluatedId);
        $rowsBefore = $this->evaluationRowCount();
        $auditBefore = \count($this->evaluationAuditRows());

        // First evaluation of a submission and a correction of an existing evaluation are both closed.
        $first = $this->failure(static fn () => $manager->record($teacher, $otherId, 'reviewed', 'Artık yetkim yok', null));
        self::assertSame($reason, $first->getReason());
        $correction = $this->failure(static fn () => $manager->record($teacher, $evaluatedId, 'needs_rework', 'Düzeltme', null !== $pointer ? Uuid::fromString($pointer) : null));
        self::assertSame($reason, $correction->getReason());

        $query = $this->homeworkTeacherSubmissionQuery();
        self::assertNull($query->submittedDetail($teacher, $evaluatedId));
        self::assertNull($query->submittedDetail($teacher, $otherId));
        self::assertNull($query->recipientRows($teacher, $s['assignment']));
        self::assertNull($query->statsFor($teacher, $s['assignment']));

        self::assertSame($rowsBefore, $this->evaluationRowCount());
        self::assertCount($auditBefore, $this->evaluationAuditRows());
        self::assertNull($this->currentEvaluationId($otherId));
    }

    private function failure(\Closure $action): HomeworkEvaluationException|HomeworkSubmissionException
    {
        try {
            $action();
        } catch (HomeworkEvaluationException|HomeworkSubmissionException $exception) {
            // Entity-level rejections thrown inside wrapInTransaction close the EntityManager
            // (Doctrine ORM 3). Reboot so the next assertion in the same test can still run.
            if (isset($this->em) && !$this->em->isOpen()) {
                $this->resetDoctrineDelivery();
            }

            return $exception;
        }
        self::fail('Expected a homework exception.');
    }

    /**
     * @return array{body: string, status: string, revision_number: int, submitted_at: ?string}
     */
    private function submissionRow(Uuid $submissionId): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT body, status, revision_number, submitted_at FROM homework_submissions WHERE id = :id',
            ['id' => $submissionId->toBinary()],
        );
        self::assertIsArray($row, 'submission row expected');

        return [
            'body' => (string) $row['body'],
            'status' => (string) $row['status'],
            'revision_number' => (int) $row['revision_number'],
            'submitted_at' => null === $row['submitted_at'] ? null : (string) $row['submitted_at'],
        ];
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
