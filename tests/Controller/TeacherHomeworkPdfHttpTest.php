<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ClassroomTeacherAssignment;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Homework\Pdf\HomeworkPdfDurableStore;
use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Homework\Pdf\HomeworkPdfStoragePaths;
use App\Homework\Pdf\HomeworkPdfUploadGate;
use App\Service\Homework\HomeworkPdfAttachmentManager;
use App\Service\Homework\HomeworkSubmissionManager;
use App\Tests\Homework\Pdf\ControllableHomeworkPdfDurableStore;
use App\Tests\Homework\Pdf\FakeHomeworkPdfScanAdapter;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher private PDF view & download for submitted homework.
 * Upload gate is opened only to seed fixtures and closed before verifying teacher download.
 */
final class TeacherHomeworkPdfHttpTest extends WebTestCase
{
    use HomeworkTestFixtures;

    private const PASSWORD = 'Guclu-Parola-123!';
    private const NOW_UTC = '2026-11-10 09:00:00';
    private const BODY = 'PDF ekli teslim metni';

    private ?string $frozenUtc = null;

    private ?string $persistSite = null;

    /**
     * @var list<string>
     */
    private array $createdDurableKeys = [];

    protected function setUp(): void
    {
        $this->frozenUtc = null;
        $this->createdDurableKeys = [];
        FakeHomeworkPdfScanAdapter::forceVerdict(null);
        ControllableHomeworkPdfDurableStore::resetFailNextCommit();
        ControllableHomeworkPdfDurableStore::resetReadAttempts();
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
        ControllableHomeworkPdfDurableStore::resetReadAttempts();
        $this->resetClock();
        self::ensureKernelShutdown();
        $this->useStorageRoots('', '');
        putenv('HOMEWORK_PDF_UPLOAD_ENABLED=0');
        putenv('HOMEWORK_PDF_SCAN_READY=0');
        $_ENV['HOMEWORK_PDF_UPLOAD_ENABLED'] = '0';
        $_SERVER['HOMEWORK_PDF_UPLOAD_ENABLED'] = '0';
        $_ENV['HOMEWORK_PDF_SCAN_READY'] = '0';
        $_SERVER['HOMEWORK_PDF_SCAN_READY'] = '0';
        self::bootKernel();
        $durable = $this->durable();
        foreach ($this->createdDurableKeys as $key) {
            $durable->deleteQuietly($key);
        }
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
        if (null !== $this->persistSite) {
            $this->removeTree($this->persistSite);
            $this->persistSite = null;
        }
        parent::tearDown();
    }

    public function testTeacherSeesPdfDownloadsWithUploadClosedAndEvaluationFlowStaysIntact(): void
    {
        $s = $this->scenarioWithPdf('thw-pdf-ok');
        $this->bootClosedGate();
        $gate = static::getContainer()->get(HomeworkPdfUploadGate::class);
        self::assertInstanceOf(HomeworkPdfUploadGate::class, $gate);
        self::assertFalse($gate->mayAcceptUpload(), 'upload gate must be closed during download verification');

        $path = '/ogretmen/odevler/teslim/'.$s['submissionId'];
        $studentPath = '/ogrenci/odevler/'.$s['assignmentId'];

        // Student detail keeps upload closed while showing the attached filename without a download URL.
        $student = $this->clientFor($s['studentEmail']);
        $studentCrawler = $student->request('GET', $studentPath);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $studentCrawler->filter('[data-testid="shw-pdf-form"]')->count());
        self::assertStringContainsString('Odev.pdf', $studentCrawler->filter('[data-testid="shw-pdf-name"]')->text());

