<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Classroom;
use App\Entity\HomeworkAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\HomeworkSubmissionStatus;
use App\Exception\HomeworkSubmissionException;
use App\Homework\Pdf\HomeworkPdfDurableStore;
use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Homework\Pdf\HomeworkPdfUploadGate;
use App\LearningContent\Document\PdfDocumentInspector;
use App\Service\Homework\HomeworkPdfAttachmentManager;
use App\Service\Homework\HomeworkStudentQuery;
use App\Service\Homework\HomeworkSubmissionManager;
use App\Tests\Homework\Pdf\ControllableHomeworkPdfDurableStore;
use App\Tests\Homework\Pdf\FakeHomeworkPdfScanAdapter;
use App\Tests\Support\HomeworkTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class HomeworkPdfAttachmentManagerTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        putenv('HOMEWORK_PDF_UPLOAD_ENABLED=1');
        putenv('HOMEWORK_PDF_SCAN_READY=1');
        $_ENV['HOMEWORK_PDF_UPLOAD_ENABLED'] = '1';
        $_SERVER['HOMEWORK_PDF_UPLOAD_ENABLED'] = '1';
        $_ENV['HOMEWORK_PDF_SCAN_READY'] = '1';
        $_SERVER['HOMEWORK_PDF_SCAN_READY'] = '1';
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
        $this->fakeScanner()->setVerdict(HomeworkPdfScanVerdict::Clean);
    }

    protected function tearDown(): void
    {
        FakeHomeworkPdfScanAdapter::forceVerdict(null);
        ControllableHomeworkPdfDurableStore::resetFailNextCommit();
        $this->cleanupHomework();
        putenv('HOMEWORK_PDF_UPLOAD_ENABLED=0');
        putenv('HOMEWORK_PDF_SCAN_READY=0');
        $_ENV['HOMEWORK_PDF_UPLOAD_ENABLED'] = '0';
        $_SERVER['HOMEWORK_PDF_UPLOAD_ENABLED'] = '0';
        $_ENV['HOMEWORK_PDF_SCAN_READY'] = '0';
        $_SERVER['HOMEWORK_PDF_SCAN_READY'] = '0';
        parent::tearDown();
    }

    public function testAttachKeepsDraftAndStoresOneAttachment(): void
    {
        $s = $this->scenario('pdf-ok');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $this->submissionManager()->saveDraft($student, $assignmentId, 'Taslak metin', 0);

        $attachment = $this->attachments()->attach($student, $assignmentId, $this->minimalPdf(), 'Odev.pdf', 1);
        self::assertSame('Odev.pdf', $attachment->getDisplayName());
        self::assertFileExists(
            $this->durableStore()->absolutePath($attachment->getStorageKey()),
        );

        $detail = $this->studentQuery()->detailFor($student, $assignmentId);
        self::assertNotNull($detail);
        self::assertTrue($detail->hasPdfAttachment);
        self::assertSame('Odev.pdf', $detail->pdfDisplayName);
        self::assertFalse($detail->submitted);
        self::assertSame('draft', $detail->statusKey);
        self::assertSame(2, $detail->revisionNumber);

        $conn = $this->em->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
        self::assertSame(
            HomeworkSubmissionStatus::Draft->value,
            (string) $conn->fetchOne('SELECT status FROM homework_submissions'),
        );
    }

    public function testSecondAttachRejectedWithoutReplace(): void
    {
        $s = $this->scenario('pdf-dup');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $this->submissionManager()->saveDraft($student, $assignmentId, 'Metin', 0);
        $this->attachments()->attach($student, $assignmentId, $this->minimalPdf(), 'Bir.pdf', 1);

        try {
            $this->attachments()->attach($student, $assignmentId, $this->minimalPdf('iki'), 'Iki.pdf', 2);
            self::fail('expected pdf exists');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_PDF_EXISTS, $e->getReason());
        }

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
        self::assertSame('Bir.pdf', (string) $this->em->getConnection()->fetchOne('SELECT display_name FROM homework_submission_attachments'));
    }

    public function testInfectedScanCreatesNoAttachment(): void
    {
        $s = $this->scenario('pdf-inf');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $this->submissionManager()->saveDraft($student, $assignmentId, 'Metin', 0);
        $this->fakeScanner()->setVerdict(HomeworkPdfScanVerdict::Infected);

        try {
            $this->attachments()->attach($student, $assignmentId, $this->minimalPdf(), 'Kotu.pdf', 1);
            self::fail('expected reject');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_PDF_REJECTED, $e->getReason());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
    }

    public function testClosedGateRejectsBeforePersist(): void
    {
        putenv('HOMEWORK_PDF_UPLOAD_ENABLED=0');
        $_ENV['HOMEWORK_PDF_UPLOAD_ENABLED'] = '0';
        $_SERVER['HOMEWORK_PDF_UPLOAD_ENABLED'] = '0';
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();

        $s = $this->scenario('pdf-closed');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $this->submissionManager()->saveDraft($student, $assignmentId, 'Metin', 0);

        try {
            $this->attachments()->attach($student, $assignmentId, $this->minimalPdf(), 'Kapali.pdf', 1);
            self::fail('expected closed');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_PDF_CLOSED, $e->getReason());
        }
        self::assertFalse($this->pdfGate()->mayAcceptUpload());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
    }

    public function testOtherStudentForbidden(): void
    {
        $s = $this->scenario('pdf-other');
        $assignmentId = $s['assignment']->getId();
        $this->submissionManager()->saveDraft($s['users'][0], $assignmentId, 'Metin', 0);
        $outsiderCtx = $this->homeworkContext('pdf-outsider');
        $outsider = $this->enrollStudents($outsiderCtx, 1)[0]->getUser();

        try {
            $this->attachments()->attach($outsider, $assignmentId, $this->minimalPdf(), 'Baska.pdf', 0);
            self::fail('expected not found');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_NOT_FOUND, $e->getReason());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
    }

    public function testSubmittedDraftRejectsAttach(): void
    {
        $s = $this->scenario('pdf-sub');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $manager = $this->submissionManager();
        $manager->saveDraft($student, $assignmentId, 'Metin', 0);
        $manager->submit($student, $assignmentId, 'Metin', 1, true);

        try {
            $this->attachments()->attach($student, $assignmentId, $this->minimalPdf(), 'Gec.pdf', 2);
            self::fail('expected already submitted');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_ALREADY_SUBMITTED, $e->getReason());
        }
    }

    public function testNonPdfBytesRejected(): void
    {
        $s = $this->scenario('pdf-type');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $this->submissionManager()->saveDraft($student, $assignmentId, 'Metin', 0);

        try {
            $this->attachments()->attach($student, $assignmentId, 'not-a-pdf', 'X.pdf', 1);
            self::fail('expected type reject');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_PDF_REJECTED, $e->getReason());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
    }

    public function testOversizedRejected(): void
    {
        $s = $this->scenario('pdf-size');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $this->submissionManager()->saveDraft($student, $assignmentId, 'Metin', 0);
        $bytes = $this->minimalPdf().str_repeat('A', PdfDocumentInspector::MAX_BYTES);

        try {
            $this->attachments()->attach($student, $assignmentId, $bytes, 'Buyuk.pdf', 1);
            self::fail('expected size reject');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_PDF_REJECTED, $e->getReason());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
    }

    public function testTextSubmitStillWorksWithoutPdf(): void
    {
        $s = $this->scenario('pdf-text');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $manager = $this->submissionManager();
        $manager->saveDraft($student, $assignmentId, 'Sadece metin', 0);
        $manager->submit($student, $assignmentId, 'Sadece metin', 1, true);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
        self::assertSame(
            HomeworkSubmissionStatus::Submitted->value,
            (string) $this->em->getConnection()->fetchOne('SELECT status FROM homework_submissions'),
        );
    }

    public function testDurableFailureKeepsExistingDraftUnchanged(): void
    {
        $s = $this->scenario('pdf-dur-keep');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();
        $this->submissionManager()->saveDraft($student, $assignmentId, 'Korunan metin', 0);

        $conn = $this->em->getConnection();
        $before = $conn->fetchAssociative(
            'SELECT body, status, revision_number FROM homework_submissions ORDER BY id ASC LIMIT 1',
        );
        self::assertIsArray($before);
        self::assertSame('Korunan metin', (string) $before['body']);
        self::assertSame(HomeworkSubmissionStatus::Draft->value, (string) $before['status']);
        self::assertSame(1, (int) $before['revision_number']);

        $this->controllableDurable()->failNextCommit();
        try {
            $this->attachments()->attach($student, $assignmentId, $this->minimalPdf('dur-keep'), 'Dur.pdf', 1);
            self::fail('expected durable reject');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_PDF_REJECTED, $e->getReason());
        }

        self::assertTrue($this->em->isOpen(), 'EM must stay open for controller 422 render');
        $after = $conn->fetchAssociative(
            'SELECT body, status, revision_number FROM homework_submissions ORDER BY id ASC LIMIT 1',
        );
        self::assertIsArray($after);
        self::assertSame((string) $before['body'], (string) $after['body']);
        self::assertSame((string) $before['status'], (string) $after['status']);
        self::assertSame((int) $before['revision_number'], (int) $after['revision_number']);
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
        self::assertSame(0, $this->auditCount('homework_submission_pdf_attached'));
        self::assertSame(0, $this->quarantineFileCount());

        $detail = $this->studentQuery()->detailFor($student, $assignmentId);
        self::assertNotNull($detail);
        self::assertSame('Korunan metin', $detail->body);
        self::assertSame(1, $detail->revisionNumber);
        self::assertFalse($detail->hasPdfAttachment);
    }

    public function testDurableFailureDoesNotCreateDraftWhenNoneExists(): void
    {
        $s = $this->scenario('pdf-dur-none');
        $student = $s['users'][0];
        $assignmentId = $s['assignment']->getId();

        $conn = $this->em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM homework_submissions'));

        $this->controllableDurable()->failNextCommit();
        try {
            $this->attachments()->attach($student, $assignmentId, $this->minimalPdf('dur-none'), 'Yeni.pdf', 0);
            self::fail('expected durable reject');
        } catch (HomeworkSubmissionException $e) {
            self::assertSame(HomeworkSubmissionException::REASON_PDF_REJECTED, $e->getReason());
        }

        self::assertTrue($this->em->isOpen(), 'EM must stay open for controller 422 render');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM homework_submissions'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'));
        self::assertSame(0, $this->auditCount('homework_submission_pdf_attached'));
        self::assertSame(0, $this->quarantineFileCount());

        $detail = $this->studentQuery()->detailFor($student, $assignmentId);
        self::assertNotNull($detail);
        self::assertSame('', $detail->body);
        self::assertSame(0, $detail->revisionNumber);
        self::assertFalse($detail->hasPdfAttachment);
        self::assertFalse($detail->submitted);
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

    private function attachments(): HomeworkPdfAttachmentManager
    {
        $manager = static::getContainer()->get(HomeworkPdfAttachmentManager::class);
        self::assertInstanceOf(HomeworkPdfAttachmentManager::class, $manager);

        return $manager;
    }

    private function submissionManager(): HomeworkSubmissionManager
    {
        $manager = static::getContainer()->get(HomeworkSubmissionManager::class);
        self::assertInstanceOf(HomeworkSubmissionManager::class, $manager);

        return $manager;
    }

    private function studentQuery(): HomeworkStudentQuery
    {
        $query = static::getContainer()->get(HomeworkStudentQuery::class);
        self::assertInstanceOf(HomeworkStudentQuery::class, $query);

        return $query;
    }

    private function durableStore(): HomeworkPdfDurableStore
    {
        $store = static::getContainer()->get(HomeworkPdfDurableStore::class);
        self::assertInstanceOf(HomeworkPdfDurableStore::class, $store);

        return $store;
    }

    private function controllableDurable(): ControllableHomeworkPdfDurableStore
    {
        $store = static::getContainer()->get(ControllableHomeworkPdfDurableStore::class);
        self::assertInstanceOf(ControllableHomeworkPdfDurableStore::class, $store);

        return $store;
    }

    private function pdfGate(): HomeworkPdfUploadGate
    {
        $gate = static::getContainer()->get(HomeworkPdfUploadGate::class);
        self::assertInstanceOf(HomeworkPdfUploadGate::class, $gate);

        return $gate;
    }

    private function fakeScanner(): FakeHomeworkPdfScanAdapter
    {
        $fake = static::getContainer()->get(FakeHomeworkPdfScanAdapter::class);
        self::assertInstanceOf(FakeHomeworkPdfScanAdapter::class, $fake);

        return $fake;
    }

    private function auditCount(string $action): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = ?',
            [$action],
        );
    }

    private function quarantineFileCount(): int
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $dir = $projectDir.'/var/homework-pdf-quarantine';
        if (!is_dir($dir)) {
            return 0;
        }
        $files = glob($dir.'/*') ?: [];

        return \count(array_filter($files, static fn (string $path): bool => is_file($path)));
    }

    private function minimalPdf(string $marker = 'ok'): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".$marker;
    }
}
