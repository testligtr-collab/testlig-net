<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Question;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminTestControllerTest extends WebTestCase
{
    public function testAnonymousTestBankRedirectsToLoginAndStudentIsDenied(): void
    {
        $client = $this->newClient();
        $client->request('GET', '/yonetim/testler');
        self::assertResponseRedirects('/giris');

        $this->createPrivileged('tb-student@example.com', UserRole::Student);
        $client = $this->newClient();
        $this->login($client, 'tb-student@example.com');
        $client->request('GET', '/yonetim/testler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateRequiresCsrfAndRejectsEmptyMarkupAndBadPoints(): void
    {
        $seed = $this->seed('tbform');
        $this->createPrivileged('tb-teacher-form@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'tb-teacher-form@example.com');

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, ['_token' => 'invalid']));
        self::assertResponseStatusCodeSame(403);

        $token = $this->token($client, $seed);
        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [],
        ]));
        $client->followRedirect();
        self::assertStringContainsString('En az bir soru ekleyin', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'title' => '<script>alert(1)</script>',
        ]));
        $client->followRedirect();
        self::assertStringNotContainsString('alert(1)', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['question'],
                'position' => '1',
                'points' => '0',
            ]],
        ]));
        $client->followRedirect();
        self::assertStringContainsString('Puan 0', (string) $client->getResponse()->getContent());
    }

    public function testTeacherSavesOwnDraftAndOtherTeacherCannotOpenIt(): void
    {
        $seed = $this->seed('tbown');
        $this->createPrivileged('tb-teacher-a@example.com', UserRole::Teacher);
        $this->createPrivileged('tb-teacher-b@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'tb-teacher-a@example.com');
        $path = $this->createDraft($client, $seed, 'Toplama testi');
        self::assertStringContainsString('Taslak', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('name="reason_code"', (string) $client->getResponse()->getContent());

        $client = $this->newClient();
        $this->login($client, 'tb-teacher-b@example.com');
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsUnpublishedGradeSubjectAndDuplicateQuestions(): void
    {
        $seed = $this->seed('tbrej');
        $this->createPrivileged('tb-teacher-rej@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'tb-teacher-rej@example.com');
        $token = $this->token($client, $seed);

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['draft_question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        $client->followRedirect();
        self::assertStringContainsString('Yalnız yayımlanmış sorular eklenebilir.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['archived_question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        $client->followRedirect();
        self::assertStringContainsString('Yalnız yayımlanmış sorular eklenebilir.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['other_grade_question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        $client->followRedirect();
        self::assertStringContainsString('Soru sınıfı testin sınıfıyla aynı olmalı.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['other_subject_question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        $client->followRedirect();
        self::assertStringContainsString('Soru dersi testin dersiyle aynı olmalı.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [
                ['question_id' => $seed['question'], 'position' => '1', 'points' => '1'],
                ['question_id' => $seed['question'], 'position' => '2', 'points' => '1'],
            ],
        ]));
        $client->followRedirect();
        self::assertStringContainsString('zaten ekli', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [
                ['question_id' => $seed['question'], 'position' => '1', 'points' => '1'],
                ['question_id' => $seed['second_question'], 'position' => '1', 'points' => '2'],
            ],
        ]));
        $client->followRedirect();
        self::assertStringContainsString('Aynı sıra', (string) $client->getResponse()->getContent());
    }

    public function testReorderStaleRevisionAndPreviewHideAnswerKey(): void
    {
        $seed = $this->seed('tbprev');
        $this->createPrivileged('tb-teacher-prev@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'tb-teacher-prev@example.com');
        $path = $this->createDraft($client, $seed, 'Siralama testi', [
            ['question_id' => $seed['question'], 'position' => '1', 'points' => '1'],
            ['question_id' => $seed['second_question'], 'position' => '2', 'points' => '2'],
        ]);
        $edit = $client->request('GET', $path.'/duzenle');
        $token = (string) $edit->filter('#test-editor input[name="_token"]')->attr('value');
        $client->request('POST', $path.'/duzenle', [
            '_token' => $token,
            'expected_revision' => '1',
            'title' => 'Siralama testi',
            'instructions' => 'Yonerge',
            'grade' => '1',
            'subject_id' => $seed['subject'],
            'move_down_0' => '1',
            'items' => [
                ['question_id' => $seed['question'], 'position' => '1', 'points' => '1'],
                ['question_id' => $seed['second_question'], 'position' => '2', 'points' => '2'],
            ],
        ]);
        self::assertResponseRedirects();
        self::assertSame('down-0', (string) $client->getResponse()->headers->get('X-Test-Move'));
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Taslak kaydedildi', $html);
        $first = strpos($html, 'Dort nedir?');
        $second = strpos($html, 'Uc nedir?');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertLessThan($first, $second);
        $token = (string) $client->request('GET', $path.'/duzenle')->filter('#test-editor input[name="_token"]')->attr('value');

        $client->request('POST', $path.'/duzenle', [
            '_token' => $token,
            'expected_revision' => '1',
            'title' => 'Eski surum',
            'grade' => '1',
            'subject_id' => $seed['subject'],
            'items' => [
                ['question_id' => $seed['question'], 'position' => '1', 'points' => '1'],
            ],
        ]);
        $client->followRedirect();
        self::assertStringContainsString('güncellendi', (string) $client->getResponse()->getContent());

        $client->request('GET', $path.'/gorunum');
        $preview = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Siralama testi', $preview);
        self::assertStringContainsString('Yonerge', $preview);
        self::assertStringContainsString('Uc', $preview);
        self::assertStringContainsString('Dort', $preview);
        self::assertStringNotContainsString('Doğru cevap', $preview);
        self::assertStringNotContainsString('correctStableKey', $preview);
        self::assertStringNotContainsString('answer_payload', $preview);
        self::assertStringNotContainsString($seed['question_revision'], $preview);
        self::assertStringNotContainsString('opt_a', $preview);
    }

    public function testLifecycleSeparationModeratorAndPublishedImmutable(): void
    {
        $seed = $this->seed('tblife');
        $this->createPrivileged('tb-teacher-life@example.com', UserRole::Teacher);
        $this->createPrivileged('tb-moderator@example.com', UserRole::Moderator);
        $this->createPrivileged('tb-admin@example.com', UserRole::Admin);
        $client = $this->newClient();
        $this->login($client, 'tb-teacher-life@example.com');
        $path = $this->createDraft($client, $seed, 'Yayin testi');
        $this->submitVisible($client, '/incelemeye-gonder');
        $client->request('POST', $path.'/yayinla', [
            '_token' => $this->token($client, $seed),
            'expected_revision' => '1',
        ]);
        $client->followRedirect();
        self::assertStringContainsString('yayımlayamazsınız', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('İncelemede', (string) $client->getResponse()->getContent());

        $client = $this->newClient();
        $this->login($client, 'tb-moderator@example.com');
        $client->request('GET', $path);
        $page = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('/yayinla', $page);
        self::assertStringContainsString('/taslaga-dondur', $page);
        $token = (string) $client->getCrawler()->filter('form[action*="/taslaga-dondur"] input[name="_token"]')->attr('value');
        $client->request('POST', $path.'/yayinla', [
            '_token' => $token,
            'expected_revision' => '1',
        ]);
        $client->followRedirect();
        self::assertStringContainsString('yetkiniz yok', (string) $client->getResponse()->getContent());

        $client = $this->newClient();
        $this->login($client, 'tb-admin@example.com');
        $client->request('GET', $path);
        $this->submitVisible($client, '/yayinla');
        self::assertStringContainsString('Test yayınlandı', (string) $client->getResponse()->getContent());
        $client->request('GET', $path.'/duzenle');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $path.'/duzenle', [
            '_token' => $this->token($client, $seed),
            'expected_revision' => '1',
            'title' => 'Degismemeli',
            'grade' => '1',
            'subject_id' => $seed['subject'],
            'items' => [[
                'question_id' => $seed['question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPublishRechecksQuestionStillPublished(): void
    {
        $seed = $this->seed('tbfresh');
        $this->createPrivileged('tb-teacher-fresh@example.com', UserRole::Teacher);
        $this->createPrivileged('tb-admin-fresh@example.com', UserRole::Admin);
        $client = $this->newClient();
        $this->login($client, 'tb-teacher-fresh@example.com');
        $path = $this->createDraft($client, $seed, 'Guncel soru testi');
        $this->submitVisible($client, '/incelemeye-gonder');

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var QuestionManager $questions */
        $questions = static::getContainer()->get(QuestionManager::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $actor = $users->findOneBy(['email' => 'tbfresh-sa@example.com']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $question = $em->find(Question::class, Uuid::fromString($seed['question']));
        self::assertInstanceOf(User::class, $actor);
        self::assertInstanceOf(Question::class, $question);
        $questions->archive($question, $actor, 'archive_obsolete');
        self::ensureKernelShutdown();

        $client = $this->newClient();
        $this->login($client, 'tb-admin-fresh@example.com');
        $client->request('GET', $path);
        $this->submitVisible($client, '/yayinla');
        self::assertStringContainsString('Yalnız yayımlanmış sorular', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Test yayınlandı', (string) $client->getResponse()->getContent());
    }

    /**
     * @param array{subject: string, question: string, second_question?: string}      $seed
     * @param list<array{question_id: string, position: string, points: string}>|null $items
     */
    private function createDraft(KernelBrowser $client, array $seed, string $title, ?array $items = null): string
    {
        $token = $this->token($client, $seed);
        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'title' => $title,
            'items' => $items ?? [[
                'question_id' => $seed['question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();

        return $client->getRequest()->getPathInfo();
    }

    private function submitVisible(KernelBrowser $client, string $needle): void
    {
        $form = $client->getCrawler()->filter('form[action*="'.$needle.'"]');
        self::assertGreaterThan(0, $form->count());
        $client->submit($form->form());
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    /**
     * @param array{subject: string, question: string} $seed
     */
    private function token(KernelBrowser $client, array $seed): string
    {
        $crawler = $client->request('GET', '/yonetim/testler/yeni?grade=1&subject_id='.$seed['subject']);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('#test-editor input[name="_token"]')->attr('value');
    }

    /**
     * @param array{subject: string, question: string} $seed
     * @param array<string, mixed>                     $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $seed, array $overrides): array
    {
        return array_replace([
            '_token' => '',
            'expected_revision' => '0',
            'title' => 'Deneme testi',
            'instructions' => 'Yonerge metni',
            'grade' => '1',
            'subject_id' => $seed['subject'],
            'duration_minutes' => '20',
            'items' => [[
                'question_id' => $seed['question'],
                'position' => '1',
                'points' => '1',
            ]],
        ], $overrides);
    }

    /**
     * @return array{
     *     subject: string,
     *     other_subject: string,
     *     question: string,
     *     second_question: string,
     *     draft_question: string,
     *     archived_question: string,
     *     other_grade_question: string,
     *     other_subject_question: string,
     *     question_revision: string
     * }
     */
    private function seed(string $prefix): array
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
        /** @var QuestionManager $questions */
        $questions = $container->get(QuestionManager::class);

        $subject = $subjectManager->create($sa, $prefix.'_s', 'Ders '.$prefix, 'create_s');
        $otherSubject = $subjectManager->create($sa, $prefix.'_s2', 'Diger '.$prefix, 'create_s2');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'create_p');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'create_t');
        $outcome = $outcomes->create($topic, $sa, $prefix.'_lo', 'Kazanim', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');
        $grade2 = $programs->createDraft($subject, $sa, GradeLevel::Grade2, $prefix.'_p2', 'P2', '1.0', 'create_p2');
        $unit2 = $units->create($grade2, $sa, $prefix.'_u2', 'U2', 1, 'create_u2');
        $topic2 = $topics->createRoot($unit2, $sa, $prefix.'_t2', 'T2', 1, 'create_t2');
        $outcome2 = $outcomes->create($topic2, $sa, $prefix.'_lo2', 'Kazanim 2', 1, 'create_lo2');
        $programs->publish($grade2, $sa, 'publish_p2');
        $otherProgram = $programs->createDraft($otherSubject, $sa, GradeLevel::Grade1, $prefix.'_p3', 'P3', '1.0', 'create_p3');
        $otherUnit = $units->create($otherProgram, $sa, $prefix.'_u3', 'U3', 1, 'create_u3');
        $otherTopic = $topics->createRoot($otherUnit, $sa, $prefix.'_t3', 'T3', 1, 'create_t3');
        $otherOutcome = $outcomes->create($otherTopic, $sa, $prefix.'_lo3', 'Kazanim 3', 1, 'create_lo3');
        $programs->publish($otherProgram, $sa, 'publish_p3');

        $publisher = $factory->createAndPersist($prefix.'-editor@example.com', 'Guclu-Parola-123!', 'E', 'D', UserRole::Teacher);
        $publisher->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $publisher->transitionTo(UserStatus::Active);
        $publisher->addGlobalRole(UserRole::Admin);
        $users->save($publisher);
        $published = $this->question($questions, $sa, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Uc nedir?', true);
        $second = $this->question($questions, $sa, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Dort nedir?', true);
        $draft = $this->question($questions, $sa, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Taslak soru', false);
        $archived = $this->question($questions, $sa, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Arsiv soru', true);
        $questions->archive($archived, $sa, 'archive_obsolete');
        $otherGrade = $this->question($questions, $sa, $publisher, $subject, GradeLevel::Grade2, $outcome2, 'Baska sinif', true);
        $otherSubjectQuestion = $this->question($questions, $sa, $publisher, $otherSubject, GradeLevel::Grade1, $otherOutcome, 'Baska ders', true);

        /** @var QuestionRevisionRepository $revisions */
        $revisions = $container->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($published, $published->getCurrentRevisionNumber());
        self::assertNotNull($revision);

        $ids = [
            'subject' => $subject->getId()->toRfc4122(),
            'other_subject' => $otherSubject->getId()->toRfc4122(),
            'question' => $published->getId()->toRfc4122(),
            'second_question' => $second->getId()->toRfc4122(),
            'draft_question' => $draft->getId()->toRfc4122(),
            'archived_question' => $archived->getId()->toRfc4122(),
            'other_grade_question' => $otherGrade->getId()->toRfc4122(),
            'other_subject_question' => $otherSubjectQuestion->getId()->toRfc4122(),
            'question_revision' => $revision->getId()->toRfc4122(),
        ];
        self::ensureKernelShutdown();

        return $ids;
    }

    private function question(
        QuestionManager $questions,
        User $actor,
        User $publisher,
        Subject $subject,
        GradeLevel $grade,
        CurriculumLearningOutcome $outcome,
        string $stem,
        bool $publish,
    ): Question {
        $question = $questions->createDraftQuestion(
            $actor,
            QuestionScope::Platform,
            null,
            $subject,
            $grade,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph($stem),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph($stem.' A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph($stem.' B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.substr(sha1($stem.$grade->value.$subject->getCode()), 0, 12),
        );
        if ($publish) {
            $questions->submitForReview($question, $actor, 'ready_for_review');
            $questions->publish($question, $publisher, 'publish_approved');
        }

        return $question;
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

    private function createPrivileged(string $email, UserRole $role): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Test', 'Kullanici', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        self::ensureKernelShutdown();
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