        // Assigning teacher sees the PDF card, no storage key/disk path leak, and downloads exact PDF bytes.
        $teacher = $this->clientFor($s['teacherEmail']);
        $crawler = $teacher->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="hw-submission-pdf"]')->count());
        self::assertStringContainsString('Odev.pdf', $crawler->filter('[data-testid="hw-submission-pdf-name"]')->text());
        self::assertSame(
            $path.'/pdf',
            $crawler->filter('[data-testid="hw-submission-pdf-download"]')->attr('href'),
        );
        $detailHtml = (string) $teacher->getResponse()->getContent();
        self::assertStringNotContainsString($s['storageKey'], $detailHtml);
        self::assertStringNotContainsString('homework-pdf', $detailHtml);
        self::assertStringNotContainsString('.bin', $detailHtml);

        $teacher->request('GET', $path.'/pdf');
        self::assertResponseIsSuccessful();
        $response = $teacher->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        $headers = $response->headers;
        self::assertSame('application/pdf', $headers->get('Content-Type'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $cacheControl = (string) $headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        self::assertSame('no-cache', $headers->get('Pragma'));
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        $disposition = (string) $headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment;', $disposition);
        self::assertStringContainsString('Odev.pdf', $disposition);

        ob_start();
        $response->sendContent();
        $streamedBytes = ob_get_clean();
        self::assertSame($s['pdfBytes'], $streamedBytes);
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
        self::assertDirectoryDoesNotExist($this->publicHomeworkPdfDir());

        // Teacher evaluation + student feedback continue to work normally alongside PDF attachment.
        $token = (string) $crawler->filter('form.hw-eval-form input[name="_token"]')->attr('value');
        $teacher->request('POST', $path.'/degerlendir', [
            '_token' => $token,
            'expected_current_evaluation_id' => '',
            'label' => 'reviewed',
            'feedback' => 'PDF ekin ve metin yanıtın incelendi.',
        ]);
        self::assertResponseRedirects($path);

        $studentAfter = $this->clientFor($s['studentEmail']);
        $afterCrawler = $studentAfter->request('GET', $studentPath);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $afterCrawler->filter('[data-testid="shw-pdf-form"]')->count());
        self::assertStringContainsString('İncelendi', $afterCrawler->filter('[data-testid="shw-evaluation"]')->text());
        self::assertStringContainsString('PDF ekin ve metin yanıtın incelendi.', $afterCrawler->filter('[data-testid="shw-evaluation"]')->text());
    }

    public function testPdfSurvivesReleaseSwitchAndOldReleaseCleanupWithIdenticalBytesAndWrongRootNeverReadsSilently(): void
    {
        $site = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'hwpdf-persist-'.bin2hex(random_bytes(4));
        $this->persistSite = $site;
        $durableRoot = $site.'/shared/var/homework-pdf';
        $quarantineRoot = $site.'/shared/var/homework-pdf-quarantine';
        $release1 = $site.'/releases/20261127T100000Z-aaaaaaaaaaaa';
        $release2 = $site.'/releases/20261127T110000Z-bbbbbbbbbbbb';
        foreach ([$durableRoot, $quarantineRoot, $release1.'/var/homework-pdf'] as $dir) {
            self::assertTrue(mkdir($dir, 0700, true));
        }
        // Legacy-style decoy inside the old release: the shared root must be the only source of truth.
        file_put_contents($release1.'/var/homework-pdf/'.str_repeat('9', 32).'.bin', 'decoy-from-old-release');

        // Release 1: durable and quarantine roots point at the shared directory, outside releases/ and public/.
        $this->useStorageRoots($durableRoot, $quarantineRoot);
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->fakeScanner()->setVerdict(HomeworkPdfScanVerdict::Clean);

        $s = $this->scenarioWithPdf('thw-pdf-persist');
        $file = $durableRoot.'/'.$s['storageKey'].'.bin';
        $expectedSha = hash('sha256', $s['pdfBytes']);
        $expectedSize = \strlen($s['pdfBytes']);
        self::assertFileExists($file);
        self::assertSame($expectedSha, hash_file('sha256', $file));
        self::assertSame($expectedSize, filesize($file));
        self::assertFileDoesNotExist(\dirname(__DIR__, 2).'/var/homework-pdf/'.$s['storageKey'].'.bin');

        $paths = static::getContainer()->get(HomeworkPdfStoragePaths::class);
        self::assertInstanceOf(HomeworkPdfStoragePaths::class, $paths);
        self::assertSame($durableRoot, $paths->durableDirectory());
        self::assertTrue($paths->isSafeDurableDirectoryReady());
        self::assertFalse($paths->isReleaseScopedPath($durableRoot));
        self::assertFalse($paths->isInsidePublicDir($durableRoot));
        self::assertFalse($paths->pathsOverlap($durableRoot, $quarantineRoot));

        $this->bootClosedGate();
        $detail = '/ogretmen/odevler/teslim/'.$s['submissionId'];

        $beforeSwitch = $this->downloadPdf($s['teacherEmail'], $detail);
        self::assertSame($expectedSize, \strlen($beforeSwitch));
        self::assertSame($expectedSha, hash('sha256', $beforeSwitch));
        self::assertSame($s['pdfBytes'], $beforeSwitch);

        // Release 2 goes live (fresh container and process state) and the old release is cleaned up.
        self::assertTrue(mkdir($release2.'/var', 0700, true));
        $this->removeTree($release1);
        self::assertDirectoryDoesNotExist($release1);
        $this->bootClosedGate();

        $afterSwitch = $this->downloadPdf($s['teacherEmail'], $detail);
        self::assertSame($expectedSize, \strlen($afterSwitch));
        self::assertSame($expectedSha, hash('sha256', $afterSwitch));
        self::assertSame($beforeSwitch, $afterSwitch);
        self::assertFileExists($file);
        self::assertSame($expectedSha, hash_file('sha256', $file));
        self::assertSame(2, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));

        // Wrong root (misconfigured next release): controlled 404, nothing created, no silent fallback.
        $wrongRoot = $site.'/shared-wrong/var/homework-pdf';
        $this->useStorageRoots($wrongRoot, $quarantineRoot);
        $this->bootClosedGate();
        $teacher = $this->clientFor($s['teacherEmail']);
        $teacher->request('GET', $detail.'/pdf');
        self::assertResponseStatusCodeSame(404);
        $this->assertNoPdfOrPathLeak($teacher, $s['storageKey']);
        self::assertDirectoryDoesNotExist($wrongRoot);
        self::assertDirectoryDoesNotExist($site.'/shared-wrong');
        self::assertSame(2, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
        // The original object was neither moved, overwritten nor deleted.
        self::assertSame($expectedSha, hash_file('sha256', $file));

        // Correct root again: same bytes.
        $this->useStorageRoots($durableRoot, $quarantineRoot);
        $this->bootClosedGate();
        self::assertSame($s['pdfBytes'], $this->downloadPdf($s['teacherEmail'], $detail));
    }

    public function testSubmissionWithoutPdfHidesBlockAndAllowsTextEvaluation(): void
    {
        $s = $this->scenarioTextOnly('thw-pdf-none');
        $this->bootClosedGate();
        ControllableHomeworkPdfDurableStore::resetReadAttempts();

        $client = $this->clientFor($s['teacherEmail']);
        $path = '/ogretmen/odevler/teslim/'.$s['submissionId'];
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-testid="hw-submission-pdf"]')->count());

        $client->request('GET', $path.'/pdf');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, ControllableHomeworkPdfDurableStore::readAttempts());
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));

        $token = (string) $crawler->filter('form.hw-eval-form input[name="_token"]')->attr('value');
        $client->request('POST', $path.'/degerlendir', [
            '_token' => $token,
            'expected_current_evaluation_id' => '',
            'label' => 'reviewed',
            'feedback' => 'Metin teslimi tamam.',
        ]);
        self::assertResponseRedirects($path);
    }

    public function testUnauthorizedActorsDraftAndEndedTeacherNeverReadDiskOrLeak(): void
    {
        $s = $this->scenarioWithPdf('thw-pdf-auth', withDraftPdfStudent: true, withClassmate: true);
        $outsiderCtx = $this->homeworkContext('thw-pdf-out');
        $coTeacher = $this->addCoTeacher($s['ctx'], 'thw-pdf-co@example.com');
        $admin = $this->activeUser('thw-pdf-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        unset($coTeacher);

        $this->bootClosedGate();
        ControllableHomeworkPdfDurableStore::resetReadAttempts();

        $pdfPath = '/ogretmen/odevler/teslim/'.$s['submissionId'].'/pdf';

        // Anonymous visitor redirects to login without reading disk or writing audit.
        self::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('GET', $pdfPath);
        self::assertResponseRedirects();
        self::assertStringContainsString('/giris', (string) $anonymous->getResponse()->headers->get('Location'));

        // Foreign teacher, co-teacher, Admin, SuperAdmin, Institution Owner: all denied without reading disk.
        $nonOwners = [
            $outsiderCtx['teacher']->getEmail(),
            'thw-pdf-co@example.com',
            'thw-pdf-admin@example.com',
            $s['ctx']['sa']->getEmail(),
            $s['ctx']['owner']->getEmail(),
        ];
        foreach ($nonOwners as $email) {
            $client = $this->clientFor($email);
            $client->request('GET', $pdfPath);
            self::assertResponseStatusCodeSame(404, $email);
            $this->assertNoPdfOrPathLeak($client, $s['storageKey'], 'Odev.pdf');
        }

        // Author student, classmate, and draft-owner student: all denied without reading disk or leaking.
        foreach ([$s['studentEmail'], $s['classmateEmail'], $s['draftStudentEmail']] as $studentEmail) {
            self::assertNotNull($studentEmail);
            $client = $this->clientFor($studentEmail);
            $client->request('GET', $pdfPath);
            $status = $client->getResponse()->getStatusCode();
            self::assertNotSame(200, $status, $studentEmail);
            self::assertContains($status, [403, 404], $studentEmail);
            $this->assertNoPdfOrPathLeak($client, $s['storageKey'], 'Odev.pdf');
        }

        // Assigning teacher cannot view or download an unsubmitted draft that already has a PDF attached.
        self::assertNotNull($s['draftSubmissionId']);
        $teacher = $this->clientFor($s['teacherEmail']);
        $teacher->request('GET', '/ogretmen/odevler/teslim/'.$s['draftSubmissionId']);
        self::assertResponseStatusCodeSame(404);
        $teacher->request('GET', '/ogretmen/odevler/teslim/'.$s['draftSubmissionId'].'/pdf');
        self::assertResponseStatusCodeSame(404);
        $this->assertNoPdfOrPathLeak($teacher, (string) $s['draftStorageKey'], 'Taslak.pdf');

        // Absent / malformed ids stay opaque 404 without reading disk.
        $teacher->request('GET', '/ogretmen/odevler/teslim/0192f000-0000-7000-8000-000000000001/pdf');
        self::assertResponseStatusCodeSame(404);
        $teacher->request('GET', '/ogretmen/odevler/teslim/not-a-uuid/pdf');
        self::assertResponseStatusCodeSame(404);

        // Ended teacher assignment closes access to both detail and PDF download before reading disk.
        $this->rebindDeliveryFixtures();
        $assignment = $this->em->find(ClassroomTeacherAssignment::class, $s['ctx']['assignment']->getId());
        $owner = $this->users->findOneById($s['ctx']['owner']->getId());
        self::assertInstanceOf(ClassroomTeacherAssignment::class, $assignment);
        self::assertInstanceOf(User::class, $owner);
        $this->teacherManager()->endAssignment($assignment, $owner, 'ended');

        $teacher->request('GET', '/ogretmen/odevler/teslim/'.$s['submissionId']);
        self::assertResponseStatusCodeSame(404);
        $teacher->request('GET', $pdfPath);
        self::assertResponseStatusCodeSame(404);
        $this->assertNoPdfOrPathLeak($teacher, $s['storageKey'], 'Odev.pdf');

        self::assertSame(0, ControllableHomeworkPdfDurableStore::readAttempts(), 'authorization must reject before disk read');
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
    }

    public function testInactiveAndUnverifiedTeachersCannotDownloadWithoutReadingDisk(): void
    {
        $s = $this->scenarioWithPdf('thw-pdf-status');
        $this->bootClosedGate();
        $pdfPath = '/ogretmen/odevler/teslim/'.$s['submissionId'].'/pdf';

        // Unverified teacher gets 403 at account() gate without touching disk.
        $unverifiedClient = $this->clientFor($s['teacherEmail']);
        $this->rebindDeliveryFixtures();
        $this->db()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = :id',
            ['id' => $s['teacherId']->toBinary()],
        );
        ControllableHomeworkPdfDurableStore::resetReadAttempts();
        $unverifiedClient->request('GET', $pdfPath);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, ControllableHomeworkPdfDurableStore::readAttempts());

        // Suspended teacher is blocked by UserChecker and redirected to /giris without touching disk.
        $this->rebindDeliveryFixtures();
        $this->db()->executeStatement(
            'UPDATE users SET email_verified_at = :verified WHERE id = :id',
            ['verified' => '2026-01-01 00:00:00', 'id' => $s['teacherId']->toBinary()],
        );
        $suspendedClient = $this->clientFor($s['teacherEmail']);
        $this->rebindDeliveryFixtures();
        $teacher = $this->users->findOneById($s['teacherId']);
        self::assertInstanceOf(User::class, $teacher);
        $teacher->transitionTo(UserStatus::Suspended);
        $this->users->save($teacher);

        ControllableHomeworkPdfDurableStore::resetReadAttempts();
        $suspendedClient->request('GET', $pdfPath);
        self::assertResponseRedirects('/giris');
        self::assertSame(0, ControllableHomeworkPdfDurableStore::readAttempts());
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
    }

    public function testStudentWhoLeftClassSubmissionRemainsDownloadableByAssigningTeacher(): void
    {
        $s = $this->scenarioWithPdf('thw-pdf-left');
        $this->rebindDeliveryFixtures();
        $this->endStudentEnrollment($s['ctx'], $s['studentMembership']);
        $this->bootClosedGate();

        $teacher = $this->clientFor($s['teacherEmail']);
        $path = '/ogretmen/odevler/teslim/'.$s['submissionId'];
        $crawler = $teacher->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="hw-submission-pdf"]')->count());

        $teacher->request('GET', $path.'/pdf');
        self::assertResponseIsSuccessful();
        $response = $teacher->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        ob_start();
        $response->sendContent();
        $streamedBytes = ob_get_clean();
        self::assertSame($s['pdfBytes'], $streamedBytes);
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
    }

    public function testSubmissionIsolationServesOnlyRequestedSubmissionAttachment(): void
    {
        $ctx = $this->homeworkContext('thw-pdf-iso');
        $students = $this->enrollStudents($ctx, 3);
        $this->freezeClock(self::NOW_UTC);
        $this->frozenUtc = self::NOW_UTC;
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $assignmentId = $assignment->getId();

        $pdfOne = $this->minimalPdf('birinci');
        $this->submissions()->saveDraft($students[0]->getUser(), $assignmentId, 'Birinci yanıt', 0);
        $attOne = $this->attachments()->attach($students[0]->getUser(), $assignmentId, $pdfOne, 'Bir.pdf', 1);
        $this->createdDurableKeys[] = $attOne->getStorageKey();
        $subOne = $this->submissions()->submit($students[0]->getUser(), $assignmentId, 'Birinci yanıt', 2, true);

        $pdfTwo = $this->minimalPdf('ikinci-farkli');
        $this->submissions()->saveDraft($students[1]->getUser(), $assignmentId, 'İkinci yanıt', 0);
        $attTwo = $this->attachments()->attach($students[1]->getUser(), $assignmentId, $pdfTwo, 'Iki.pdf', 1);
        $this->createdDurableKeys[] = $attTwo->getStorageKey();
        $subTwo = $this->submissions()->submit($students[1]->getUser(), $assignmentId, 'İkinci yanıt', 2, true);

        $this->submissions()->saveDraft($students[2]->getUser(), $assignmentId, 'Metin yanıt', 0);
        $subThree = $this->submissions()->submit($students[2]->getUser(), $assignmentId, 'Metin yanıt', 1, true);

        $this->bootClosedGate();
        $teacher = $this->clientFor($ctx['teacher']->getEmail());

        $teacher->request('GET', '/ogretmen/odevler/teslim/'.$subOne->getId()->toRfc4122().'/pdf');
        self::assertResponseIsSuccessful();
        $respOne = $teacher->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $respOne);
        self::assertStringContainsString('Bir.pdf', (string) $respOne->headers->get('Content-Disposition'));
        ob_start();
        $respOne->sendContent();
        self::assertSame($pdfOne, ob_get_clean());

        $teacher->request('GET', '/ogretmen/odevler/teslim/'.$subTwo->getId()->toRfc4122().'/pdf');
        self::assertResponseIsSuccessful();
        $respTwo = $teacher->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $respTwo);
        self::assertStringContainsString('Iki.pdf', (string) $respTwo->headers->get('Content-Disposition'));
        ob_start();
        $respTwo->sendContent();
        self::assertSame($pdfTwo, ob_get_clean());

        $teacher->request('GET', '/ogretmen/odevler/teslim/'.$subThree->getId()->toRfc4122().'/pdf');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingOrCorruptedDurableFileReturnsControlled404WithoutPathLeak(): void
    {
        $s = $this->scenarioWithPdf('thw-pdf-miss');
        $this->bootClosedGate();
        $durablePath = $this->durable()->absolutePath($s['storageKey']);
        self::assertFileExists($durablePath);
        @unlink($durablePath);
        self::assertFileDoesNotExist($durablePath);

        $teacher = $this->clientFor($s['teacherEmail']);
        $detailPath = '/ogretmen/odevler/teslim/'.$s['submissionId'];
        $teacher->request('GET', $detailPath);
        self::assertResponseIsSuccessful();
        $this->assertNoPdfOrPathLeak($teacher, $s['storageKey']);

        $teacher->request('GET', $detailPath.'/pdf');
        self::assertResponseStatusCodeSame(404);
        $this->assertNoPdfOrPathLeak($teacher, $s['storageKey']);

        // Corrupted file on disk (hash / size mismatch) also returns controlled 404 without 500 or path leak.
        file_put_contents($durablePath, $s['pdfBytes'].'corrupt');
        $teacher->request('GET', $detailPath.'/pdf');
        self::assertResponseStatusCodeSame(404);
        $this->assertNoPdfOrPathLeak($teacher, $s['storageKey']);
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
    }

    public function testUnsafeDisplayNameInDbFallsBackToSafeAttachmentFilename(): void
    {
        $s = $this->scenarioWithPdf('thw-pdf-name');
        $this->rebindDeliveryFixtures();
        $this->db()->executeStatement(
            'UPDATE homework_submission_attachments SET display_name = :bad WHERE submission_id = :id',
            [
                'bad' => '../kotu\\ad.pdf',
                'id' => Uuid::fromString($s['submissionId'])->toBinary(),
            ],
        );
        $this->bootClosedGate();

        $teacher = $this->clientFor($s['teacherEmail']);
        $teacher->request('GET', '/ogretmen/odevler/teslim/'.$s['submissionId'].'/pdf');
        self::assertResponseIsSuccessful();
        $disposition = (string) $teacher->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment;', $disposition);
        self::assertStringContainsString('odev.pdf', $disposition);
        self::assertStringNotContainsString('..', $disposition);
    }

    /**
     * @return array{
     *     ctx: array{owner: User, sa: User, institution: \App\Entity\Institution, year: \App\Entity\AcademicYear, classroom: \App\Entity\Classroom, teacher: User, teacherMembership: \App\Entity\InstitutionMembership, assignment: ClassroomTeacherAssignment},
     *     teacherId: Uuid,
     *     teacherEmail: string,
     *     studentEmail: string,
     *     studentMembership: \App\Entity\InstitutionMembership,
     *     classmateEmail: string|null,
     *     draftStudentEmail: string|null,
     *     draftSubmissionId: string|null,
     *     draftStorageKey: string|null,
     *     assignmentId: string,
     *     submissionId: string,
     *     storageKey: string,
     *     pdfBytes: string
     * }
     */
    private function scenarioWithPdf(string $prefix, bool $withDraftPdfStudent = false, bool $withClassmate = false): array
    {
        $ctx = $this->homeworkContext($prefix);
        $count = 1 + ($withDraftPdfStudent ? 1 : 0) + ($withClassmate ? 1 : 0);
        $students = $this->enrollStudents($ctx, $count);
        $student = $students[0]->getUser();
        $this->freezeClock(self::NOW_UTC);
        $this->frozenUtc = self::NOW_UTC;
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $assignmentId = $assignment->getId();

        $pdfBytes = $this->minimalPdf($prefix);
        $this->submissions()->saveDraft($student, $assignmentId, self::BODY, 0);
        $attachment = $this->attachments()->attach($student, $assignmentId, $pdfBytes, 'Odev.pdf', 1);
        $this->createdDurableKeys[] = $attachment->getStorageKey();
        $submission = $this->submissions()->submit($student, $assignmentId, self::BODY, 2, true);

        $idx = 1;
        $draftStudentEmail = null;
        $draftSubmissionId = null;
        $draftStorageKey = null;
        if ($withDraftPdfStudent) {
            $draftUser = $students[$idx++]->getUser();
            $draftStudentEmail = $draftUser->getEmail();
            $draftSub = $this->submissions()->saveDraft($draftUser, $assignmentId, 'Gizli taslak', 0);
            $draftAttachment = $this->attachments()->attach($draftUser, $assignmentId, $this->minimalPdf($prefix.'-draft'), 'Taslak.pdf', 1);
            $this->createdDurableKeys[] = $draftAttachment->getStorageKey();
            $draftSubmissionId = $draftSub->getId()->toRfc4122();
            $draftStorageKey = $draftAttachment->getStorageKey();
        }

        $classmateEmail = null;
        if ($withClassmate) {
            $classmateEmail = $students[$idx]->getUser()->getEmail();
        }

        return [
            'ctx' => $ctx,
            'teacherId' => $ctx['teacher']->getId(),
            'teacherEmail' => $ctx['teacher']->getEmail(),
            'studentEmail' => $student->getEmail(),
            'studentMembership' => $students[0],
            'classmateEmail' => $classmateEmail,
            'draftStudentEmail' => $draftStudentEmail,
            'draftSubmissionId' => $draftSubmissionId,
            'draftStorageKey' => $draftStorageKey,
            'assignmentId' => $assignmentId->toRfc4122(),
            'submissionId' => $submission->getId()->toRfc4122(),
            'storageKey' => $attachment->getStorageKey(),
            'pdfBytes' => $pdfBytes,
        ];
    }

    /**
     * @return array{teacherEmail: string, submissionId: string}
     */
    private function scenarioTextOnly(string $prefix): array
    {
        $ctx = $this->homeworkContext($prefix);
        $student = $this->enrollStudents($ctx, 1)[0]->getUser();
        $this->freezeClock(self::NOW_UTC);
        $this->frozenUtc = self::NOW_UTC;
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $this->submissions()->saveDraft($student, $assignment->getId(), self::BODY, 0);
        $submission = $this->submissions()->submit($student, $assignment->getId(), self::BODY, 1, true);

        return [
            'teacherEmail' => $ctx['teacher']->getEmail(),
            'submissionId' => $submission->getId()->toRfc4122(),
        ];
    }

    private function bootClosedGate(): void
    {
        $this->resetClock();
        self::ensureKernelShutdown();
        putenv('HOMEWORK_PDF_UPLOAD_ENABLED=0');
        putenv('HOMEWORK_PDF_SCAN_READY=0');
        $_ENV['HOMEWORK_PDF_UPLOAD_ENABLED'] = '0';
        $_SERVER['HOMEWORK_PDF_UPLOAD_ENABLED'] = '0';
        $_ENV['HOMEWORK_PDF_SCAN_READY'] = '0';
        $_SERVER['HOMEWORK_PDF_SCAN_READY'] = '0';
        self::bootKernel();
        $this->rebindDeliveryFixtures();
    }

    private function useStorageRoots(string $durable, string $quarantine): void
    {
        foreach (['HOMEWORK_PDF_STORAGE_DIR' => $durable, 'HOMEWORK_PDF_QUARANTINE_DIR' => $quarantine] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function downloadPdf(string $teacherEmail, string $detailPath): string
    {
        $client = $this->clientFor($teacherEmail);
        $client->request('GET', $detailPath.'/pdf');
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.\DIRECTORY_SEPARATOR.$entry);
            }
        }
        @rmdir($path);
    }

    private function clientFor(string $email): KernelBrowser
    {
        $this->resetClock();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        if (null !== $this->frozenUtc) {
            $this->freezeClock($this->frozenUtc);
        }

        return $client;
    }

    private function attachments(): HomeworkPdfAttachmentManager
    {
        $manager = static::getContainer()->get(HomeworkPdfAttachmentManager::class);
        self::assertInstanceOf(HomeworkPdfAttachmentManager::class, $manager);

        return $manager;
    }

    private function submissions(): HomeworkSubmissionManager
    {
        $manager = static::getContainer()->get(HomeworkSubmissionManager::class);
        self::assertInstanceOf(HomeworkSubmissionManager::class, $manager);

        return $manager;
    }

    private function durable(): HomeworkPdfDurableStore
    {
        $store = static::getContainer()->get(HomeworkPdfDurableStore::class);
        self::assertInstanceOf(HomeworkPdfDurableStore::class, $store);

        return $store;
    }

    private function fakeScanner(): FakeHomeworkPdfScanAdapter
    {
        $fake = static::getContainer()->get(FakeHomeworkPdfScanAdapter::class);
        self::assertInstanceOf(FakeHomeworkPdfScanAdapter::class, $fake);

        return $fake;
    }

    private function minimalPdf(string $marker = 'ok'): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".$marker;
    }

    private function db(): Connection
    {
        $this->rebindDeliveryFixtures();

        return $this->em->getConnection();
    }

    private function rowCount(string $table, string $where = '1=1'): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE '.$where);
    }

    private function assertNoPdfOrPathLeak(KernelBrowser $client, string $storageKey, ?string $filename = null): void
    {
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('%PDF', $body);
        self::assertStringNotContainsString($storageKey, $body);
        self::assertStringNotContainsString('homework-pdf', $body);
        self::assertStringNotContainsString('.bin', $body);
        if (null !== $filename && !str_contains($body, 'NotFoundHttpException') && !str_contains($body, 'sf-dump')) {
            self::assertStringNotContainsString($filename, $body);
        }
    }

    private function publicHomeworkPdfDir(): string
    {
        return \dirname(__DIR__, 2).\DIRECTORY_SEPARATOR.'public'.\DIRECTORY_SEPARATOR.'homework-pdf';
    }
}
