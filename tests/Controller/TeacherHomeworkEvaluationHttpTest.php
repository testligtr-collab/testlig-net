<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ClassroomTeacherAssignment;
use App\Entity\HomeworkAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher homework submission review and evaluation over HTTP. Clock frozen at 2026-11-10 09:00 UTC
 * after login; the default homework is due 2026-11-12 18:30 Europe/Istanbul (15:30 UTC).
 */
final class TeacherHomeworkEvaluationHttpTest extends WebTestCase
{
    use HomeworkTestFixtures;

    private const PASSWORD = 'Guclu-Parola-123!';
    private const ABSENT_ID = '0192f000-0000-7000-8000-000000000001';
    private const NOW_UTC = '2026-11-10 09:00:00';
    private const BODY = 'Birinci öğrencinin yanıtı';

    private ?string $frozenUtc = null;

    protected function setUp(): void
    {
        $this->frozenUtc = null;
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
        parent::tearDown();
    }

    public function testSubmissionPageShowsTheSubmittedAnswerAndFormAndGetNeverWrites(): void
    {
        $s = $this->scenario('hwev-read', 2, draftForSecond: true);
        $client = $this->clientFor($s['teacherEmail']);
        $path = $this->submissionPath($s['submissionId']);

        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(self::BODY, $crawler->filter('[data-testid="hw-submission-body"]')->text());
        self::assertStringContainsString('Sayfa 12 ve 13’teki soruları çöz.', $crawler->filter('[data-testid="hw-frozen-prompt"]')->text());
        self::assertStringContainsString('Henüz değerlendirilmedi.', $crawler->filter('[data-testid="hw-current-evaluation"]')->text());
        self::assertSame('Değerlendir', trim($crawler->filter('#hw-eval-heading')->text()));
        self::assertSame(
            '/ogretmen/odevler/teslim/'.$s['submissionId'].'/degerlendir',
            $crawler->filter('form.hw-eval-form')->attr('action'),
        );
        self::assertSame('post', strtolower((string) $crawler->filter('form.hw-eval-form')->attr('method')));
        self::assertSame(['reviewed', 'needs_rework'], $crawler->filter('input[name="label"]')->each(static fn (Crawler $node): string => (string) $node->attr('value')));
        self::assertSame('', $crawler->filter('input[name="expected_current_evaluation_id"]')->attr('value'));
        self::assertNotSame('', $this->evalToken($crawler));
        self::assertSame(0, $crawler->filter('input[name="label"][checked]')->count());
        self::assertStringContainsString('Bu değerlendirme yeniden teslim hakkı vermez.', $html);
        self::assertStringNotContainsString('Gizli taslak metni', $html);
        // No numeric score control exists: the evaluation is a label plus text.
        self::assertSame(0, $crawler->filter('input[type="number"], input[name="score"], input[name="points"]')->count());

        // Reloading never writes.
        $client->request('GET', $path);
        $client->request('GET', $path);
        self::assertSame(0, $this->rowCount('homework_evaluations'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));

        // The assignment detail lists real counters and links only to the submitted answer.
        $crawler = $client->request('GET', '/ogretmen/odevler/'.$s['definitionId']);
        self::assertResponseIsSuccessful();
        self::assertSame('2', trim($crawler->filter('[data-testid="hw-count-recipients"]')->text()));
        self::assertSame('1', trim($crawler->filter('[data-testid="hw-count-submitted"]')->text()));
        self::assertSame('0', trim($crawler->filter('[data-testid="hw-count-evaluated"]')->text()));
        self::assertSame(1, $crawler->filter('.hw-recipient[data-state="submitted"]')->count());
        self::assertSame(1, $crawler->filter('.hw-recipient[data-state="awaiting"]')->count());
        self::assertSame(1, $crawler->filter('a[href="'.$path.'"]')->count());
        self::assertSame(1, $crawler->filter('.hw-recipient a[href^="/ogretmen/odevler/teslim/"]')->count(), 'a draft or empty recipient has no inspect link');
        self::assertStringNotContainsString('Gizli taslak metni', (string) $client->getResponse()->getContent());
    }

