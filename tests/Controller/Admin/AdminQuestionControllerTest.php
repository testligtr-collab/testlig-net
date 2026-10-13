<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class AdminQuestionControllerTest extends WebTestCase
{
    public function testAnonymousQuestionBankRedirectsToLoginAndStudentIsDenied(): void
    {
        $client = $this->newClient();
        $client->request('GET', '/yonetim/sorular');
        self::assertResponseRedirects('/giris');

        $this->createPrivileged('qb-student@example.com', UserRole::Student);
        $client = $this->newClient();
        $this->login($client, 'qb-student@example.com');
        $client->request('GET', '/yonetim/sorular');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/ogrenci/dersler');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('correctStableKey', (string) $client->getResponse()->getContent());
    }

    public function testTeacherCreatesOwnDraftAndCannotOpenAnotherTeachersDraft(): void
    {
        $ids = $this->seedCurriculum('qbown');
        $this->createPrivileged('qb-teacher-a@example.com', UserRole::Teacher);
        $this->createPrivileged('qb-teacher-b@example.com', UserRole::Teacher);

        $client = $this->newClient();
        $this->login($client, 'qb-teacher-a@example.com');
        $this->postNew($client, $ids, [
            'stem' => 'Kare bir sekil midir?',
            'options' => ['Evet', 'Hayir'],
            'correct' => '1',
        ]);
        $location = $this->openCreated($client);
        self::assertSelectorTextContains('body', 'Taslak');

        $client = $this->newClient();
        $this->login($client, 'qb-teacher-b@example.com');
        $client->request('GET', $location);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/yonetim/sorular');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Kare bir sekil midir?', (string) $client->getResponse()->getContent());
    }

    public function testCreateRequiresCsrfAndRejectsMarkupDuplicateAndBlankOptions(): void
    {
        $ids = $this->seedCurriculum('qbedit');
        $this->createPrivileged('qb-teacher-form@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'qb-teacher-form@example.com');

        $client->request('POST', '/yonetim/sorular/yeni', $this->payload($ids, ['_token' => 'invalid']));
        self::assertResponseStatusCodeSame(403);

        $this->postNew($client, $ids, ['stem' => '<script>alert(1)</script>']);
        self::assertResponseRedirects();
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('alert(1)', $html);
        self::assertStringContainsString('Soru kaydedilemedi', $html);

        $this->postNew($client, $ids, ['options' => ['Ayni', 'Ayni']]);
        $client->followRedirect();
        self::assertStringContainsString('Soru kaydedilemedi', (string) $client->getResponse()->getContent());

        $this->postNew($client, $ids, ['options' => ['Dolu', '   ']]);
        $client->followRedirect();
        self::assertStringContainsString('Soru kaydedilemedi', (string) $client->getResponse()->getContent());
    }

    public function testOutcomeListKeepsOnlyActiveSubjectGradeOutcomes(): void
    {
        $ids = $this->seedCurriculum('qbout');
        $this->createPrivileged('qb-teacher-out@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'qb-teacher-out@example.com');
        $client->request('GET', '/yonetim/sorular/yeni?grade=1&subject_id='.$ids['subject']);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($ids['active_code'], $html);
        self::assertStringNotContainsString($ids['archived_code'], $html);
        self::assertStringNotContainsString($ids['other_grade_code'], $html);
    }

    public function testModeratorCanReturnButCannotPublishAndPreviewHidesCorrectChoice(): void
    {
        $ids = $this->seedCurriculum('qbrev');
        $this->createPrivileged('qb-teacher-rev@example.com', UserRole::Teacher);
        $this->createPrivileged('qb-mod-rev@example.com', UserRole::Moderator);
        $this->createPrivileged('qb-student-public@example.com', UserRole::Student);

        $client = $this->newClient();
        $this->login($client, 'qb-teacher-rev@example.com');
        $this->postNew($client, $ids, [
            'stem' => 'Dogru gizli kok',
            'options' => ['DogruGizliMetin', 'YanlisSecenekMetni'],
            'correct' => '1',
        ]);
        $path = $this->openCreated($client);
        $this->postAction($client, 'incelemeye-gonder');
        self::assertSelectorTextContains('body', 'İncelemede');

        $client->request('GET', $path.'/gorunum');
        $preview = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Doğru cevap', $preview);
        self::assertStringNotContainsString('correctStableKey', $preview);
        self::assertStringContainsString('DogruGizliMetin', $preview);

        $client = $this->newClient();
        $this->login($client, 'qb-student-public@example.com');
        $client->request('GET', '/ogrenci/dersler');
        $client->followRedirect();
        self::assertStringNotContainsString('DogruGizliMetin', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('correctStableKey', (string) $client->getResponse()->getContent());

        $client = $this->newClient();
        $this->login($client, 'qb-mod-rev@example.com');
        $client->request('GET', '/yonetim/sorular/yeni');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $moderatorHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Taslağa döndür', $moderatorHtml);
        self::assertStringNotContainsString('>Yayınla<', $moderatorHtml);

        $this->postToken($client, $path.'/yayinla');
        self::assertStringContainsString('yetkiniz yok', (string) $client->getResponse()->getContent());

        $this->postAction($client, 'taslaga-dondur');
        self::assertSelectorTextContains('body', 'Taslak');
    }

    public function testAuthorCannotPublishAndAnotherAdminCan(): void
    {
        $ids = $this->seedCurriculum('qbpub');
        $this->createPrivileged('qb-head-author@example.com', UserRole::HeadTeacher);
        $this->createPrivileged('qb-admin-pub@example.com', UserRole::Admin);

        $client = $this->newClient();
        $this->login($client, 'qb-head-author@example.com');
        $this->postNew($client, $ids, []);
        $path = $this->openCreated($client);
        $this->postAction($client, 'incelemeye-gonder');
        self::assertStringContainsString('yayınlayamazsınız', (string) $client->getResponse()->getContent());
        $this->postToken($client, $path.'/yayinla');
        self::assertStringContainsString('yayınlayamazsınız', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Soru yayınlandı', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('body', 'İncelemede');

        $client = $this->newClient();
        $this->login($client, 'qb-admin-pub@example.com');
        $client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Yayınla');
        self::assertSelectorExists('#app-confirm-dialog');
        self::assertSelectorExists('form[action*="yayinla"][data-controller="confirm-submit"]');
        $this->postAction($client, 'yayinla');
        self::assertSelectorTextContains('body', 'Yayında');
        $this->postAction($client, 'arsivle');
        self::assertSelectorTextContains('body', 'Arşivde');
        self::assertStringNotContainsString('>Yayınla<', (string) $client->getResponse()->getContent());
        $client->request('GET', $path.'/duzenle');
        self::assertResponseStatusCodeSame(403);
    }

    public function testImageUploadCreatesANewRevisionAndBlocksReview(): void
    {
        if (!\function_exists('imagepng')) {
            self::markTestSkipped('GD PNG support is required.');
        }
        $ids = $this->seedCurriculum('qbimg');
        $this->createPrivileged('qb-teacher-image@example.com', UserRole::HeadTeacher);
        $client = $this->newClient();
        $this->login($client, 'qb-teacher-image@example.com');
        $this->postNew($client, $ids, ['stem' => 'Ilk kok']);
        $path = $this->openCreated($client);
        $questionId = basename($path);
        $edit = $client->request('GET', $path.'/duzenle?grade=1&subject_id='.$ids['subject']);
        self::assertResponseIsSuccessful();
        $file = tempnam(sys_get_temp_dir(), 'qimg');
        self::assertIsString($file);
        file_put_contents($file, $this->pngBytes());
        $token = (string) $edit->filter('#question-editor input[name="_token"]')->attr('value');
        $client->request('POST', $path.'/duzenle', $this->payload($ids, [
            '_token' => $token,
            'expected_revision' => '1',
            'stem' => 'Ikinci kok',
        ]), [
            'question_image' => new UploadedFile($file, 'soru.png', 'image/png', null, true),
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        $page = (string) $client->getResponse()->getContent();
        $revisions = $this->revisionStems($questionId);
        self::assertCount(2, $revisions);
        self::assertStringContainsString('Ilk kok', $revisions[0]);
        self::assertStringNotContainsString('image_reference', $revisions[0]);
        self::assertStringContainsString('Ikinci kok', $revisions[1]);
        self::assertStringContainsString('image_reference', $revisions[1]);
        preg_match('/[0-9a-f-]{36}/', $revisions[1], $match);
        $mediaId = $match[0] ?? null;
        self::assertIsString($mediaId);
        self::assertStringNotContainsString($mediaId, $page);
        self::assertStringContainsString('Taslak', $page);
        $this->postAction($client, 'incelemeye-gonder');
        self::assertStringContainsString('Bekleyen görsel incelenemez veya yayımlanamaz.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Taslak', (string) $client->getResponse()->getContent());
        $token = (string) $client->getCrawler()->filter('#question-csrf input[name="_token"]')->attr('value');
        $client->request('POST', $path.'/yayinla', [
            '_token' => $token,
            'expected_revision' => '2',
            'operator_note' => 'Kontrol notu',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Bekleyen görsel incelenemez veya yayımlanamaz.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Taslak', (string) $client->getResponse()->getContent());
    }

    public function testModeratorCsrfCannotUploadAQuestionImage(): void
    {
        if (!\function_exists('imagepng')) {
            self::markTestSkipped('GD PNG support is required.');
        }
        $ids = $this->seedCurriculum('qbmod');
        $this->createPrivileged('qb-moderator-image@example.com', UserRole::Moderator);
        $before = $this->mediaCount();
        $client = $this->newClient();
        $this->login($client, 'qb-moderator-image@example.com');
        $client->request('GET', '/yonetim/sorular');
        self::assertResponseIsSuccessful();
        $file = tempnam(sys_get_temp_dir(), 'qimg');
        self::assertIsString($file);
        file_put_contents($file, $this->pngBytes());
        $session = $client->getRequest()->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\RequestStack::class, $stack);
        $stack->push($client->getRequest());
        $tokens = $client->getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class, $tokens);
        $token = $tokens->getToken('question_editor')->getValue();
        $session->save();
        $stack->pop();
        $client->request('POST', '/yonetim/sorular/yeni', $this->payload($ids, [
            '_token' => $token,
            'stem' => 'Yetkisiz gorsel',
        ]), [
            'question_image' => new UploadedFile($file, 'soru.png', 'image/png', null, true),
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->mediaCount());
    }

    public function testPlatformCreateFailureRollsBackTheImageAndQuestion(): void
    {
        if (!\function_exists('imagepng')) {
            self::markTestSkipped('GD PNG support is required.');
        }
        $ids = $this->seedCurriculum('qbimgfail');
        $this->createPrivileged('qb-teacher-image-fail@example.com', UserRole::Teacher);
        $before = $this->imageSnapshot();
        $client = $this->newClient();
        $this->login($client, 'qb-teacher-image-fail@example.com');
        $form = $client->request('GET', '/yonetim/sorular/yeni?grade=1&subject_id='.$ids['subject']);
        self::assertResponseIsSuccessful();
        $token = (string) $form->filter('#question-editor input[name="_token"]')->attr('value');
        $file = $this->pngFile();
        $this->failOnImageInsert($client);
        $client->catchExceptions(false);
        try {
            $client->request('POST', '/yonetim/sorular/yeni', $this->payload($ids, [
                '_token' => $token,
                'stem' => 'Kayit hatasi',
            ]), [
                'question_image' => new UploadedFile($file, 'soru.png', 'image/png', null, true),
            ]);
            self::fail('The question save error was hidden.');
        } catch (\RuntimeException $exception) {
            if ('db-failed' !== $exception->getMessage()) {
                throw $exception;
            }
        }
        self::assertSame($before, $this->imageSnapshot());
        self::assertSame([], $this->questionImageParts());
    }

    public function testPlatformRevisionFailureKeepsTheBoundImage(): void
    {
        if (!\function_exists('imagepng')) {
            self::markTestSkipped('GD PNG support is required.');
        }
        $ids = $this->seedCurriculum('qbimgedit');
        $this->createPrivileged('qb-teacher-image-edit@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'qb-teacher-image-edit@example.com');
        $this->postNew($client, $ids, ['stem' => 'Ilk kok']);
        $path = $this->openCreated($client);
        $file = $this->pngFile();
        $created = $client->request('GET', $path.'/duzenle?grade=1&subject_id='.$ids['subject']);
        self::assertResponseIsSuccessful();
        $createdToken = (string) $created->filter('#question-editor input[name="_token"]')->attr('value');
        $client->request('POST', $path.'/duzenle', $this->payload($ids, [
            '_token' => $createdToken,
            'expected_revision' => '1',
            'stem' => 'Bagli gorsel',
        ]), [
            'question_image' => new UploadedFile($file, 'soru.png', 'image/png', null, true),
        ]);
        self::assertResponseRedirects();
        $bound = $this->imageSnapshot();
        $client = $this->newClient();
        $this->login($client, 'qb-teacher-image-edit@example.com');
        $edit = $client->request('GET', $path.'/duzenle?grade=1&subject_id='.$ids['subject']);
        self::assertResponseIsSuccessful();
        $token = (string) $edit->filter('#question-editor input[name="_token"]')->attr('value');
        $this->failOnImageInsert($client);
        $client->catchExceptions(false);
        try {
            $client->request('POST', $path.'/duzenle', $this->payload($ids, [
                '_token' => $token,
                'expected_revision' => '2',
                'stem' => 'Ikinci gorsel',
            ]), [
                'question_image' => new UploadedFile($this->pngFile(), 'soru.png', 'image/png', null, true),
            ]);
            self::fail('The revision save error was hidden.');
        } catch (\RuntimeException $exception) {
            if ('db-failed' !== $exception->getMessage()) {
                throw $exception;
            }
        }
        self::assertSame($bound, $this->imageSnapshot());
        self::assertSame([], $this->questionImageParts());
    }

    public function testStaleRevisionIsRejected(): void
    {
        $ids = $this->seedCurriculum('qbconc');
        $this->createPrivileged('qb-teacher-conc@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'qb-teacher-conc@example.com');
        $this->postNew($client, $ids, ['stem' => 'Ilk kok']);
        $path = $this->openCreated($client);
        $edit = $client->request('GET', $path.'/duzenle?grade=1&subject_id='.$ids['subject']);
        self::assertResponseIsSuccessful();
        $token = (string) $edit->filter('#question-editor input[name="_token"]')->attr('value');

        $client->request('POST', $path.'/duzenle', $this->payload($ids, [
            '_token' => $token,
            'expected_revision' => '1',
            'stem' => 'Ikinci kok',
        ]));
        self::assertResponseRedirects();
        $client->request('POST', $path.'/duzenle', $this->payload($ids, [
            '_token' => $token,
            'expected_revision' => '1',
            'stem' => 'Ucuncu kok',
        ]));
        $client->followRedirect();
        self::assertStringContainsString('başka bir işlemle değişti', (string) $client->getResponse()->getContent());
    }

    /**
     * @param array{subject: string, outcome: string} $ids
     * @param array<string, mixed>                    $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $ids, array $overrides = []): array
    {
        return array_merge([
            '_token' => '',
            'expected_revision' => '0',
            'grade' => '1',
            'subject_id' => $ids['subject'],
            'outcome_id' => $ids['outcome'],
            'difficulty' => 'easy',
            'stem' => 'Dort kenari esit olan sekil hangisidir?',
            'explanation' => 'Kare esit kenarlidir.',
            'options' => ['Kare', 'Daire'],
            'correct' => '1',
        ], $overrides);
    }

    /**
     * @param array{subject: string, outcome: string} $ids
     * @param array<string, mixed>                    $overrides
     */
    private function postNew(KernelBrowser $client, array $ids, array $overrides): void
    {
        $crawler = $client->request('GET', '/yonetim/sorular/yeni?grade=1&subject_id='.$ids['subject']);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('#question-editor input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/sorular/yeni', $this->payload($ids, array_merge(['_token' => $token], $overrides)));
    }

    private function openCreated(KernelBrowser $client): string
    {
        $failure = (string) $client->getResponse()->headers->get('X-Question-Failure');
        self::assertResponseRedirects(null, null, $failure);
        $client->followRedirect();
        self::assertStringContainsString('Taslak kaydedildi', (string) $client->getResponse()->getContent(), $failure);

        return $client->getRequest()->getPathInfo();
    }

    private function postAction(KernelBrowser $client, string $needle): void
    {
        $formNode = $client->getCrawler()->filter('form[action*="'.$needle.'"]');
        self::assertGreaterThan(0, $formNode->count(), (string) $client->getResponse()->getContent());
        $client->submit($formNode->form([
            'operator_note' => 'Kontrol notu',
        ]));
        $failure = (string) $client->getResponse()->headers->get('X-Question-Failure');
        self::assertResponseRedirects(null, null, $failure.' '.$client->getResponse()->getStatusCode());
        $client->followRedirect();
    }

    private function postToken(KernelBrowser $client, string $path): void
    {
        $token = (string) $client->getCrawler()->filter('#question-csrf input[name="_token"]')->attr('value');
        $client->request('POST', $path, [
            '_token' => $token,
            'expected_revision' => '1',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    /**
     * @return array{subject: string, outcome: string, active_code: string, archived_code: string, other_grade_code: string}
     */
    private function seedCurriculum(string $prefix): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var UserFactory $factory */
        $factory = $container->get(UserFactory::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $sa = $factory->createAndPersist($prefix.'-sa@example.com', 'Guclu-Parola-123!', 'S', 'A', UserRole::Student);
        $sa->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $sa->transitionTo(UserStatus::Active);
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $users->save($sa);

        /** @var SubjectManager $subjectManager */
        $subjectManager = $container->get(SubjectManager::class);
        /** @var CurriculumProgramManager $programs */
        $programs = $container->get(CurriculumProgramManager::class);
        /** @var CurriculumUnitManager $units */
        $units = $container->get(CurriculumUnitManager::class);
        /** @var CurriculumTopicManager $topics */
        $topics = $container->get(CurriculumTopicManager::class);
        /** @var CurriculumLearningOutcomeManager $outcomes */
        $outcomes = $container->get(CurriculumLearningOutcomeManager::class);

        $subject = $subjectManager->create($sa, $prefix.'_s', 'Ders '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'create_p');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'create_t');
        $active = $outcomes->create($topic, $sa, $prefix.'_active', 'Aktif kazanım', 1, 'create_lo');
        $archived = $outcomes->create($topic, $sa, $prefix.'_archived', 'Arşiv kazanım', 2, 'create_lo2');
        $outcomes->archive($archived, $sa, 'archive_lo');
        $programs->publish($program, $sa, 'publish_p');

        $other = $programs->createDraft($subject, $sa, GradeLevel::Grade2, $prefix.'_p2', 'P2', '1.0', 'create_p2');
        $otherUnit = $units->create($other, $sa, $prefix.'_u2', 'U2', 1, 'create_u2');
        $otherTopic = $topics->createRoot($otherUnit, $sa, $prefix.'_t2', 'T2', 1, 'create_t2');
        $otherOutcome = $outcomes->create($otherTopic, $sa, $prefix.'_grade2', 'Başka sınıf', 1, 'create_lo3');
        $programs->publish($other, $sa, 'publish_p2');

        $ids = [
            'subject' => $subject->getId()->toRfc4122(),
            'outcome' => $active->getId()->toRfc4122(),
            'active_code' => $prefix.'_active',
            'archived_code' => $prefix.'_archived',
            'other_grade_code' => $prefix.'_grade2',
        ];
        unset($active, $archived, $otherOutcome);
        self::ensureKernelShutdown();

        return $ids;
    }

    private function newClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    /**
     * @return array{assets: int, questions: int, revisions: int, media_audits: int, question_audits: int, revision_audits: int, files: int}
     */
    private function imageSnapshot(): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();
        $snapshot = [
            'assets' => (int) $connection->fetchOne('SELECT COUNT(*) FROM stored_media_assets'),
            'questions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM questions'),
            'revisions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM question_revisions'),
            'media_audits' => (int) $connection->fetchOne('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', ['stored_media_asset_registered']),
            'question_audits' => (int) $connection->fetchOne('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', ['question_created']),
            'revision_audits' => (int) $connection->fetchOne('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', ['question_revision_created']),
            'files' => \count($this->questionImageFiles()),
        ];
        self::ensureKernelShutdown();

        return $snapshot;
    }

    private function failOnImageInsert(KernelBrowser $client): void
    {
        $client->disableReboot();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getEventManager()->addEventListener(\Doctrine\ORM\Events::onFlush, new class {
            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof \App\Entity\StoredMediaAsset) {
                        throw new \RuntimeException('db-failed');
                    }
                }
            }
        });
    }

    private function pngFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'qimg');
        self::assertIsString($file);
        file_put_contents($file, $this->pngBytes());

        return $file;
    }

    /**
     * @return list<string>
     */
    private function questionImageFiles(): array
    {
        return $this->questionImageMatches(static fn (\SplFileInfo $file): bool => $file->isFile() && !str_ends_with($file->getFilename(), '.part'));
    }

    /**
     * @return list<string>
     */
    private function questionImageParts(): array
    {
        return $this->questionImageMatches(static fn (\SplFileInfo $file): bool => $file->isFile() && str_ends_with($file->getFilename(), '.part'));
    }

    /**
     * @param callable(\SplFileInfo): bool $match
     *
     * @return list<string>
     */
    private function questionImageMatches(callable $match): array
    {
        $root = \dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'question-images';
        if (!is_dir($root)) {
            return [];
        }
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($match($file)) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private function revisionStems(string $questionId): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $question = $em->find(Question::class, Uuid::fromString($questionId));
        self::assertInstanceOf(Question::class, $question);
        $revisions = $em->getRepository(QuestionRevision::class)->findBy(['question' => $question], ['revisionNumber' => 'ASC']);
        $stems = [];
        foreach ($revisions as $revision) {
            self::assertInstanceOf(QuestionRevision::class, $revision);
            $encoded = json_encode($revision->getStemContent());
            self::assertIsString($encoded);
            $stems[] = $encoded;
        }
        self::ensureKernelShutdown();

        return $stems;
    }

    private function mediaCount(): int
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $count = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM stored_media_assets');
        self::ensureKernelShutdown();

        return $count;
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(8, 8);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    private function createPrivileged(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Soru', 'Kullanici', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        self::ensureKernelShutdown();

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            QuestionBankDbCleanup::deleteTables($em->getConnection(), [
                'curriculum_learning_outcomes',
                'curriculum_topics',
                'curriculum_units',
                'curriculum_programs',
                'subjects',
                'security_audit_events',
                'users',
            ]);
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}
