<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

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
        $this->postAction($client, 'yayinla');
        self::assertSelectorTextContains('body', 'Yayında');
        $this->postAction($client, 'arsivle');
        self::assertSelectorTextContains('body', 'Arşivde');
        self::assertStringNotContainsString('>Yayınla<', (string) $client->getResponse()->getContent());
        $client->request('GET', $path.'/duzenle');
        self::assertResponseStatusCodeSame(403);
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