    public function testEvaluatePostRedirectsThenCorrectionAppendsAndReplayIsStale(): void
    {
        $s = $this->scenario('hwev-flow', 2);
        $id = $s['submissionId'];
        $path = $this->submissionPath($s['submissionId']);
        $client = $this->clientFor($s['teacherEmail']);
        $crawler = $client->request('GET', $path);
        $token = $this->evalToken($crawler);

        // First evaluation: PRG, flash, current evaluation rendered, one row + pointer + one audit.
        $client->request('POST', $path.'/degerlendir', [
            '_token' => $token,
            'expected_current_evaluation_id' => '',
            'label' => 'reviewed',
            'feedback' => "Aferin!\r\nİkinci satır ğüşiöç",
        ]);
        self::assertResponseRedirects($path);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Değerlendirme kaydedildi. Bu değerlendirme yeniden teslim hakkı vermez.', (string) $client->getResponse()->getContent());
        $current = $crawler->filter('[data-testid="hw-current-evaluation"]')->text();
        self::assertStringContainsString('İncelendi', $current);
        self::assertStringContainsString('Aferin!', $current);
        self::assertSame('Yeni değerlendirme', trim($crawler->filter('#hw-eval-heading')->text()));
        self::assertSame('reviewed', $crawler->filter('input[name="label"][checked]')->attr('value'));
        self::assertSame("Aferin!\nİkinci satır ğüşiöç", $crawler->filter('#hw-feedback')->text(null, false));
        $first = $this->db()->fetchAssociative('SELECT id, label, feedback, supersedes_evaluation_id FROM homework_evaluations');
        self::assertIsArray($first);
        self::assertSame(['reviewed', "Aferin!\nİkinci satır ğüşiöç", null], [$first['label'], $first['feedback'], $first['supersedes_evaluation_id']]);
        $firstId = $this->uuid((string) $first['id']);
        self::assertSame($firstId, $crawler->filter('input[name="expected_current_evaluation_id"]')->attr('value'));
        self::assertSame($firstId, $this->currentPointer($id));
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));
        $this->assertSubmissionUntouched($s['submissionId']);

        // Reloading the PRG target repeats nothing.
        $client->request('GET', $path);
        self::assertSame(1, $this->rowCount('homework_evaluations'));

        // Correction: new row that supersedes the first; the first row stays as it was.
        $client->request('POST', $path.'/degerlendir', [
            '_token' => $token,
            'expected_current_evaluation_id' => $firstId,
            'label' => 'needs_rework',
            'feedback' => 'Eksikler var, tekrar bak.',
        ]);
        self::assertResponseRedirects($path);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Yeniden çalışılmalı', $crawler->filter('[data-testid="hw-current-evaluation"]')->text());
        self::assertSame(2, $this->rowCount('homework_evaluations'));
        $rows = $this->db()->fetchAllAssociative('SELECT id, label, feedback, supersedes_evaluation_id FROM homework_evaluations ORDER BY recorded_at ASC, id ASC');
        self::assertSame($firstId, $this->uuid((string) $rows[0]['id']));
        self::assertSame(['reviewed', "Aferin!\nİkinci satır ğüşiöç"], [$rows[0]['label'], $rows[0]['feedback']]);
        self::assertSame($firstId, $this->uuid((string) $rows[1]['supersedes_evaluation_id']));
        $secondId = $this->uuid((string) $rows[1]['id']);
        self::assertSame($secondId, $this->currentPointer($id));
        self::assertSame(2, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));
        $this->assertSubmissionUntouched($s['submissionId']);

        // A stale tab (still holds the old expected id) gets a conflict, nothing is written, and the
        // re-rendered form carries the fresh current id so a deliberate retry can succeed.
        $crawler = $client->request('POST', $path.'/degerlendir', [
            '_token' => $token,
            'expected_current_evaluation_id' => $firstId,
            'label' => 'reviewed',
            'feedback' => 'Eski sekmeden gelen.',
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('başka bir yerde değerlendirilmiş', (string) $client->getResponse()->getContent());
        self::assertSame($secondId, $crawler->filter('input[name="expected_current_evaluation_id"]')->attr('value'));
        self::assertSame(2, $this->rowCount('homework_evaluations'));
        self::assertSame($secondId, $this->currentPointer($id));
        self::assertSame(2, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));

        // Counters on the assignment detail follow the current pointer only.
        $crawler = $client->request('GET', '/ogretmen/odevler/'.$s['definitionId']);
        self::assertSame('1', trim($crawler->filter('[data-testid="hw-count-submitted"]')->text()));
        self::assertSame('1', trim($crawler->filter('[data-testid="hw-count-evaluated"]')->text()));
        self::assertSame(1, $crawler->filter('.hw-recipient[data-state="evaluated"]')->count());
        self::assertStringContainsString('Yeniden çalışılmalı', $crawler->filter('.hw-recipient[data-state="evaluated"]')->text());
    }

    public function testInvalidEvaluationRendersFieldErrorsKeepsInputAndWritesNothing(): void
    {
        $s = $this->scenario('hwev-invalid');
        $path = $this->submissionPath($s['submissionId']);
        $client = $this->clientFor($s['teacherEmail']);
        $token = $this->evalToken($client->request('GET', $path));
        $base = ['_token' => $token, 'expected_current_evaluation_id' => ''];

        // Missing / unknown label.
        foreach ([[], ['label' => ''], ['label' => 'approved'], ['label' => '10']] as $label) {
            $client->request('POST', $path.'/degerlendir', $base + $label + ['feedback' => 'Kalsın']);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Geçerli bir değerlendirme etiketi seç.', (string) $client->getResponse()->getContent());
        }

        // needs_rework without feedback.
        $crawler = $client->request('POST', $path.'/degerlendir', $base + ['label' => 'needs_rework', 'feedback' => "  \r\n "]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Geri bildirim boş olamaz.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('needs_rework', $crawler->filter('input[name="label"][checked]')->attr('value'), 'the chosen label is kept');
        self::assertSame(1, $crawler->filter('.hw-alert')->count());
        self::assertSame('true', $crawler->filter('#hw-feedback')->attr('aria-invalid'));

        // HTML is rejected, never stripped; the input is echoed escaped.
        $crawler = $client->request('POST', $path.'/degerlendir', $base + ['label' => 'reviewed', 'feedback' => 'Güzel <script>alert(1)</script> & "tırnak"']);
        self::assertResponseStatusCodeSame(422);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Geri bildirim HTML içeremez.', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertSame('Güzel <script>alert(1)</script> & "tırnak"', $crawler->filter('#hw-feedback')->text(null, false));

        // One code point over the limit.
        $client->request('POST', $path.'/degerlendir', $base + ['label' => 'reviewed', 'feedback' => str_repeat("\u{1F600}", 5001)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('en fazla 5.000 karakter', (string) $client->getResponse()->getContent());

        self::assertSame(0, $this->rowCount('homework_evaluations'));
        self::assertNull($this->currentPointer($s['submissionId']));
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));
        $this->assertSubmissionUntouched($s['submissionId']);
    }

    public function testCsrfIsRequiredAndAForgedExpectedIdIsABadRequest(): void
    {
        $s = $this->scenario('hwev-csrf');
        $path = $this->submissionPath($s['submissionId']);
        $client = $this->clientFor($s['teacherEmail']);
        $token = $this->evalToken($client->request('GET', $path));
        $draftIntentToken = (string) $client->request('GET', '/ogretmen/odevler/yeni')->filter('form.hw-form input[name="_token"]')->attr('value');
        $valid = ['expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'x'];

        $client->request('POST', $path.'/degerlendir', ['_token' => 'invalid-token'] + $valid);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $path.'/degerlendir', $valid);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $path.'/degerlendir', ['_token' => ''] + $valid);
        self::assertResponseStatusCodeSame(403);
        // A token minted for another form (intent) must not authorize an evaluation.
        $client->request('POST', $path.'/degerlendir', ['_token' => $draftIntentToken] + $valid);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rowCount('homework_evaluations'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));

        // Valid token, forged expected id: a bad request, still nothing written.
        $client->request('POST', $path.'/degerlendir', ['_token' => $token, 'expected_current_evaluation_id' => 'not-a-uuid'] + $valid);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->rowCount('homework_evaluations'));

        // Only POST evaluates: a GET on the action URL is not routed.
        $client->request('GET', $path.'/degerlendir');
        self::assertResponseStatusCodeSame(405);
        self::assertSame(0, $this->rowCount('homework_evaluations'));

        $client->request('POST', $path.'/degerlendir', ['_token' => $token] + $valid);
        self::assertResponseRedirects($path);
        self::assertSame(1, $this->rowCount('homework_evaluations'));
    }

    public function testForeignDraftMissingAndMalformedSubmissionsAreOpaqueNotFound(): void
    {
        $s = $this->scenario('hwev-foreign', 2, draftForSecond: true);
        $other = $this->scenario('hwev-foreign2', 1);
        $co = $this->addCoTeacher($s['ctx'], 'hwev-foreign-co@example.com');
        $admin = $this->activeUser('hwev-foreign-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        unset($co);
        $path = $this->submissionPath($s['submissionId']);
        $draftPath = $this->submissionPath($s['draftId']);

        // A foreign teacher with a perfectly valid CSRF token still gets an opaque 404.
        $foreign = $this->clientFor($other['teacherEmail']);
        $foreignToken = $this->evalToken($foreign->request('GET', $this->submissionPath($other['submissionId'])));
        $foreign->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
        $this->assertNoLeak($foreign, self::BODY);
        $foreign->request('POST', $path.'/degerlendir', ['_token' => $foreignToken, 'expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'Yabancı kurum']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->rowCount('homework_evaluations'));

        // Roles with no teaching scope over this homework: co-teacher, admin, super admin, owner, student.
        foreach (['hwev-foreign-co@example.com', 'hwev-foreign-admin@example.com', 'hwev-foreign-sa@example.com', 'hwev-foreign-owner@example.com', $s['studentEmails'][0]] as $email) {
            $client = $this->clientFor($email);
            $client->request('GET', $path);
            self::assertContains($client->getResponse()->getStatusCode(), [403, 404], $email.' GET');
            self::assertNotSame(200, $client->getResponse()->getStatusCode(), $email.' GET');
            $this->assertNoLeak($client, self::BODY);
            $client->request('POST', $path.'/degerlendir', ['_token' => 'x', 'expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'Yetkisiz']);
            self::assertContains($client->getResponse()->getStatusCode(), [403, 404], $email.' POST');
        }

        // The assigning teacher: a student draft is not reviewable (GET and POST alike) and the draft
        // text never appears; missing and malformed ids are plain 404s.
        $teacher = $this->clientFor($s['teacherEmail']);
        $token = $this->evalToken($teacher->request('GET', $path));
        $teacher->request('GET', $draftPath);
        self::assertResponseStatusCodeSame(404);
        $this->assertNoLeak($teacher, 'Gizli taslak metni');
        $teacher->request('POST', $draftPath.'/degerlendir', ['_token' => $token, 'expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'Taslak']);
        self::assertResponseStatusCodeSame(404);
        foreach (['/ogretmen/odevler/teslim/'.self::ABSENT_ID, '/ogretmen/odevler/teslim/not-a-uuid', '/ogretmen/odevler/teslim/6ba7b810-9dad-11d1-80b4-00c04fd430c8'] as $missing) {
            $teacher->request('GET', $missing);
            self::assertResponseStatusCodeSame(404, $missing);
            $teacher->request('POST', $missing.'/degerlendir', ['_token' => $token, 'expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'x']);
            self::assertResponseStatusCodeSame(404, $missing);
        }

        self::assertSame(0, $this->rowCount('homework_evaluations'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));
        self::assertSame(1, $this->rowCount('homework_submissions', "status = 'draft'"), 'the draft is untouched');
    }

    public function testStudentsAndAnonymousVisitorsCannotUseTheTeacherEvaluationRoutes(): void
    {
        $s = $this->scenario('hwev-student', 2);
        $path = $this->submissionPath($s['submissionId']);
        $teacher = $this->clientFor($s['teacherEmail']);
        $token = $this->evalToken($teacher->request('GET', $path));

        // The author and a classmate: teacher routes are closed, whatever the CSRF token.
        foreach ($s['studentEmails'] as $email) {
            $client = $this->clientFor($email);
            foreach (['/ogretmen/odevler', $path] as $url) {
                $client->request('GET', $url);
                $this->assertDenied($client, $email.' '.$url);
            }
            $client->request('POST', $path.'/degerlendir', ['_token' => $token, 'expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'Kendime not']);
            $this->assertDenied($client, $email.' POST');
        }

        self::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('GET', $path);
        self::assertResponseRedirects();
        self::assertStringContainsString('/giris', (string) $anonymous->getResponse()->headers->get('Location'));
        $anonymous->request('POST', $path.'/degerlendir', ['_token' => 'x', 'expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'x']);
        self::assertResponseRedirects();

        self::assertSame(0, $this->rowCount('homework_evaluations'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));
    }

    public function testEndedTeacherAssignmentClosesTheSubmissionPageAndThePost(): void
    {
        $s = $this->scenario('hwev-ended');
        $path = $this->submissionPath($s['submissionId']);
        $client = $this->clientFor($s['teacherEmail']);
        $token = $this->evalToken($client->request('GET', $path));

        // The browser kernel replaced the container: reload the fixtures into the current EntityManager.\n        $this->rebindDeliveryFixtures();
        $assignment = $this->em->find(ClassroomTeacherAssignment::class, $s['ctx']['assignment']->getId());
        $owner = $this->users->findOneById($s['ctx']['owner']->getId());
        self::assertInstanceOf(ClassroomTeacherAssignment::class, $assignment);
        self::assertInstanceOf(User::class, $owner);
        $this->teacherManager()->endAssignment($assignment, $owner, 'ended');

        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
        $this->assertNoLeak($client, self::BODY);
        $client->request('POST', $path.'/degerlendir', ['_token' => $token, 'expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'Artık yetkim yok']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->rowCount('homework_evaluations'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_evaluation_recorded'"));
    }

    public function testStudentWhoLeftTheClassIsStillEvaluatedAndReadsTheFeedback(): void
    {
        $s = $this->scenario('hwev-left');
        $path = $this->submissionPath($s['submissionId']);
        $this->rebindDeliveryFixtures();
        $this->endStudentEnrollment($s['ctx'], $s['students'][0]);
        $client = $this->clientFor($s['teacherEmail']);

        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BODY, $crawler->filter('[data-testid="hw-submission-body"]')->text());
        $client->request('POST', $path.'/degerlendir', ['_token' => $this->evalToken($crawler), 'expected_current_evaluation_id' => '', 'label' => 'reviewed', 'feedback' => 'Ayrılsan da değerlendirdim.']);
        self::assertResponseRedirects($path);
        self::assertSame(1, $this->rowCount('homework_evaluations'));

        $student = $this->clientFor($s['studentEmails'][0]);
        $crawler = $student->request('GET', '/ogrenci/odevler/'.$s['assignmentId']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ayrılsan da değerlendirdim.', $crawler->filter('[data-testid="shw-evaluation"]')->text());
        self::assertSame(0, $crawler->filter('#shw-draft-form')->count());
        self::assertSame(0, $crawler->filter('#shw-submit-form')->count());
    }

    public function testStudentSeesOwnFeedbackEmptyStateAndNoResubmitAndNeverAnotherStudents(): void
    {
        $s = $this->scenario('hwev-view', 3, draftForThird: true);
        $assignmentId = $s['assignmentId'];
        $secondSubmission = $this->studentSubmit($s['users'][1], $s['assignment'], 'İkinci öğrencinin yanıtı');
        $teacher = $this->clientFor($s['teacherEmail']);
        $crawler = $teacher->request('GET', $this->submissionPath($s['submissionId']));
        $teacher->request('POST', $this->submissionPath($s['submissionId']).'/degerlendir', [
            '_token' => $this->evalToken($crawler),
            'expected_current_evaluation_id' => '',
            'label' => 'needs_rework',
            'feedback' => "Satır 1 & \"tırnak\"\nSatır 2 'tek'",
        ]);
        self::assertResponseRedirects();

        // Evaluated student: label, escaped multi-line feedback, the no-resubmit note, no write forms.
        $author = $this->clientFor($s['studentEmails'][0]);
        $crawler = $author->request('GET', '/ogrenci/odevler/'.$assignmentId);
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="shw-evaluation"]');
        self::assertSame(1, $card->count());
        self::assertStringContainsString('Yeniden çalışılmalı', $card->text());
        self::assertStringContainsString('Satır 1 & "tırnak"', $card->text());
        self::assertStringContainsString('Satır 2', $card->text());
        self::assertStringContainsString('Bu değerlendirme yeniden teslim hakkı vermez.', $card->text());
        $html = (string) $author->getResponse()->getContent();
        self::assertStringContainsString('Satır 1 &amp; &quot;tırnak&quot;<br />', $html);
        self::assertSame(0, $crawler->filter('#shw-draft-form')->count());
        self::assertSame(0, $crawler->filter('#shw-submit-form')->count());
        self::assertSame(0, $crawler->filter('[data-testid="shw-evaluation"] a, [data-testid="shw-evaluation"] button, [data-testid="shw-evaluation"] form')->count());

        // Submitted but not yet evaluated: the empty state, never somebody else's feedback.
        $second = $this->clientFor($s['studentEmails'][1]);
        $crawler = $second->request('GET', '/ogrenci/odevler/'.$assignmentId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Henüz değerlendirilmedi.', $crawler->filter('[data-testid="shw-evaluation"]')->text());
        $secondHtml = (string) $second->getResponse()->getContent();
        self::assertStringNotContainsString('Yeniden çalışılmalı', $secondHtml);
        self::assertStringNotContainsString('Satır 1', $secondHtml);
        self::assertStringNotContainsString(self::BODY, $secondHtml);

        // Draft only: no evaluation card at all.
        $third = $this->clientFor($s['studentEmails'][2]);
        $crawler = $third->request('GET', '/ogrenci/odevler/'.$assignmentId);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-testid="shw-evaluation"]')->count());
        self::assertStringNotContainsString('Satır 1', (string) $third->getResponse()->getContent());
        self::assertSame(1, $this->rowCount('homework_evaluations'));
        self::assertNull($this->currentPointer($secondSubmission->getId()->toRfc4122()));
    }

    public function testLongUnicodeFeedbackIsAcceptedAtTheLimitAndRenderedEscapedForTeacherAndStudent(): void
    {
        $s = $this->scenario('hwev-long');
        $path = $this->submissionPath($s['submissionId']);
        $client = $this->clientFor($s['teacherEmail']);
        $token = $this->evalToken($client->request('GET', $path));
        $feedback = str_repeat("\u{1F600}", 5000);

        $client->request('POST', $path.'/degerlendir', ['_token' => $token, 'expected_current_evaluation_id' => '', 'label' => 'needs_rework', 'feedback' => $feedback]);
        self::assertResponseRedirects($path);
        $crawler = $client->followRedirect();
        self::assertSame($feedback, $this->db()->fetchOne('SELECT feedback FROM homework_evaluations'));
        self::assertStringContainsString($feedback, (string) $client->getResponse()->getContent());
        self::assertSame('5000 / 5000', trim($crawler->filter('#hw-feedback-counter')->text()));

        $student = $this->clientFor($s['studentEmails'][0]);
        $student->request('GET', '/ogrenci/odevler/'.$s['assignmentId']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($feedback, (string) $student->getResponse()->getContent());
    }

    /**
     * Teacher context with $students enrolled students and one assigned homework. The first
     * student has a submitted answer; optionally the second (or third) one only a draft.
     *
     * @return array{
     *     ctx: array{owner: User, sa: User, institution: \App\Entity\Institution, year: \App\Entity\AcademicYear, classroom: \App\Entity\Classroom, teacher: User, teacherMembership: InstitutionMembership, assignment: ClassroomTeacherAssignment},
     *     students: list<InstitutionMembership>,
     *     users: list<User>,
     *     studentEmails: list<string>,
     *     teacherEmail: string,
     *     assignment: HomeworkAssignment,
     *     assignmentId: string,
     *     definitionId: string,
     *     submissionId: string,
     *     draftId: string|null
     * }
     */
    private function scenario(string $prefix, int $students = 1, bool $draftForSecond = false, bool $draftForThird = false): array
    {
        $ctx = $this->homeworkContext($prefix);
        $memberships = $this->enrollStudents($ctx, $students);
        $this->freezeClock(self::NOW_UTC);
        $assignment = $this->assignClassroomHomework($ctx, 'Kesirler ödevi');
        $this->frozenUtc = self::NOW_UTC;
        $users = array_map(static fn (InstitutionMembership $membership): User => $membership->getUser(), $memberships);
        $submission = $this->studentSubmit($users[0], $assignment, self::BODY."\nikinci satır");
        $draft = null;
        if ($draftForSecond) {
            $draft = $this->studentDraft($users[1], $assignment, 'Gizli taslak metni');
        }
        if ($draftForThird) {
            $draft = $this->studentDraft($users[2], $assignment, 'Gizli taslak metni');
        }

        return [
            'ctx' => $ctx,
            'students' => $memberships,
            'users' => $users,
            'studentEmails' => array_map(static fn (User $user): string => $user->getEmail(), $users),
            'teacherEmail' => $ctx['teacher']->getEmail(),
            'assignment' => $assignment,
            'assignmentId' => $assignment->getId()->toRfc4122(),
            'definitionId' => $assignment->getDefinition()->getId()->toRfc4122(),
            'submissionId' => $submission->getId()->toRfc4122(),
            'draftId' => $draft?->getId()->toRfc4122(),
        ];
    }

    private function submissionPath(?string $submissionId): string
    {
        self::assertNotNull($submissionId);

        return '/ogretmen/odevler/teslim/'.$submissionId;
    }

    private function clientFor(string $email): KernelBrowser
    {
        // Log in on real time; the fixed clock only applies to the page requests under test.
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

    private function evalToken(Crawler $crawler): string
    {
        return (string) $crawler->filter('form.hw-eval-form input[name="_token"]')->attr('value');
    }

    private function uuid(string $binary): string
    {
        return Uuid::fromBinary($binary)->toRfc4122();
    }

    private function currentPointer(string $submissionId): ?string
    {
        $value = $this->db()->fetchOne(
            'SELECT current_evaluation_id FROM homework_submissions WHERE id = :id',
            ['id' => Uuid::fromString($submissionId)->toBinary()],
        );

        return false === $value || null === $value ? null : $this->uuid((string) $value);
    }

    /**
     * Evaluating must never rewrite the student's submitted answer.
     */
    private function assertSubmissionUntouched(string $submissionId): void
    {
        $row = $this->db()->fetchAssociative(
            'SELECT body, status, revision_number, submitted_at FROM homework_submissions WHERE id = :id',
            ['id' => Uuid::fromString($submissionId)->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame([self::BODY."\nikinci satır", 'submitted', 2, self::NOW_UTC], [$row['body'], $row['status'], (int) $row['revision_number'], $row['submitted_at']]);
    }

    private function assertNoLeak(KernelBrowser $client, string $secret): void
    {
        $body = (string) $client->getResponse()->getContent();
        // Debug exception pages can dump route params; only assert on a normal error body.
        if (!str_contains($body, 'NotFoundHttpException') && !str_contains($body, 'sf-dump')) {
            self::assertStringNotContainsString($secret, $body);
        }
    }

    private function assertDenied(KernelBrowser $client, string $message): void
    {
        $status = $client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status, $message);
        self::assertContains($status, [302, 403, 404], $message);
        if (302 === $status) {
            self::assertStringNotContainsString('/ogretmen/odevler', (string) $client->getResponse()->headers->get('Location'), $message);
        }
        $this->assertNoLeak($client, self::BODY);
    }
}
