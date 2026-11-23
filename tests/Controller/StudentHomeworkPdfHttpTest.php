<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\HomeworkAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Tests\Homework\Pdf\ControllableHomeworkPdfDurableStore;
use App\Tests\Homework\Pdf\FakeHomeworkPdfScanAdapter;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StudentHomeworkPdfHttpTest extends WebTestCase
{
    use HomeworkTestFixtures;

    private const PASSWORD = 'Guclu-Parola-123!';
    private const NOW_UTC = '2026-11-10 09:00:00';

    private ?string $frozenUtc = null;

    protected function setUp(): void
    {
        $this->frozenUtc = null;
        FakeHomeworkPdfScanAdapter::forceVerdict(null);
        ControllableHomeworkPdfDurableStore::resetFailNextCommit();
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
    }

    protected function tearDown(): void
    {
        FakeHomeworkPdfScanAdapter::forceVerdict(null);
        ControllableHomeworkPdfDurableStore::resetFailNextCommit();
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

    public function testClosedGateHidesFormAndRejectsPost(): void
    {
        $s = $this->scenario('pdfhttp-closed');
        $id = $s['assignment']->getId()->toRfc4122();
        $client = $this->clientFor($s['emails'][0]);

        $crawler = $client->request('GET', '/ogrenci/odevler/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-testid="shw-pdf-form"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="shw-pdf"]')->count());

        $pdf = $this->tempPdfFile();
        $client->request('POST', '/ogrenci/odevler/'.$id.'/pdf', [
            '_token' => 'invalid',
            'revision' => '0',
            'body' => '',
        ], ['pdf' => $pdf]);
        self::assertContains($client->getResponse()->getStatusCode(), [403, 422]);
        self::assertSame(0, $this->rowCount('homework_submission_attachments'));
    }

    public function testOpenGateAttachesPdfAndKeepsDraft(): void
    {
        $this->bootOpenGate();
        $s = $this->scenario('pdfhttp-open');
        $id = $s['assignment']->getId()->toRfc4122();
        $detail = '/ogrenci/odevler/'.$id;
        $client = $this->clientFor($s['emails'][0]);

        $crawler = $client->request('GET', $detail);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="shw-pdf-form"]')->count());

        $client->request('POST', $detail.'/taslak', [
            '_token' => (string) $crawler->filter('#shw-draft-form input[name="_token"]')->attr('value'),
            'revision' => '0',
            'body' => 'Metin taslak',
        ]);
        self::assertResponseRedirects($detail);
        $crawler = $client->followRedirect();
        $token = (string) $crawler->filter('#shw-pdf-form input[name="_token"]')->attr('value');
        $revision = (string) $crawler->filter('#shw-pdf-form input[name="revision"]')->attr('value');

        $client->request('POST', $detail.'/pdf', [
            '_token' => $token,
            'revision' => $revision,
            'body' => 'Metin taslak',
        ], ['pdf' => $this->tempPdfFile('Odev.pdf')]);
        self::assertResponseRedirects($detail);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('PDF eklendi. Ödevin henüz gönderilmedi.', $html);
        self::assertStringContainsString('Odev.pdf', $html);
        self::assertSame(0, $crawler->filter('[data-testid="shw-pdf-form"]')->count());
        self::assertSame(1, $this->rowCount('homework_submission_attachments'));
        self::assertSame('draft', (string) $this->db()->fetchOne('SELECT status FROM homework_submissions'));
    }

    public function testInfectedUploadCreatesNoRow(): void
    {
        $this->bootOpenGate();
        $s = $this->scenario('pdfhttp-inf');
        $id = $s['assignment']->getId()->toRfc4122();
        $detail = '/ogrenci/odevler/'.$id;
        $client = $this->clientFor($s['emails'][0]);
        $client->disableReboot();
        $crawler = $client->request('GET', $detail);
        $client->request('POST', $detail.'/taslak', [
            '_token' => (string) $crawler->filter('#shw-draft-form input[name="_token"]')->attr('value'),
            'revision' => '0',
            'body' => 'Metin',
        ]);
        $crawler = $client->followRedirect();
        FakeHomeworkPdfScanAdapter::forceVerdict(HomeworkPdfScanVerdict::Infected);
        $this->fakeScanner()->setVerdict(HomeworkPdfScanVerdict::Infected);

        $client->request('POST', $detail.'/pdf', [
            '_token' => (string) $crawler->filter('#shw-pdf-form input[name="_token"]')->attr('value'),
            'revision' => (string) $crawler->filter('#shw-pdf-form input[name="revision"]')->attr('value'),
            'body' => 'Metin',
        ], ['pdf' => $this->tempPdfFile()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rowCount('homework_submission_attachments'));
    }

    public function testDurableFailureReturns422WithoutChangingDraft(): void
    {
        $this->bootOpenGate();
        $s = $this->scenario('pdfhttp-dur');
        $id = $s['assignment']->getId()->toRfc4122();
        $detail = '/ogrenci/odevler/'.$id;
        $client = $this->clientFor($s['emails'][0]);
        $client->disableReboot();
        $crawler = $client->request('GET', $detail);
        $client->request('POST', $detail.'/taslak', [
            '_token' => (string) $crawler->filter('#shw-draft-form input[name="_token"]')->attr('value'),
            'revision' => '0',
            'body' => 'Korunan http',
        ]);
        $crawler = $client->followRedirect();
        ControllableHomeworkPdfDurableStore::armFailNextCommit();
        $this->controllableDurable()->failNextCommit();

        $client->request('POST', $detail.'/pdf', [
            '_token' => (string) $crawler->filter('#shw-pdf-form input[name="_token"]')->attr('value'),
            'revision' => (string) $crawler->filter('#shw-pdf-form input[name="revision"]')->attr('value'),
            'body' => 'Korunan http',
        ], ['pdf' => $this->tempPdfFile('Dur.pdf')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rowCount('homework_submission_attachments'));
        self::assertSame('Korunan http', (string) $this->db()->fetchOne('SELECT body FROM homework_submissions'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT revision_number FROM homework_submissions'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_submission_pdf_attached'"));
    }

    private function bootOpenGate(): void
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

    private function fakeScanner(): FakeHomeworkPdfScanAdapter
    {
        $fake = static::getContainer()->get(FakeHomeworkPdfScanAdapter::class);
        self::assertInstanceOf(FakeHomeworkPdfScanAdapter::class, $fake);

        return $fake;
    }

    private function controllableDurable(): ControllableHomeworkPdfDurableStore
    {
        $store = static::getContainer()->get(ControllableHomeworkPdfDurableStore::class);
        self::assertInstanceOf(ControllableHomeworkPdfDurableStore::class, $store);

        return $store;
    }

    private function tempPdfFile(string $name = 'odev.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hwpdf');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /**
     * @return array{
     *     ctx: array{owner: User, sa: User, institution: \App\Entity\Institution, year: \App\Entity\AcademicYear, classroom: \App\Entity\Classroom, teacher: User, teacherMembership: InstitutionMembership},
     *     students: list<InstitutionMembership>,
     *     emails: list<string>,
     *     assignment: HomeworkAssignment
     * }
     */
    private function scenario(string $prefix, string $title = 'PDF ödev', int $students = 1): array
    {
        $ctx = $this->homeworkContext($prefix);
        $memberships = $this->enrollStudents($ctx, $students);
        $this->freezeClock(self::NOW_UTC);
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom'], title: $title));
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $this->frozenUtc = self::NOW_UTC;
        $emails = array_map(static fn (InstitutionMembership $membership): string => $membership->getUser()->getEmail(), $memberships);

        return ['ctx' => $ctx, 'students' => $memberships, 'emails' => $emails, 'assignment' => $assignment];
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
