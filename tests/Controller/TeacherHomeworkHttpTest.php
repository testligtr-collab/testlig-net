<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\GradeLevel;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class TeacherHomeworkHttpTest extends WebTestCase
{
    use HomeworkTestFixtures;

    private const PASSWORD = 'Guclu-Parola-123!';
    private const ABSENT_ID = '0192f000-0000-7000-8000-000000000001';

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

    public function testTeacherCreatesListsShowsAndEditsDraftWithPrgAndNoWriteOnGet(): void
    {
        $ctx = $this->homeworkContext('hwh-flow');
        $classroomId = $ctx['classroom']->getId()->toRfc4122();
        $this->frozenUtc = '2026-11-10 09:00:00';
        $client = $this->clientFor('hwh-flow-teacher@example.com');

        $crawler = $client->request('GET', '/ogretmen/odevler');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ödevlerim', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Henüz ödevin yok', (string) $client->getResponse()->getContent());
        self::assertSame(['draft' => '0', 'assigned' => '0'], $this->counters($crawler));
        // Only real data: no counters or actions for features that do not exist yet.
        foreach (['Yayında', 'Teslim', 'İncelenecek'] as $fake) {
            self::assertStringNotContainsString($fake, (string) $client->getResponse()->getContent());
        }
        self::assertGreaterThanOrEqual(2, $crawler->filter('a[href="/ogretmen/odevler"]')->count(), 'desktop + mobile menu entries');

        $crawler = $client->request('GET', '/ogretmen/odevler/yeni');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Taslak kaydetmek öğrencilere atama yapmaz.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Yayımladıktan sonra ödev metni değiştirilemez.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Europe/Istanbul', (string) $client->getResponse()->getContent());
        $this->assertNoPublishControls($crawler);
        self::assertSame(0, $this->rowCount('homework_definitions'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action LIKE 'homework_definition_%'"));
        $token = $this->token($crawler);

        $client->request('POST', '/ogretmen/odevler/yeni', $this->form($classroomId, $token));
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/ogretmen/odevler/[0-9a-f-]{36}$#', $location);
        $id = substr($location, -36);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ödev taslağı kaydedildi. Öğrencilere atama yapılmadı.', $html);
        self::assertStringContainsString('Kesirler çalışma kâğıdı', $html);
        self::assertStringContainsString('12.11.2026 18:30', $html);
        self::assertStringContainsString('Taslak', $html);
        self::assertStringContainsString('/ogretmen/odevler/'.$id.'/duzenle', $html);
        $this->assertNoPublishControls($crawler);
        self::assertSame('2026-11-12 15:30:00', $this->db()->fetchOne('SELECT due_at FROM homework_definitions'));

        $crawler = $client->request('GET', '/ogretmen/odevler');
        self::assertResponseIsSuccessful();
        self::assertSame(['draft' => '1', 'assigned' => '0'], $this->counters($crawler));
        self::assertStringContainsString('Kesirler çalışma kâğıdı', (string) $client->getResponse()->getContent());
        self::assertSame('İncele', trim($crawler->filter('a[href="/ogretmen/odevler/'.$id.'"]')->text()));

        $updatedAtBefore = $this->db()->fetchOne('SELECT updated_at FROM homework_definitions');
        $crawler = $client->request('GET', '/ogretmen/odevler/'.$id.'/duzenle');
        self::assertResponseIsSuccessful();
        self::assertSame('Kesirler çalışma kâğıdı', $crawler->filter('input[name="title"]')->attr('value'));
        self::assertSame('2026-11-12', $crawler->filter('input[name="due_date"]')->attr('value'));
        self::assertSame('18:30', $crawler->filter('input[name="due_time"]')->attr('value'));
        self::assertSame('1', $crawler->filter('input[name="revision"]')->attr('value'));
        self::assertSame($classroomId, $crawler->filter('select[name="classroom"] option[selected]')->attr('value'));
        self::assertSame($updatedAtBefore, $this->db()->fetchOne('SELECT updated_at FROM homework_definitions'), 'GET edit must not write');
        self::assertSame(1, $this->rowCount('security_audit_events', "action LIKE 'homework_definition_%'"));

        $client->request('POST', '/ogretmen/odevler/'.$id.'/duzenle', $this->form($classroomId, $this->token($crawler), [
            'title' => 'Kesirler ve ondalıklar',
            'revision' => '1',
            'due_time' => '20:00',
        ]));
        self::assertResponseRedirects('/ogretmen/odevler/'.$id);
        $client->followRedirect();
        self::assertStringContainsString('Ödev taslağı güncellendi.', (string) $client->getResponse()->getContent());
        $row = $this->db()->fetchAssociative('SELECT title, due_at, revision_number FROM homework_definitions');
        self::assertSame(['title' => 'Kesirler ve ondalıklar', 'due_at' => '2026-11-12 17:00:00', 'revision_number' => 2], $row);
        self::assertSame(2, $this->rowCount('security_audit_events', "action LIKE 'homework_definition_%'"));
    }

    public function testInvalidFormKeepsSafeInputAndWritesNothing(): void
    {
        $ctx = $this->homeworkContext('hwh-invalid');
        $classroomId = $ctx['classroom']->getId()->toRfc4122();
        $this->frozenUtc = '2026-11-10 09:00:00';
        $client = $this->clientFor('hwh-invalid-teacher@example.com');
        $crawler = $client->request('GET', '/ogretmen/odevler/yeni');
        $token = $this->token($crawler);

        $client->request('POST', '/ogretmen/odevler/yeni', $this->form($classroomId, $token, [
            'title' => '',
            'description' => 'Açıklama <script>alert(1)</script> & "tırnak"',
            'due_date' => '2026-11-09',
        ]));

        self::assertResponseStatusCodeSame(422);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ödev başlığı boş olamaz.', $html);
        self::assertStringContainsString('Ödev açıklaması HTML içeremez.', $html);
        self::assertStringContainsString('Son teslim zamanı gelecekte olmalı.', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $crawler = new Crawler($html);
        self::assertSame($classroomId, $crawler->filter('select[name="classroom"] option[selected]')->attr('value'));
        self::assertSame('2026-11-09', $crawler->filter('input[name="due_date"]')->attr('value'));
        self::assertSame('18:30', $crawler->filter('input[name="due_time"]')->attr('value'));
        self::assertSame(0, $this->rowCount('homework_definitions'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action LIKE 'homework_definition_%'"));
    }

    public function testCsrfFailureAndMissingTokenWriteNothing(): void
    {
        $ctx = $this->homeworkContext('hwh-csrf');
        $classroomId = $ctx['classroom']->getId()->toRfc4122();
        $draft = $this->persistDraft($ctx, 'Mevcut taslak');
        $id = $draft->getId()->toRfc4122();
        $this->frozenUtc = '2026-11-10 09:00:00';
        $client = $this->clientFor('hwh-csrf-teacher@example.com');
        $client->request('GET', '/ogretmen/odevler/yeni');

        $client->request('POST', '/ogretmen/odevler/yeni', $this->form($classroomId, 'invalid-token'));
        self::assertResponseStatusCodeSame(403);
        $payload = $this->form($classroomId, '');
        unset($payload['_token']);
        $client->request('POST', '/ogretmen/odevler/yeni', $payload);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->rowCount('homework_definitions'), 'only the pre-existing draft');

        $client->request('POST', '/ogretmen/odevler/'.$id.'/duzenle', $this->form($classroomId, 'invalid-token', ['revision' => '1', 'title' => 'Değişti']));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Mevcut taslak', $this->db()->fetchOne('SELECT title FROM homework_definitions'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT revision_number FROM homework_definitions'));
        self::assertSame(0, $this->rowCount('security_audit_events', "action LIKE 'homework_definition_%'"));
    }

    public function testStaleRevisionReturnsConflictWithoutWriteOrSuccessAudit(): void
    {
        $ctx = $this->homeworkContext('hwh-stale');
        $classroomId = $ctx['classroom']->getId()->toRfc4122();
        $this->frozenUtc = '2026-11-10 09:00:00';
        $draft = $this->persistDraft($ctx, 'İlk başlık');
        $id = $draft->getId()->toRfc4122();
        $client = $this->clientFor('hwh-stale-teacher@example.com');

        $crawler = $client->request('GET', '/ogretmen/odevler/'.$id.'/duzenle');
        $token = $this->token($crawler);
        $client->request('POST', '/ogretmen/odevler/'.$id.'/duzenle', $this->form($classroomId, $token, ['title' => 'Birinci sekme', 'revision' => '1']));
        self::assertResponseRedirects('/ogretmen/odevler/'.$id);

        $client->request('POST', '/ogretmen/odevler/'.$id.'/duzenle', $this->form($classroomId, $token, ['title' => 'İkinci sekme', 'revision' => '1']));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('başka bir yerde değiştirilmiş', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('İkinci sekme', (string) $client->getResponse()->getContent());
        self::assertSame(['title' => 'Birinci sekme', 'revision_number' => 2], $this->db()->fetchAssociative('SELECT title, revision_number FROM homework_definitions'));
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_definition_draft_updated'"));
    }

    public function testForeignMissingAndMalformedIdsAreOpaqueNotFound(): void
    {
        $ctx = $this->homeworkContext('hwh-foreign');
        $other = $this->homeworkContext('hwh-foreign2');
        $co = $this->addCoTeacher($ctx, 'hwh-foreign-co@example.com');
        $admin = $this->activeUser('hwh-foreign-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $this->frozenUtc = '2026-11-10 09:00:00';
        $draft = $this->persistDraft($ctx, 'Gizli taslak');
        $id = $draft->getId()->toRfc4122();
        $classroomId = $ctx['classroom']->getId()->toRfc4122();
        unset($co);

        foreach (['hwh-foreign2-teacher@example.com', 'hwh-foreign-co@example.com'] as $email) {
            $client = $this->clientFor($email);
            foreach (['/ogretmen/odevler/'.$id, '/ogretmen/odevler/'.$id.'/duzenle'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(404, $email.' '.$path);
                // Debug exception pages can dump route params; assert the draft body is absent from a normal 404 body.
                $body = (string) $client->getResponse()->getContent();
                if (!str_contains($body, 'NotFoundHttpException') && !str_contains($body, 'sf-dump')) {
                    self::assertStringNotContainsString('Gizli taslak', $body);
                    self::assertStringNotContainsString('Sayfa 12', $body);
                }
            }
            $client->request('POST', '/ogretmen/odevler/'.$id.'/duzenle', $this->form($classroomId, 'x', ['revision' => '1']));
            self::assertContains($client->getResponse()->getStatusCode(), [403, 404]);
        }

        // Super admin, admin, institution owner and a student never reach teacher drafts.
        foreach (['hwh-foreign-sa@example.com', 'hwh-foreign-admin@example.com', 'hwh-foreign-owner@example.com'] as $email) {
            $client = $this->clientFor($email);
            foreach (['/ogretmen/odevler', '/ogretmen/odevler/'.$id, '/ogretmen/odevler/'.$id.'/duzenle', '/ogretmen/odevler/yeni'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(404, $email.' '.$path);
            }
        }

        $teacher = $this->clientFor('hwh-foreign-teacher@example.com');
        $teacher->request('GET', '/ogretmen/odevler/'.self::ABSENT_ID);
        self::assertResponseStatusCodeSame(404);
        $teacher->request('GET', '/ogretmen/odevler/'.self::ABSENT_ID.'/duzenle');
        self::assertResponseStatusCodeSame(404);
        $teacher->request('GET', '/ogretmen/odevler/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        // A UUID that is not version 7 does not match the route.
        $teacher->request('GET', '/ogretmen/odevler/6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        self::assertResponseStatusCodeSame(404);
        unset($other);
    }

    public function testEndedAssignmentRemovesListAndDetailAccessEvenForHistory(): void
    {
        $ctx = $this->homeworkContext('hwh-ended');
        $draft = $this->persistDraft($ctx, 'Geçmiş taslak');
        $id = $draft->getId()->toRfc4122();
        $this->teacherManager()->endAssignment($ctx['assignment'], $ctx['owner'], 'ended');
        $this->frozenUtc = '2026-11-10 09:00:00';
        $client = $this->clientFor('hwh-ended-teacher@example.com');

        foreach (['/ogretmen/odevler', '/ogretmen/odevler/yeni', '/ogretmen/odevler/'.$id, '/ogretmen/odevler/'.$id.'/duzenle'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testSearchClassroomFilterCountAndEmptySearchState(): void
    {
        $ctx = $this->homeworkContext('hwh-search');
        $this->frozenUtc = '2026-11-10 09:00:00';
        $this->persistDraft($ctx, 'Kesirler');
        $this->persistDraft($ctx, 'Ondalık sayılar');
        $this->persistDraft($ctx, 'Yüzde 50% problemi');
        $client = $this->clientFor('hwh-search-teacher@example.com');

        $crawler = $client->request('GET', '/ogretmen/odevler');
        self::assertSame(['draft' => '3', 'assigned' => '0'], $this->counters($crawler));
        self::assertCount(3, $crawler->filter('tbody tr'));

        $crawler = $client->request('GET', '/ogretmen/odevler', ['q' => 'kesir']);
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSame(['draft' => '3', 'assigned' => '0'], $this->counters($crawler), 'counters describe the whole authorized set');
        self::assertStringContainsString('(listelenen 1)', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/ogretmen/odevler', ['q' => '%']);
        self::assertCount(1, $crawler->filter('tbody tr'), 'a literal percent sign is not a wildcard');

        $crawler = $client->request('GET', '/ogretmen/odevler', ['q' => 'bulunmayan başlık']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aramana uygun ödev bulunamadı', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Henüz ödevin yok', (string) $client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('tbody tr'));

        $crawler = $client->request('GET', '/ogretmen/odevler', ['sinif' => $ctx['classroom']->getId()->toRfc4122()]);
        self::assertCount(3, $crawler->filter('tbody tr'));
        // An unknown or foreign classroom id is ignored instead of leaking anything.
        $crawler = $client->request('GET', '/ogretmen/odevler', ['sinif' => self::ABSENT_ID]);
        self::assertCount(3, $crawler->filter('tbody tr'));
    }

    public function testLongUnicodeTitleAndDescriptionAreAcceptedAtTheLimitAndRejectedAbove(): void
    {
        $ctx = $this->homeworkContext('hwh-long');
        $classroomId = $ctx['classroom']->getId()->toRfc4122();
        $this->frozenUtc = '2026-11-10 09:00:00';
        $client = $this->clientFor('hwh-long-teacher@example.com');
        $crawler = $client->request('GET', '/ogretmen/odevler/yeni');
        $token = $this->token($crawler);

        $title = str_repeat("\u{1F600}", 150);
        $description = str_repeat("ç\n", 5000);
        $client->request('POST', '/ogretmen/odevler/yeni', $this->form($classroomId, $token, ['title' => $title, 'description' => $description]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($title, (string) $client->getResponse()->getContent());

        $client->request('POST', '/ogretmen/odevler/yeni', $this->form($classroomId, $token, ['title' => $title."\u{1F600}"]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('en fazla 150 karakter', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->rowCount('homework_definitions'));
    }

    public function testAssignFlowGetNeverWritesThenConfirmedPostRedirectsToFrozenDetail(): void
    {
        $ctx = $this->homeworkContext('hwh-assign');
        $this->enrollStudents($ctx, 3);
        $draft = $this->persistDraft($ctx, 'Atanacak ödev');
        $id = $draft->getId()->toRfc4122();
        $detail = '/ogretmen/odevler/'.$id;
        $this->frozenUtc = '2026-11-10 09:00:00';
        $client = $this->clientFor('hwh-assign-teacher@example.com');

        // Detail GET offers the action but shows no confirm form and writes nothing.
        $crawler = $client->request('GET', $detail);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-testid="hw-assign-confirm"]')->count());
        $assignLink = $crawler->filter('a[href="'.$detail.'?ata=1"]');
        self::assertSame(1, $assignLink->count());
        self::assertSame('Sınıfa ata', trim($assignLink->text()));
        $this->assertNoPublishControls($crawler);
        $this->assertNothingAssigned();

        // Confirm screen GET: live eligible count, form with CSRF + revision, still no writes.
        $updatedAt = $this->db()->fetchOne('SELECT updated_at FROM homework_definitions');
        $crawler = $client->request('GET', $detail, ['ata' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="hw-assign-confirm"]')->count());
        self::assertSame('3', trim($crawler->filter('[data-testid="hw-eligible-count"]')->text()));
        self::assertSame('Atamayı onayla', trim($crawler->filter('.hw-assign-form button[type="submit"]')->text()));
        self::assertSame('1', $crawler->filter('.hw-assign-form input[name="revision"]')->attr('value'));
        self::assertSame(1, $crawler->filter('.hw-assign-form input[name="confirm_assign"][type="checkbox"]')->count());
        self::assertSame(0, $crawler->filter('.hw-assign-form button[type="submit"][disabled]')->count());
        $this->assertNoPublishControls($crawler);
        $this->assertNothingAssigned();
        self::assertSame($updatedAt, $this->db()->fetchOne('SELECT updated_at FROM homework_definitions'), 'GET must not write');
        $token = $this->assignToken($crawler);

        // Unchecked confirmation: 422 with the field error, nothing written.
        $client->request('POST', $detail, ['_token' => $token, 'revision' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Atamayı onaylamak için kutuyu işaretle.', (string) $client->getResponse()->getContent());
        $this->assertNothingAssigned();

        // Confirmed POST: PRG to the detail, flash, frozen assignment with real recipient count.
        $client->request('POST', $detail, ['_token' => $token, 'revision' => '1', 'confirm_assign' => '1']);
        self::assertResponseRedirects($detail);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ödev sınıfa atandı. Ödev metni donduruldu.', $html);
        self::assertStringContainsString('Atandı', $html);
        self::assertStringContainsString('Atanacak ödev', $html);
        self::assertSame('3', trim($crawler->filter('[data-testid="hw-recipient-count"]')->text()));
        self::assertSame(0, $crawler->filter('[data-testid="hw-assign-confirm"]')->count());
        self::assertSame(0, $crawler->filter('a[href$="/duzenle"]')->count(), 'assigned homework has no edit link');
        self::assertSame(0, $crawler->filter('a[href*="ata=1"]')->count(), 'assigned homework has no assign link');
        self::assertSame(1, $this->rowCount('homework_assignments'));
        self::assertSame(3, $this->rowCount('homework_recipients'));
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_assignment_created'"));
        $this->assertNoPublishControls($crawler);

        // Reloading the PRG target repeats nothing.
        $client->request('GET', $detail);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->rowCount('homework_assignments'));

        // Edit is closed, and a replayed assign POST is an opaque 404 that writes nothing.
        $client->request('GET', $detail.'/duzenle');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $detail, ['_token' => $token, 'revision' => '1', 'confirm_assign' => '1']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->rowCount('homework_assignments'));
        self::assertSame(3, $this->rowCount('homework_recipients'));
        self::assertSame(1, $this->rowCount('security_audit_events', "action = 'homework_assignment_created'"));
    }

    public function testAssignedAndDraftRowsAppearTogetherAndStatusFilterWorks(): void
    {
        $ctx = $this->homeworkContext('hwh-mixed');
        $this->enrollStudents($ctx, 2);
        $this->frozenUtc = '2026-11-10 09:00:00';
        $this->persistDraft($ctx, 'Hâlâ taslak');
        $assigned = $this->persistDraft($ctx, 'Atanmış ödev');
        $this->assignHomework($ctx['teacher'], $assigned->getId(), 1);
        $client = $this->clientFor('hwh-mixed-teacher@example.com');

        $crawler = $client->request('GET', '/ogretmen/odevler');
        self::assertResponseIsSuccessful();
        self::assertSame(['draft' => '1', 'assigned' => '1'], $this->counters($crawler));
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertCount(1, $crawler->filter('.hw-badge--assigned'));
        self::assertCount(1, $crawler->filter('.hw-badge--draft'));
        self::assertSame('Atandı', trim($crawler->filter('.hw-badge--assigned')->text()));
        self::assertStringContainsString('2 alıcı', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Yayında', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/ogretmen/odevler', ['durum' => 'atandi']);
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Atanmış ödev', $crawler->filter('tbody')->text());
        self::assertSame(['draft' => '1', 'assigned' => '1'], $this->counters($crawler));

        $crawler = $client->request('GET', '/ogretmen/odevler', ['durum' => 'taslak']);
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Hâlâ taslak', $crawler->filter('tbody')->text());
        self::assertSame(0, $crawler->filter('.hw-badge--assigned')->count());

        // Unknown status values are ignored rather than leaking or failing.
        $crawler = $client->request('GET', '/ogretmen/odevler', ['durum' => 'yayinda']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));
    }

    public function testAssignPostCsrfFailuresWriteNothing(): void
    {
        $ctx = $this->homeworkContext('hwh-asgcsrf');
        $this->enrollStudents($ctx, 2);
        $draft = $this->persistDraft($ctx, 'CSRF ödevi');
        $detail = '/ogretmen/odevler/'.$draft->getId()->toRfc4122();
        $this->frozenUtc = '2026-11-10 09:00:00';
        $client = $this->clientFor('hwh-asgcsrf-teacher@example.com');

        $form = $client->request('GET', '/ogretmen/odevler/yeni');
        $draftIntentToken = $this->token($form);

        $client->request('POST', $detail, ['_token' => 'invalid-token', 'revision' => '1', 'confirm_assign' => '1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $detail, ['revision' => '1', 'confirm_assign' => '1']);
        self::assertResponseStatusCodeSame(403);
        // A token minted for the draft form must not authorize an assignment.
        $client->request('POST', $detail, ['_token' => $draftIntentToken, 'revision' => '1', 'confirm_assign' => '1']);
        self::assertResponseStatusCodeSame(403);

        $this->assertNothingAssigned();
        $crawler = $client->request('GET', $detail, ['ata' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="hw-assign-confirm"]')->count(), 'draft is still assignable');
    }

    public function testAssignRejectionsRenderTheRightStateAndLeaveNoRows(): void
    {
        $ctx = $this->homeworkContext('hwh-asgfail');
        $second = $this->classroomManager()->create($ctx['year'], $ctx['owner'], 'hwh-asgfail 9-B', GradeLevel::Grade9, 'cls2', 'B', 40);
        $this->teacherManager()->assign($second, $ctx['owner'], $ctx['teacherMembership'], TeacherAssignmentRole::AssistantTeacher, 'assign_b');
        $this->enrollStudents(['owner' => $ctx['owner'], 'institution' => $ctx['institution'], 'classroom' => $second], 2);
        $empty = $this->persistDraft($ctx, 'Öğrencisiz sınıf ödevi');
        $pastDue = $this->persistDraft($ctx, 'Süresi geçen ödev', '2026-11-05 10:00:00', $second);
        $stale = $this->persistDraft($ctx, 'Eski sürüm ödevi', '2026-12-01 10:00:00', $second);
        $this->frozenUtc = '2026-11-10 09:00:00';
        $client = $this->clientFor('hwh-asgfail-teacher@example.com');

        // No eligible student: the confirm screen says so, disables the button and POST yields 422.
        $emptyPath = '/ogretmen/odevler/'.$empty->getId()->toRfc4122();
        $crawler = $client->request('GET', $emptyPath, ['ata' => '1']);
        self::assertSame('0', trim($crawler->filter('[data-testid="hw-eligible-count"]')->text()));
        self::assertStringContainsString('uygun aktif öğrenci yok', (string) $client->getResponse()->getContent());
        self::assertSame(1, $crawler->filter('.hw-assign-form button[type="submit"][disabled]')->count());
        $token = $this->assignToken($crawler);
        $client->request('POST', $emptyPath, ['_token' => $token, 'revision' => '1', 'confirm_assign' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Bu sınıfta uygun aktif öğrenci yok.', (string) $client->getResponse()->getContent());
        $this->assertNothingAssigned();

        // Students exist but the due date is already past: 422 with the due message.
        $pastPath = '/ogretmen/odevler/'.$pastDue->getId()->toRfc4122();
        $crawler = $client->request('GET', $pastPath, ['ata' => '1']);
        self::assertSame('2', trim($crawler->filter('[data-testid="hw-eligible-count"]')->text()));
        $client->request('POST', $pastPath, ['_token' => $token, 'revision' => '1', 'confirm_assign' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Son teslim zamanı atama anından sonra olmalıdır.', (string) $client->getResponse()->getContent());
        $this->assertNothingAssigned();

        // Stale revision: 409 conflict, nothing written.
        $stalePath = '/ogretmen/odevler/'.$stale->getId()->toRfc4122();
        $client->request('POST', $stalePath, ['_token' => $token, 'revision' => '9', 'confirm_assign' => '1']);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('başka bir yerde değiştirilmiş', (string) $client->getResponse()->getContent());
        $this->assertNothingAssigned();
    }

    public function testAssignIsOpaqueNotFoundForForeignRolesEndedAssignmentAndAbsentIds(): void
    {
        $ctx = $this->homeworkContext('hwh-asgdeny');
        $other = $this->homeworkContext('hwh-asgdeny2');
        $co = $this->addCoTeacher($ctx, 'hwh-asgdeny-co@example.com');
        $admin = $this->activeUser('hwh-asgdeny-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $this->enrollStudents($ctx, 2);
        $draft = $this->persistDraft($ctx, 'Gizli atama ödevi');
        $detail = '/ogretmen/odevler/'.$draft->getId()->toRfc4122();

        $endedCtx = $this->homeworkContext('hwh-asgdeny3');
        $this->enrollStudents($endedCtx, 2);
        $endedDraft = $this->persistDraft($endedCtx, 'Biten atama ödevi');
        $endedDetail = '/ogretmen/odevler/'.$endedDraft->getId()->toRfc4122();
        $this->teacherManager()->endAssignment($endedCtx['assignment'], $endedCtx['owner'], 'ended');
        unset($co, $other);
        $this->frozenUtc = '2026-11-10 09:00:00';

        foreach ([
            'hwh-asgdeny2-teacher@example.com',
            'hwh-asgdeny-co@example.com',
            'hwh-asgdeny-sa@example.com',
            'hwh-asgdeny-admin@example.com',
            'hwh-asgdeny-owner@example.com',
        ] as $email) {
            $client = $this->clientFor($email);
            $client->request('GET', $detail, ['ata' => '1']);
            self::assertResponseStatusCodeSame(404, $email.' GET');
            $body = (string) $client->getResponse()->getContent();
            if (!str_contains($body, 'NotFoundHttpException') && !str_contains($body, 'sf-dump')) {
                self::assertStringNotContainsString('Gizli atama ödevi', $body);
            }
            $client->request('POST', $detail, ['_token' => 'x', 'revision' => '1', 'confirm_assign' => '1']);
            self::assertContains($client->getResponse()->getStatusCode(), [403, 404], $email.' POST');
        }
        $this->assertNothingAssigned();

        $teacher = $this->clientFor('hwh-asgdeny-teacher@example.com');
        $teacher->request('GET', '/ogretmen/odevler/'.self::ABSENT_ID, ['ata' => '1']);
        self::assertResponseStatusCodeSame(404);
        $teacher->request('POST', '/ogretmen/odevler/'.self::ABSENT_ID, ['_token' => 'x', 'revision' => '1', 'confirm_assign' => '1']);
        self::assertContains($teacher->getResponse()->getStatusCode(), [403, 404]);
        $this->assertNothingAssigned();

        // Ended teacher assignment: detail, confirm screen and POST are all closed.
        $ended = $this->clientFor('hwh-asgdeny3-teacher@example.com');
        $ended->request('GET', $endedDetail);
        self::assertResponseStatusCodeSame(404);
        $ended->request('GET', $endedDetail, ['ata' => '1']);
        self::assertResponseStatusCodeSame(404);
        $ended->request('POST', $endedDetail, ['_token' => 'x', 'revision' => '1', 'confirm_assign' => '1']);
        self::assertContains($ended->getResponse()->getStatusCode(), [403, 404]);
        $this->assertNothingAssigned();
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

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('form.hw-form input[name="_token"]')->attr('value');
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function form(string $classroomId, string $token, array $overrides = []): array
    {
        return array_merge([
            '_token' => $token,
            'title' => 'Kesirler çalışma kâğıdı',
            'description' => 'Sayfa 12 ve 13’teki soruları çöz.',
            'classroom' => $classroomId,
            'due_date' => '2026-11-12',
            'due_time' => '18:30',
        ], $overrides);
    }

    /**
     * @return array{draft: string, assigned: string}
     */
    private function counters(Crawler $crawler): array
    {
        $numbers = $crawler->filter('[data-testid="hw-status-count"] strong');

        return ['draft' => trim($numbers->eq(0)->text()), 'assigned' => trim($numbers->eq(1)->text())];
    }

    private function assignToken(Crawler $crawler): string
    {
        return (string) $crawler->filter('.hw-assign-form input[name="_token"]')->attr('value');
    }

    private function assertNothingAssigned(): void
    {
        self::assertSame(0, $this->rowCount('homework_assignments'), 'homework_assignments must be empty');
        self::assertSame(0, $this->rowCount('homework_recipients'), 'homework_recipients must be empty');
        self::assertSame(0, $this->rowCount('security_audit_events', "action = 'homework_assignment_created'"));
    }

    private function assertNoPublishControls(Crawler $crawler): void
    {
        $labels = $crawler->filter('.hw-page button, .hw-page a.btn')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        foreach ($labels as $label) {
            self::assertDoesNotMatchRegularExpression('/Yayımla|Ata$|Gönder|Teslim|Değerlendir/u', $label);
        }
    }
}
