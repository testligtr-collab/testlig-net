<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\HomeworkAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Student homework list/detail/draft/submit over HTTP. Clock frozen at 2026-11-10 09:00 UTC after
 * login; the default homework is due 2026-11-12 18:30 Europe/Istanbul (15:30 UTC).
 */
final class StudentHomeworkHttpTest extends WebTestCase
{
    use HomeworkTestFixtures;

    private const PASSWORD = 'Guclu-Parola-123!';
    private const ABSENT_ID = '0192f000-0000-7000-8000-000000000001';
    private const NOW_UTC = '2026-11-10 09:00:00';

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

    public function testStudentSeesListAndDetailAndGetNeverWrites(): void
    {
        $s = $this->scenario('shh-read', 'Kesirler ödevi');
        $id = $s['assignment']->getId()->toRfc4122();
        $client = $this->clientFor($s['emails'][0]);

        $crawler = $client->request('GET', '/ogrenci/odevler');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ödevlerim', $html);
        self::assertStringContainsString('Kesirler ödevi', $html);
        self::assertStringContainsString($s['ctx']['classroom']->getName(), $html);
        self::assertStringContainsString('12 Kasım 2026 • 18.30', $html);
        self::assertStringContainsString('Teslim edilmedi', $html);
        self::assertSame(1, $crawler->filter('a[href="/ogrenci/odevler/'.$id.'"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/ogrenci/odevler"]')->count(), 'student menu entry');

        $crawler = $client->request('GET', '/ogrenci/odevler/'.$id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kesirler ödevi', $html);
        self::assertStringContainsString('Sayfa 12 ve 13’teki soruları çöz.', $html);
        self::assertSame(1, $crawler->filter('#shw-draft-form textarea[name="body"]')->count());
        self::assertSame('0', $crawler->filter('#shw-draft-form input[name="revision"]')->attr('value'));
        self::assertSame(1, $crawler->filter('#shw-submit-form input[name="confirm_submit"][type="checkbox"]')->count());
        self::assertSame(0, $crawler->filter('#shw-submit-form input[name="confirm_submit"][checked]')->count());

        self::assertSame(0, $this->rowCount('homework_submissions'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action LIKE 'homework_submission_%'"));
    }

    public function testDraftThenSubmitFlowUsesPrgAndLocksTheAnswer(): void
    {
        $s = $this->scenario('shh-flow');
        $id = $s['assignment']->getId()->toRfc4122();
        $detail = '/ogrenci/odevler/'.$id;
        $client = $this->clientFor($s['emails'][0]);

        $crawler = $client->request('GET', $detail);
        $draftToken = $this->draftToken($crawler);
        $client->request('POST', $detail.'/taslak', [
            '_token' => $draftToken,
            'revision' => '0',
            'body' => "Yarım yanıt\r\nikinci satır ğüşiöç",
        ]);
        self::assertResponseRedirects($detail);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Taslak kaydedildi. Ödevin henüz gönderilmedi.', (string) $client->getResponse()->getContent());
        self::assertSame("Yarım yanıt\nikinci satır ğüşiöç", $crawler->filter('#shw-draft-form textarea[name="body"]')->text(null, false));
        self::assertSame('1', $crawler->filter('#shw-draft-form input[name="revision"]')->attr('value'));
        self::assertSame(['draft', 1], [$this->db()->fetchOne('SELECT status FROM homework_submissions'), (int) $this->db()->fetchOne('SELECT revision_number FROM homework_submissions')]);
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_draft_saved'"));

        // Reload does not repeat anything.
        $client->request('GET', $detail);
        self::assertSame(1, $this->rowCount('homework_submissions'));
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_draft_saved'"));

        // Submit without the confirmation: 422, field error, still a draft.
        $token = $this->submitToken($crawler);
        $client->request('POST', $detail.'/gonder', ['_token' => $token, 'revision' => '1', 'body' => 'Nihai yanıt']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Gönderdikten sonra yanıtımı değiştiremeyeceğimi anladım', (string) $client->getResponse()->getContent());
        self::assertSame('draft', $this->db()->fetchOne('SELECT status FROM homework_submissions'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_submission_submitted'"));

        // Invalid (HTML) body with confirmation: 422, safe echo, still a draft.
        $client->request('POST', $detail.'/gonder', ['_token' => $token, 'revision' => '1', 'body' => '<script>alert(1)</script>', 'confirm_submit' => '1']);
        self::assertResponseStatusCodeSame(422);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Yanıtın HTML içeremez.', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertSame('draft', $this->db()->fetchOne('SELECT status FROM homework_submissions'));

        // Confirmed submit: PRG, flash, read-only answer.
        $client->request('POST', $detail.'/gonder', ['_token' => $token, 'revision' => '1', 'body' => 'Nihai yanıt', 'confirm_submit' => '1']);
        self::assertResponseRedirects($detail);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ödevin gönderildi.', $html);
        self::assertStringContainsString('Gönderildi', $html);
        self::assertStringContainsString('Nihai yanıt', $html);
        self::assertSame(0, $crawler->filter('#shw-draft-form')->count());
        self::assertSame(0, $crawler->filter('#shw-submit-form')->count());
        self::assertSame(['submitted', 'Nihai yanıt', 2], $this->submissionTriple());
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_submitted'"));

        // Replays and late drafts change nothing and add no success audit.
        $client->request('POST', $detail.'/gonder', ['_token' => $token, 'revision' => '2', 'body' => 'İkinci deneme', 'confirm_submit' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('zaten gönderildi', (string) $client->getResponse()->getContent());
        $client->request('POST', $detail.'/taslak', ['_token' => $draftToken, 'revision' => '2', 'body' => 'Taslağa dönüş']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('zaten gönderildi', (string) $client->getResponse()->getContent());
        self::assertSame(['submitted', 'Nihai yanıt', 2], $this->submissionTriple());
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_submitted'"));
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_draft_saved'"));

        // The list now shows the submitted state.
        $crawler = $client->request('GET', '/ogrenci/odevler');
        self::assertStringContainsString('Gönderildi', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Teslimimi gör', trim($crawler->filter('a[href="'.$detail.'"]')->text()));
    }

    public function testCsrfIsRequiredAndWritesNothing(): void
    {
        $s = $this->scenario('shh-csrf');
        $id = $s['assignment']->getId()->toRfc4122();
        $detail = '/ogrenci/odevler/'.$id;
        $client = $this->clientFor($s['emails'][0]);
        $crawler = $client->request('GET', $detail);
        $draftToken = $this->draftToken($crawler);
        $submitToken = $this->submitToken($crawler);

        $client->request('POST', $detail.'/taslak', ['_token' => 'invalid-token', 'revision' => '0', 'body' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $detail.'/taslak', ['revision' => '0', 'body' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $detail.'/gonder', ['_token' => 'invalid-token', 'revision' => '0', 'body' => 'x', 'confirm_submit' => '1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $detail.'/gonder', ['revision' => '0', 'body' => 'x', 'confirm_submit' => '1']);
        self::assertResponseStatusCodeSame(403);

        // Tokens are bound to their intent: the draft token must not submit and vice versa.
        $client->request('POST', $detail.'/gonder', ['_token' => $draftToken, 'revision' => '0', 'body' => 'x', 'confirm_submit' => '1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $detail.'/taslak', ['_token' => $submitToken, 'revision' => '0', 'body' => 'x']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->rowCount('homework_submissions'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action LIKE 'homework_submission_%'"));

        $client->request('POST', $detail.'/taslak', ['_token' => $draftToken, 'revision' => '0', 'body' => 'geçerli']);
        self::assertResponseRedirects($detail);
        self::assertSame(1, $this->rowCount('homework_submissions'));
    }

    public function testStaleRevisionPostKeepsStoredDraftAndRendersTheStoredBody(): void
    {
        $s = $this->scenario('shh-stale');
        $detail = '/ogrenci/odevler/'.$s['assignment']->getId()->toRfc4122();
        $client = $this->clientFor($s['emails'][0]);
        $crawler = $client->request('GET', $detail);
        $token = $this->draftToken($crawler);

        $client->request('POST', $detail.'/taslak', ['_token' => $token, 'revision' => '0', 'body' => 'birinci sekme']);
        self::assertResponseRedirects($detail);

        $client->request('POST', $detail.'/taslak', ['_token' => $token, 'revision' => '0', 'body' => 'eski sekme']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('başka bir yerde değiştirilmiş', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('birinci sekme', (string) $client->getResponse()->getContent());
        self::assertSame(['draft', 'birinci sekme', 1], $this->submissionTriple());
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_submission_draft_saved'"));
    }

    public function testDueDatePassedClosesWritingAndPostsAreRejected(): void
    {
        $s = $this->scenario('shh-due');
        $detail = '/ogrenci/odevler/'.$s['assignment']->getId()->toRfc4122();
        $client = $this->clientFor($s['emails'][0]);
        $crawler = $client->request('GET', $detail);
        $draftToken = $this->draftToken($crawler);
        $submitToken = $this->submitToken($crawler);

        $this->freezeClock('2026-11-12 15:30:01');
        $crawler = $client->request('GET', $detail);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Teslim süresi sona erdi', (string) $client->getResponse()->getContent());
        self::assertSame(0, $crawler->filter('#shw-draft-form')->count());
        self::assertSame(0, $crawler->filter('#shw-submit-form')->count());
        $crawler = $client->request('GET', '/ogrenci/odevler');
        self::assertStringContainsString('Teslim süresi sona erdi', (string) $client->getResponse()->getContent());

        $client->request('POST', $detail.'/taslak', ['_token' => $draftToken, 'revision' => '0', 'body' => 'geç taslak']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Teslim süresi sona erdi', (string) $client->getResponse()->getContent());
        $client->request('POST', $detail.'/gonder', ['_token' => $submitToken, 'revision' => '0', 'body' => 'geç teslim', 'confirm_submit' => '1']);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->rowCount('homework_submissions'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action LIKE 'homework_submission_%'"));
    }

    public function testForeignMissingAndMalformedIdsAreOpaqueNotFound(): void
    {
        $s = $this->scenario('shh-foreign', 'Gizli ödev başlığı');
        $other = $this->scenario('shh-foreign2', 'Başka kurum ödevi');
        $foreignId = $s['assignment']->getId()->toRfc4122();
        $ownId = $other['assignment']->getId()->toRfc4122();
        $client = $this->clientFor($other['emails'][0]);

        $crawler = $client->request('GET', '/ogrenci/odevler/'.$ownId);
        self::assertResponseIsSuccessful();
        $token = $this->draftToken($crawler);
        $submitToken = $this->submitToken($crawler);

        foreach (['/ogrenci/odevler/'.$foreignId, '/ogrenci/odevler/'.self::ABSENT_ID] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
            $body = (string) $client->getResponse()->getContent();
            // Debug exception pages can dump route params; only assert on a normal 404 body.
            if (!str_contains($body, 'NotFoundHttpException') && !str_contains($body, 'sf-dump')) {
                self::assertStringNotContainsString('Gizli ödev başlığı', $body);
                self::assertStringNotContainsString('Sayfa 12', $body);
            }
            $client->request('POST', $path.'/taslak', ['_token' => $token, 'revision' => '0', 'body' => 'sızma']);
            self::assertResponseStatusCodeSame(404, $path.'/taslak');
            $client->request('POST', $path.'/gonder', ['_token' => $submitToken, 'revision' => '0', 'body' => 'sızma', 'confirm_submit' => '1']);
            self::assertResponseStatusCodeSame(404, $path.'/gonder');
        }

        $client->request('GET', '/ogrenci/odevler/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        // A UUID that is not version 7 does not match the route.
        $client->request('GET', '/ogrenci/odevler/6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        self::assertResponseStatusCodeSame(404);

        // The list never mentions the foreign assignment either.
        $client->request('GET', '/ogrenci/odevler');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Gizli ödev başlığı', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Başka kurum ödevi', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->rowCount('homework_submissions'));
    }

    public function testClassmateOfAnotherRecipientAndLateJoinerNeverSeeTheSubmittedAnswer(): void
    {
        $s = $this->scenario('shh-mates', 'Ortak ödev', 2);
        $id = $s['assignment']->getId()->toRfc4122();
        $detail = '/ogrenci/odevler/'.$id;
        $late = $this->enrollStudents($s['ctx'], 1)[0]->getUser()->getEmail();

        $author = $this->clientFor($s['emails'][0]);
        $crawler = $author->request('GET', $detail);
        $author->request('POST', $detail.'/gonder', ['_token' => $this->submitToken($crawler), 'revision' => '0', 'body' => 'Çok özel yanıt', 'confirm_submit' => '1']);
        self::assertResponseRedirects($detail);

        $mate = $this->clientFor($s['emails'][1]);
        $mate->request('GET', $detail);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Çok özel yanıt', (string) $mate->getResponse()->getContent());
        $mate->request('GET', '/ogrenci/odevler');
        self::assertStringNotContainsString('Çok özel yanıt', (string) $mate->getResponse()->getContent());

        $joiner = $this->clientFor($late);
        $joiner->request('GET', $detail);
        self::assertResponseStatusCodeSame(404);
        $joiner->request('GET', '/ogrenci/odevler');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ortak ödev', (string) $joiner->getResponse()->getContent());
    }

    public function testTeacherRolesCannotUseTheStudentHomeworkArea(): void
    {
        $s = $this->scenario('shh-staff', 'Öğretmene kapalı ödev');
        $id = $s['assignment']->getId()->toRfc4122();
        $this->activeUser('shh-staff-pure-teacher@example.com', UserRole::Teacher);
        $expert = $this->activeUser('shh-staff-expert@example.com', UserRole::ExpertTeacher);
        unset($expert);

        // Global teacher roles (no ROLE_STUDENT) are stopped by access_control.
        foreach (['shh-staff-pure-teacher@example.com', 'shh-staff-expert@example.com'] as $email) {
            $client = $this->clientFor($email);
            foreach (['/ogrenci/odevler', '/ogrenci/odevler/'.$id] as $path) {
                $client->request('GET', $path);
                $this->assertDenied($client, $email.' '.$path);
            }
            foreach (['/taslak', '/gonder'] as $suffix) {
                $client->request('POST', '/ogrenci/odevler/'.$id.$suffix, ['_token' => 'x', 'revision' => '0', 'body' => 'x', 'confirm_submit' => '1']);
                $this->assertDenied($client, $email.' POST '.$suffix);
            }
        }

        // The assigning teacher account (fixture gives it ROLE_STUDENT too) is no recipient: opaque 404.
        $client = $this->clientFor('shh-staff-teacher@example.com');
        $crawler = $client->request('GET', '/ogrenci/odevler');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Öğretmene kapalı ödev', (string) $client->getResponse()->getContent());
        self::assertSame(0, $crawler->filter('a[href="/ogrenci/odevler/'.$id.'"]')->count());
        $client->request('GET', '/ogrenci/odevler/'.$id);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->rowCount('homework_submissions'));
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $s = $this->scenario('shh-anon');
        self::ensureKernelShutdown();
        $client = static::createClient();

        foreach (['/ogrenci/odevler', '/ogrenci/odevler/'.$s['assignment']->getId()->toRfc4122()] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects(null, null, $path);
            self::assertStringContainsString('/giris', (string) $client->getResponse()->headers->get('Location'));
        }
        $client->request('POST', '/ogrenci/odevler/'.$s['assignment']->getId()->toRfc4122().'/taslak', ['_token' => 'x', 'revision' => '0', 'body' => 'x']);
        self::assertResponseRedirects();
        self::assertSame(0, $this->rowCount('homework_submissions'));
    }

    /**
     * Teacher context with $students enrolled students and one assigned homework.
     *
     * @return array{
     *     ctx: array{owner: User, sa: User, institution: \App\Entity\Institution, year: \App\Entity\AcademicYear, classroom: \App\Entity\Classroom, teacher: User, teacherMembership: InstitutionMembership},
     *     students: list<InstitutionMembership>,
     *     emails: list<string>,
     *     assignment: HomeworkAssignment
     * }
     */
    private function scenario(string $prefix, string $title = 'Kesirler çalışma kâğıdı', int $students = 1): array
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

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function submissionTriple(): array
    {
        $row = $this->db()->fetchAssociative('SELECT status, body, revision_number FROM homework_submissions');
        self::assertIsArray($row);

        return [(string) $row['status'], (string) $row['body'], (int) $row['revision_number']];
    }

    private function draftToken(Crawler $crawler): string
    {
        return (string) $crawler->filter('#shw-draft-form input[name="_token"]')->attr('value');
    }

    private function submitToken(Crawler $crawler): string
    {
        return (string) $crawler->filter('#shw-submit-form input[name="_token"]')->attr('value');
    }

    private function assertDenied(KernelBrowser $client, string $message): void
    {
        $status = $client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status, $message);
        self::assertContains($status, [302, 403, 404], $message);
        if (302 === $status) {
            self::assertStringNotContainsString('/ogrenci/odevler', (string) $client->getResponse()->headers->get('Location'), $message);
        }
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Öğretmene kapalı ödev', $body, $message);
    }
}
