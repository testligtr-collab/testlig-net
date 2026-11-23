<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Service\Homework\HomeworkPdfAttachmentManager;
use App\Service\Homework\HomeworkSubmissionManager;
use App\Tests\Homework\Pdf\FakeHomeworkPdfScanAdapter;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Teacher private PDF download for submitted homework (upload gate only needed to seed the file).
 */
final class TeacherHomeworkPdfHttpTest extends WebTestCase
{
    use HomeworkTestFixtures;

    private const PASSWORD = 'Guclu-Parola-123!';
    private const NOW_UTC = '2026-11-10 09:00:00';
    private const BODY = 'PDF ekli teslim metni';

    private ?string $frozenUtc = null;

    protected function setUp(): void
    {
        $this->frozenUtc = null;
        FakeHomeworkPdfScanAdapter::forceVerdict(null);
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
        $this->cleanupHomework();
        parent::tearDown();
    }

    public function testTeacherSeesPdfAndCanDownloadSubmittedAttachment(): void
    {
        $s = $this->scenarioWithPdf('thw-pdf-ok');
        $client = $this->clientFor($s['teacherEmail']);
        $path = '/ogretmen/odevler/teslim/'.$s['submissionId'];

        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="hw-submission-pdf"]')->count());
        self::assertStringContainsString('Odev.pdf', $crawler->filter('[data-testid="hw-submission-pdf-name"]')->text());
        self::assertSame(
            $path.'/pdf',
            $crawler->filter('[data-testid="hw-submission-pdf-download"]')->attr('href'),
        );

        $client->request('GET', $path.'/pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
    }

    public function testSubmissionWithoutPdfHidesBlock(): void
    {
        $s = $this->scenarioTextOnly('thw-pdf-none');
        $client = $this->clientFor($s['teacherEmail']);
        $crawler = $client->request('GET', '/ogretmen/odevler/teslim/'.$s['submissionId']);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-testid="hw-submission-pdf"]')->count());
        $client->request('GET', '/ogretmen/odevler/teslim/'.$s['submissionId'].'/pdf');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
    }

    public function testOtherTeacherCannotDownload(): void
    {
        $s = $this->scenarioWithPdf('thw-pdf-own');
        $outsiderCtx = $this->homeworkContext('thw-pdf-out');
        $outsiderEmail = $outsiderCtx['teacher']->getEmail();

        $client = $this->clientFor($outsiderEmail);
        $client->request('GET', '/ogretmen/odevler/teslim/'.$s['submissionId'].'/pdf');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_downloaded'"));
    }

    /**
     * @return array{teacherEmail: string, submissionId: string}
     */
    private function scenarioWithPdf(string $prefix): array
    {
        $ctx = $this->homeworkContext($prefix);
        $student = $this->enrollStudents($ctx, 1)[0]->getUser();
        $this->freezeClock(self::NOW_UTC);
        $this->frozenUtc = self::NOW_UTC;
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $assignmentId = $assignment->getId();

        $this->submissions()->saveDraft($student, $assignmentId, self::BODY, 0);
        $this->attachments()->attach($student, $assignmentId, $this->minimalPdf($prefix), 'Odev.pdf', 1);
        $submission = $this->submissions()->submit($student, $assignmentId, self::BODY, 2, true);

        return [
            'teacherEmail' => $ctx['teacher']->getEmail(),
            'submissionId' => $submission->getId()->toRfc4122(),
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
}
