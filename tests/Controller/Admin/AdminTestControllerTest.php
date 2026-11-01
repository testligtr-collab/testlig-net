<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Assessment;
use App\Entity\AssessmentRevision;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionAlignment;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionType;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResultReleasePolicy;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Repository\QuestionRevisionAlignmentRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\AssessmentManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
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
        self::assertResponseIsSuccessful();
        $empty = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('En az bir soru ekleyin', $empty);
        self::assertSame('Deneme testi', (string) $client->getCrawler()->filter('#test-title')->attr('value'));
        self::assertStringContainsString('Yonerge metni', $empty);
        self::assertSame('20', (string) $client->getCrawler()->filter('#test-duration')->attr('value'));

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'title' => '<script>alert(1)</script>',
        ]));
        self::assertResponseIsSuccessful();
        $markup = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>', $markup);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $markup);
        self::assertStringContainsString('Başlık ve yönerge düz metin olmalıdır.', $markup);
        self::assertSame('<script>alert(1)</script>', (string) $client->getCrawler()->filter('#test-title')->attr('value'));

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['question'],
                'position' => '1',
                'points' => '0',
            ]],
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Puan 0', (string) $client->getResponse()->getContent());
        self::assertSame('0', (string) $client->getCrawler()->filter('#test-item-points-0')->attr('value'));
        self::assertStringContainsString('Uc nedir?', (string) $client->getResponse()->getContent());
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
        $client->request('GET', $path.'/gorunum');
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
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Yalnız yayımlanmış sorular eklenebilir.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['archived_question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Yalnız yayımlanmış sorular eklenebilir.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['other_grade_question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Soru sınıfı testin sınıfıyla aynı olmalı.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [[
                'question_id' => $seed['other_subject_question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Soru dersi testin dersiyle aynı olmalı.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [
                ['question_id' => $seed['question'], 'position' => '1', 'points' => '1'],
                ['question_id' => $seed['question'], 'position' => '2', 'points' => '1'],
            ],
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('zaten ekli', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [
                ['question_id' => $seed['question'], 'position' => '1', 'points' => '1'],
                ['question_id' => $seed['second_question'], 'position' => '1', 'points' => '2'],
            ],
        ]));
        self::assertResponseIsSuccessful();
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
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Taslak kaydedildi', $html);
        $first = strpos($html, 'Dort nedir?');
        $second = strpos($html, 'Uc nedir?');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertLessThan($second, $first, 'Dort nedir? should render before Uc nedir?.');
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
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('güncellendi', (string) $client->getResponse()->getContent());
        self::assertSame('Siralama testi', (string) $client->getCrawler()->filter('#test-title')->attr('value'));
        self::assertSame('2', (string) $client->getCrawler()->filter('#test-editor input[name="expected_revision"]')->attr('value'));

        $client->request('GET', $path.'/gorunum');
        $preview = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Siralama testi', $preview);
        self::assertStringContainsString('Yonerge', $preview);
        self::assertStringContainsString('Uc', $preview);
        self::assertStringContainsString('Dort', $preview);
        self::assertStringContainsString('Güncel test sürümünün önizlemesidir. Doğru cevaplar ve çözümler gösterilmez.', $preview);
        self::assertStringNotContainsString('correctStableKey', $preview);
        self::assertStringNotContainsString('answer_payload', $preview);
        self::assertStringNotContainsString($seed['question_revision'], $preview);
        self::assertStringNotContainsString('opt_a', $preview);
    }

    public function testPreviewRendersFullDocumentWithoutAnswerOrSolution(): void
    {
        $seed = $this->seed('tbrich');
        $longOption = 'Uzun secenek metni '.str_repeat('devam ', 12);
        $richId = $this->publishRichQuestion($seed, $longOption);
        $this->createPrivileged('tbrich-teacher@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'tbrich-teacher@example.com');
        $token = $this->token($client, $seed);
        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'title' => 'Biçimli önizleme',
            'instructions' => '',
            'duration_minutes' => '',
            'items' => [
                ['question_id' => $richId, 'position' => '1', 'points' => '2.50'],
                ['question_id' => $seed['question'], 'position' => '2', 'points' => '1'],
            ],
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        $path = $client->getRequest()->getPathInfo();
        $assessments = $this->assessmentCount();
        $revisions = $this->revisionCount();
        $audits = $this->successAuditCount();
        $attempts = $this->tableCount('assessment_attempts');
        $answers = $this->tableCount('assessment_attempt_answers');
        $client->request('GET', $path.'/gorunum');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame($assessments, $this->assessmentCount());
        self::assertSame($revisions, $this->revisionCount());
        self::assertSame($audits, $this->successAuditCount());
        self::assertSame($attempts, $this->tableCount('assessment_attempts'));
        self::assertSame($answers, $this->tableCount('assessment_attempt_answers'));
        self::assertSelectorTextContains('h1', 'Biçimli önizleme');
        self::assertStringContainsString('Sürüm 1', $html);
        self::assertStringContainsString('Test önizlemesi', $html);
        self::assertStringContainsString('Güncel test sürümünün önizlemesidir. Doğru cevaplar ve çözümler gösterilmez.', $html);
        self::assertStringContainsString('Yönerge belirtilmemiş.', $html);
        self::assertStringContainsString('Süre sınırı yok', $html);
        self::assertSelectorTextContains('.admin-test-preview__count', '2');
        self::assertSelectorTextContains('.admin-test-preview__points', '3,5');
        self::assertMatchesRegularExpression('/<strong>\s*Kalın kök/', $html);
        self::assertMatchesRegularExpression('/<em>\s*eğik devam\s*<\/em>/', $html);
        self::assertStringContainsString('<li>Liste maddesi bir</li>', $html);
        self::assertStringContainsString('class="q-frac"', $html);
        self::assertStringContainsString('Eski duz paragraf', $html);
        self::assertStringContainsString('Uc nedir?', $html);
        self::assertStringContainsString('Birinci paragraf baslangici', $html);
        self::assertStringContainsString('Ikinci paragraf tamami burada', $html);
        self::assertStringContainsString($longOption, $html);
        self::assertStringContainsString('>A<', $html);
        self::assertStringContainsString('>B<', $html);
        self::assertStringNotContainsString('Gizli cozum cumlesi', $html);
        self::assertStringNotContainsString('opt_a', $html);
        self::assertStringNotContainsString('opt_b', $html);
        self::assertStringNotContainsString('correctStableKey', $html);
        self::assertStringNotContainsString('type="radio"', $html);
        self::assertStringNotContainsString($richId, $html);
        self::assertSelectorExists('a.admin-test-preview__back[href="'.$path.'"]');
    }

    public function testPreviewUsesCurrentRevisionAndPinnedQuestionRevision(): void
    {
        $seed = $this->seed('tbpin');
        $this->createPrivileged('tbpin-teacher@example.com', UserRole::Teacher);
        $this->createPrivileged('tbpin-admin@example.com', UserRole::Admin);
        $client = $this->newClient();
        $this->login($client, 'tbpin-teacher@example.com');
        $path = $this->createDraft($client, $seed, 'Yayin basligi');
        $this->submitVisible($client, '/incelemeye-gonder');
        $client = $this->newClient();
        $this->login($client, 'tbpin-admin@example.com');
        $client->request('GET', $path);
        $this->submitVisible($client, '/yayinla');

        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $assessment = $em->find(Assessment::class, Uuid::fromString(basename($path)));
        $question = $em->find(Question::class, Uuid::fromString($seed['question']));
        $second = $em->find(Question::class, Uuid::fromString($seed['second_question']));
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $actor = $users->findOneBy(['email' => 'tbpin-sa@example.com']);
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertInstanceOf(Question::class, $question);
        self::assertInstanceOf(Question::class, $second);
        self::assertInstanceOf(User::class, $actor);
        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $pinned = $revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        $secondRevision = $revisions->findForQuestionNumber($second, $second->getCurrentRevisionNumber());
        self::assertInstanceOf(QuestionRevision::class, $pinned);
        self::assertInstanceOf(QuestionRevision::class, $secondRevision);
        /** @var QuestionRevisionAlignmentRepository $alignments */
        $alignments = static::getContainer()->get(QuestionRevisionAlignmentRepository::class);
        $alignment = $alignments->findByRevision($pinned)[0] ?? null;
        self::assertInstanceOf(QuestionRevisionAlignment::class, $alignment);
        /** @var AssessmentManager $assessments */
        $assessments = static::getContainer()->get(AssessmentManager::class);
        $assessments->createRevision(
            $assessment,
            $actor,
            'Guncel taslak basligi',
            null,
            null,
            null,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Manual,
            null,
            [
                $this->previewSection($question, $pinned, 1, '1.00'),
                $this->previewSection($second, $secondRevision, 2, '2.00'),
            ],
            'preview_revision',
            $assessment->getSubject(),
        );
        /** @var QuestionManager $questions */
        $questions = static::getContainer()->get(QuestionManager::class);
        $questions->createRevision(
            $question,
            $actor,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Degisen kok metni'),
            QuestionContentDocument::paragraph('Gizli cozum cumlesi')->toArray(),
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Yeni A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Yeni B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $alignment->getLearningOutcome(), 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'preview_question_revision',
        );
        self::ensureKernelShutdown();

        $client = $this->newClient();
        $this->login($client, 'tbpin-admin@example.com');
        $assessmentsCount = $this->assessmentCount();
        $revisions = $this->revisionCount();
        $audits = $this->successAuditCount();
        $client->request('GET', $path.'/gorunum');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame($assessmentsCount, $this->assessmentCount());
        self::assertSame($revisions, $this->revisionCount());
        self::assertSame($audits, $this->successAuditCount());
        self::assertSelectorTextContains('h1', 'Guncel taslak basligi');
        self::assertStringContainsString('Sürüm 2', $html);
        self::assertStringNotContainsString('Yayin basligi', $html);
        self::assertStringNotContainsString('Yonerge metni', $html);
        self::assertStringContainsString('Yönerge belirtilmemiş.', $html);
        self::assertStringContainsString('Süre sınırı yok', $html);
        self::assertStringNotContainsString('20 dk', $html);
        $first = strpos($html, 'Uc nedir?');
        $secondPos = strpos($html, 'Dort nedir?');
        self::assertNotFalse($first);
        self::assertNotFalse($secondPos);
        self::assertLessThan($secondPos, $first);
        self::assertSame(2, substr_count($html, 'admin-test-preview__question">'));
        self::assertStringNotContainsString('Degisen kok metni', $html);
        self::assertStringNotContainsString('Gizli cozum cumlesi', $html);
        self::assertStringNotContainsString('Yeni A', $html);
        self::assertStringNotContainsString('opt_a', $html);
        self::assertStringNotContainsString('correctStableKey', $html);
        self::assertStringNotContainsString($pinned->getId()->toRfc4122(), $html);
    }

    public function testPreviewQueryCountDoesNotGrowWithQuestions(): void
    {
        $seed = $this->seed('tbqcount');
        $this->createPrivileged('tbqcount-teacher@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'tbqcount-teacher@example.com');
        $one = $this->createDraft($client, $seed, 'Tek soru');
        $two = $this->createDraft($client, $seed, 'Iki soru', [
            ['question_id' => $seed['question'], 'position' => '1', 'points' => '1'],
            ['question_id' => $seed['second_question'], 'position' => '2', 'points' => '2'],
        ]);
        $first = $this->previewQueryCounts($client, $one.'/gorunum');
        $second = $this->previewQueryCounts($client, $two.'/gorunum');
        self::assertSame(1, $first['items']);
        self::assertSame(1, $second['items']);
        self::assertSame(1, $first['options']);
        self::assertSame(1, $second['options']);
        self::assertSame(0, $first['leaks']);
        self::assertSame(0, $second['leaks']);
        self::assertSame(0, $first['writes']);
        self::assertSame(0, $second['writes']);
        self::assertStringContainsString('Uc nedir?', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Dort nedir?', (string) $client->getResponse()->getContent());
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

    public function testListVisibilityFiltersCountersAndCurrentRevisionCount(): void
    {
        $seed = $this->seed('tb');
        $this->createPrivileged('tb-author@example.com', UserRole::Teacher);
        $this->createPrivileged('tb-other@example.com', UserRole::Teacher);
        $this->createPrivileged('tb-mod@example.com', UserRole::Moderator);
        $client = $this->newClient();
        $this->login($client, 'tb-author@example.com');
        $title = 'Toplama alfa & '.str_repeat('uzunbaslik ', 8);
        $path = $this->createDraft($client, $seed, $title, [
            ['question_id' => $seed['question'], 'position' => '1', 'points' => '2'],
            ['question_id' => $seed['second_question'], 'position' => '2', 'points' => '3'],
        ]);
        $edit = $client->request('GET', $path.'/duzenle');
        self::assertResponseIsSuccessful();
        $client->request('POST', $path.'/duzenle', $this->payload($seed, [
            '_token' => (string) $edit->filter('#test-editor input[name="_token"]')->attr('value'),
            'expected_revision' => (string) $edit->filter('#test-editor input[name="expected_revision"]')->attr('value'),
            'title' => $title,
            'items' => [[
                'question_id' => $seed['question'],
                'position' => '1',
                'points' => '2',
            ]],
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $this->token($client, $seed),
            'title' => 'Cikarma beta',
            'subject_id' => $seed['other_subject'],
            'items' => [[
                'question_id' => $seed['other_subject_question'],
                'position' => '1',
                'points' => '1',
            ]],
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();

        $client->request('GET', '/yonetim/testler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sayaçlar filtrelerden bağımsızdır.');
        self::assertSame('2', trim($client->getCrawler()->filter('[data-test-summary="draft"]')->text()));
        self::assertSelectorExists('a[href="/yonetim/testler/yeni"]');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Toplama alfa &amp;', $html);
        self::assertStringNotContainsString('Toplama alfa & uzunbaslik', $html);
        $row = $client->getCrawler()->filterXPath('//tr[contains(., "Toplama alfa")]');
        self::assertSame('1', trim($row->filter('.admin-test__count')->text()));
        self::assertGreaterThan(20, \strlen(trim($row->filter('code')->text())));
        self::assertSelectorExists('summary[role="button"]');

        $client->request('GET', '/yonetim/testler?q=alfa&subject_id='.$seed['subject'].'&grade=1&status=draft');
        self::assertSelectorTextContains('body', 'Toplama alfa');
        self::assertSelectorTextNotContains('body', 'Cikarma beta');
        self::assertSame('2', trim($client->getCrawler()->filter('[data-test-summary="draft"]')->text()));
        self::assertSame('/yonetim/testler', $client->getCrawler()->filter('.admin-user-clear')->attr('href'));
        self::assertSelectorExists('option[value="draft"][selected]');

        $client->request('GET', '/yonetim/testler?grade=2');
        self::assertSelectorTextContains('body', 'Bu filtreye uyan test yok.');
        self::assertSame('2', trim($client->getCrawler()->filter('[data-test-summary="draft"]')->text()));

        $client->request('GET', '/yonetim/testler?subject_id=not-a-subject&q='.rawurlencode('Toplama <script>'));
        self::assertSelectorTextContains('body', 'Bu filtreye uyan test yok.');
        self::assertStringNotContainsString('<script>', (string) $client->getResponse()->getContent());
        self::assertSame('2', trim($client->getCrawler()->filter('[data-test-summary="draft"]')->text()));

        $client = $this->newClient();
        $this->login($client, 'tb-other@example.com');
        $client->request('GET', '/yonetim/testler');
        self::assertSelectorTextContains('body', 'Henüz görebildiğiniz bir test yok.');
        self::assertSelectorTextNotContains('body', 'Toplama alfa');
        self::assertSame('0', trim($client->getCrawler()->filter('[data-test-summary="draft"]')->text()));
        self::assertSelectorExists('a[href="/yonetim/testler/yeni"]');

        $client = $this->newClient();
        $this->login($client, 'tb-mod@example.com');
        $client->request('GET', '/yonetim/testler');
        self::assertSelectorTextContains('body', 'Toplama alfa');
        self::assertSelectorNotExists('a[href="/yonetim/testler/yeni"]');
    }

    public function testListStaysPlatformScopedAndReadOnly(): void
    {
        $seed = $this->seed('iso');
        $this->createPrivileged('iso-author@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'iso-author@example.com');
        $this->createDraft($client, $seed, 'Platform gorunen');
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $owner = $users->findOneBy(['email' => 'iso-author@example.com']);
        self::assertInstanceOf(User::class, $owner);
        $institution = Institution::create('Kurum Liste', 'kurum liste', 'kurum-liste-iso', InstitutionType::School, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $institutionAssessment = Assessment::createDraft(AssessmentScope::Institution, $institution, AssessmentType::Quiz, GradeLevel::Grade1, $owner, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $em->persist($institution);
        $em->persist($institutionAssessment);
        $em->flush();
        $hiddenPreview = '/yonetim/testler/'.$institutionAssessment->getId()->toRfc4122().'/gorunum';
        $before = $em->getConnection()->fetchFirstColumn('SELECT updated_at FROM assessments WHERE scope = ? ORDER BY code', ['platform']);
        self::ensureKernelShutdown();

        try {
            $client = $this->newClient();
            $this->login($client, 'iso-editor@example.com');
            self::ensureKernelShutdown();
            self::bootKernel();
            $auditEm = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $auditEm);
            $audits = (int) $auditEm->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');
            self::ensureKernelShutdown();
            $client->request('GET', '/yonetim/testler');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Platform gorunen');
            self::assertSame(1, $client->getCrawler()->filter('.admin-test-table tbody tr')->count());
            self::assertStringNotContainsString('Kurum Liste', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('correctStableKey', (string) $client->getResponse()->getContent());
            $client->request('GET', $hiddenPreview);
            self::assertResponseStatusCodeSame(404);
            self::ensureKernelShutdown();
            self::bootKernel();
            $connection = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $connection);
            self::assertSame($before, $connection->getConnection()->fetchFirstColumn('SELECT updated_at FROM assessments WHERE scope = ? ORDER BY code', ['platform']));
            self::assertSame($audits, (int) $connection->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events'));
        } finally {
            self::ensureKernelShutdown();
            self::bootKernel();
            $cleanup = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanup);
            $cleanup->getConnection()->executeStatement('DELETE FROM assessments WHERE scope = ?', ['institution']);
            $cleanup->getConnection()->executeStatement('DELETE FROM institutions WHERE slug = ?', ['kurum-liste-iso']);
            self::ensureKernelShutdown();
        }
    }

    public function testCountersIgnoreTheHundredRowPage(): void
    {
        $this->createPrivileged('cap-admin@example.com', UserRole::Admin);
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $owner = $users->findOneBy(['email' => 'cap-admin@example.com']);
        self::assertInstanceOf(User::class, $owner);
        for ($i = 0; $i < 101; ++$i) {
            $em->persist(Assessment::createDraft(AssessmentScope::Platform, null, AssessmentType::Quiz, GradeLevel::Grade1, $owner, new \DateTimeImmutable('2026-01-01 00:00:00')));
        }
        $em->flush();
        self::ensureKernelShutdown();

        $client = $this->newClient();
        $this->login($client, 'cap-admin@example.com');
        $client->request('GET', '/yonetim/testler');
        self::assertResponseIsSuccessful();
        self::assertSame('101', trim($client->getCrawler()->filter('[data-test-summary="draft"]')->text()));
        self::assertSame(100, $client->getCrawler()->filter('.admin-test-table tbody tr')->count());
        self::assertSelectorTextContains('body', 'Listede en yeni 100 kayıt görünüyor.');
        self::assertSelectorTextContains('body', 'Filtreye uyan kayıt sayısı 101.');
    }

    public function testEditorLayoutKeepsSearchAndDoesNotWrite(): void
    {
        $seed = $this->seed('tbedit');
        $this->createPrivileged('tb-teacher-edit@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'tb-teacher-edit@example.com');
        $before = $this->assessmentCount();
        $crawler = $client->request('GET', '/yonetim/testler/yeni?grade=1&subject_id='.$seed['subject'].'&q=Uc&page=2');
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->assessmentCount());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Yeni test', $html);
        self::assertStringContainsString('Kaydetme taslak sürüm oluşturur', $html);
        self::assertStringContainsString('Soru havuzundan seç', $html);
        self::assertStringContainsString('Sınıflandırma', $html);
        self::assertStringContainsString('Bu filtrede yayımlanmış soru yok', $html);
        self::assertStringNotContainsString('correctStableKey', $html);
        self::assertStringNotContainsString('opt_b', $html);
        self::assertStringNotContainsString('Taslak soru', $html);
        self::assertSame('Uc', (string) $crawler->filter('#test-q')->attr('value'));
        $previous = (string) $crawler->selectLink('Önceki')->attr('href');
        self::assertStringContainsString('q=Uc', $previous);
        self::assertStringContainsString('grade=1', $previous);
        self::assertStringContainsString('subject_id='.$seed['subject'], $previous);

        $path = $this->createDraft($client, $seed, 'Duzen duzeni');
        $edit = $client->request('GET', $path.'/duzenle');
        self::assertResponseIsSuccessful();
        $editHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Testi düzenle', $editHtml);
        self::assertStringContainsString('formu gönderir', $editHtml);
        self::assertStringContainsString('1. sınıf', $editHtml);
        self::assertStringNotContainsString('correctStableKey', $editHtml);
        self::assertSame(0, $edit->filter('#test-grade')->count());
        self::assertSame('submit', (string) $edit->filter('button[name="move_up_0"]')->attr('type'));
        self::assertGreaterThan(0, $edit->filter('#test-editor input[name="expected_revision"]')->count());
    }

    public function testInvalidEditorKeepsSafeInputAndMatchesGradeSummary(): void
    {
        $seed = $this->seed('tbkeep');
        $this->createPrivileged('tb-teacher-keep@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'tb-teacher-keep@example.com');

        $open = $client->request('GET', '/yonetim/testler/yeni');
        self::assertResponseIsSuccessful();
        self::assertSame('1', (string) $open->filter('#test-grade option[selected]')->attr('value'));
        $openHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<dt>Sınıf</dt><dd>1. sınıf</dd>', $openHtml);
        self::assertStringContainsString('<dt>Ders</dt><dd>Seçilmedi</dd>', $openHtml);
        self::assertSame('', (string) $open->filter('#test-subject option[selected]')->attr('value'));

        $listed = $client->request('GET', '/yonetim/testler/yeni?grade=1&subject_id='.$seed['subject'].'&q=Uc');
        self::assertSame('1', (string) $listed->filter('#test-grade option[selected]')->attr('value'));
        self::assertSame($seed['subject'], (string) $listed->filter('#test-subject option[selected]')->attr('value'));
        $listedHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<dt>Sınıf</dt><dd>1. sınıf</dd>', $listedHtml);
        self::assertStringContainsString('<dt>Ders</dt><dd>Ders tbkeep</dd>', $listedHtml);
        self::assertSame('Uc', (string) $listed->filter('#test-q')->attr('value'));

        $invalidGrade = $client->request('GET', '/yonetim/testler/yeni?grade=99&subject_id='.$seed['subject']);
        self::assertSame('99', (string) $invalidGrade->filter('#test-grade option[selected]')->attr('value'));
        $invalidHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<dt>Sınıf</dt><dd>99</dd>', $invalidHtml);
        self::assertStringNotContainsString('Uc nedir?', $invalidHtml);

        $beforeAssessments = $this->assessmentCount();
        $beforeRevisions = $this->revisionCount();
        $beforeAudits = $this->successAuditCount();
        $token = $this->token($client, $seed);
        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'items' => [],
            'search_q' => 'Uc',
            'search_outcome' => 'kazanim-1',
            'search_difficulty' => 'easy',
            'search_page' => '2',
        ]));
        self::assertResponseIsSuccessful();
        $kept = $client->getCrawler();
        $keptHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('En az bir soru ekleyin', $keptHtml);
        self::assertSame('Deneme testi', (string) $kept->filter('#test-title')->attr('value'));
        self::assertStringContainsString('Yonerge metni', $keptHtml);
        self::assertSame('20', (string) $kept->filter('#test-duration')->attr('value'));
        self::assertSame('1', (string) $kept->filter('#test-grade option[selected]')->attr('value'));
        self::assertSame($seed['subject'], (string) $kept->filter('#test-subject option[selected]')->attr('value'));
        self::assertStringContainsString('<dt>Sınıf</dt><dd>1. sınıf</dd>', $keptHtml);
        self::assertStringContainsString('<dt>Ders</dt><dd>Ders tbkeep</dd>', $keptHtml);
        self::assertSame('Uc', (string) $kept->filter('#test-q')->attr('value'));
        self::assertSame('kazanim-1', (string) $kept->filter('#test-outcome')->attr('value'));
        self::assertSame('easy', (string) $kept->filter('#test-difficulty option[selected]')->attr('value'));
        self::assertSame($beforeAssessments, $this->assessmentCount());
        self::assertSame($beforeRevisions, $this->revisionCount());
        self::assertSame($beforeAudits, $this->successAuditCount());

        $foreign = '00000000-0000-4000-8000-000000000099';
        $client->request('POST', '/yonetim/testler/yeni', $this->payload($seed, [
            '_token' => $token,
            'title' => 'Yabanci deneme',
            'items' => [
                ['question_id' => $seed['draft_question'], 'position' => '1', 'points' => '1'],
                ['question_id' => $seed['archived_question'], 'position' => '2', 'points' => '1'],
                ['question_id' => $seed['other_grade_question'], 'position' => '3', 'points' => '1'],
                ['question_id' => $seed['other_subject_question'], 'position' => '4', 'points' => '1'],
                ['question_id' => $foreign, 'position' => '5', 'points' => '1'],
            ],
        ]));
        self::assertResponseIsSuccessful();
        $foreignHtml = (string) $client->getResponse()->getContent();
        self::assertSame('Yabanci deneme', (string) $client->getCrawler()->filter('#test-title')->attr('value'));
        self::assertStringNotContainsString('Taslak soru', $foreignHtml);
        self::assertStringNotContainsString('Arsiv soru', $foreignHtml);
        self::assertStringNotContainsString('Baska sinif', $foreignHtml);
        self::assertStringNotContainsString('Baska ders', $foreignHtml);
        self::assertStringNotContainsString($foreign, $foreignHtml);
        self::assertStringNotContainsString('opt_a', $foreignHtml);
        self::assertStringNotContainsString('correctStableKey', $foreignHtml);
        self::assertSame($beforeAssessments, $this->assessmentCount());
        self::assertSame($beforeRevisions, $this->revisionCount());
        self::assertSame($beforeAudits, $this->successAuditCount());

        $path = $this->createDraft($client, $seed, 'Duzen duzeni');
        $savedAssessments = $this->assessmentCount();
        $savedRevisions = $this->revisionCount();
        $savedAudits = $this->successAuditCount();
        $edit = $client->request('GET', $path.'/duzenle');
        $client->request('POST', $path.'/duzenle', $this->payload($seed, [
            '_token' => (string) $edit->filter('#test-editor input[name="_token"]')->attr('value'),
            'expected_revision' => '1',
            'title' => 'Bozuk baslik',
            'grade' => '2',
            'subject_id' => $seed['other_subject'],
            'items' => [[
                'question_id' => $seed['question'],
                'position' => '0',
                'points' => '0',
            ]],
        ]));
        self::assertResponseIsSuccessful();
        $broken = $client->getCrawler();
        $brokenHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Sıra 1 veya daha büyük olmalıdır.', $brokenHtml);
        self::assertSame('Bozuk baslik', (string) $broken->filter('#test-title')->attr('value'));
        self::assertStringContainsString('Yonerge metni', $brokenHtml);
        self::assertSame('20', (string) $broken->filter('#test-duration')->attr('value'));
        self::assertSame('0', (string) $broken->filter('#test-item-position-0')->attr('value'));
        self::assertSame('0', (string) $broken->filter('#test-item-points-0')->attr('value'));
        self::assertStringContainsString('Uc nedir?', $brokenHtml);
        self::assertStringContainsString('1. sınıf', $brokenHtml);
        self::assertStringNotContainsString('2. sınıf', $brokenHtml);
        self::assertStringContainsString('Ders tbkeep', $brokenHtml);
        self::assertStringNotContainsString('Diger tbkeep', $brokenHtml);
        self::assertSame('1', (string) $broken->filter('#test-editor input[name="grade"]')->attr('value'));
        self::assertSame($seed['subject'], (string) $broken->filter('#test-editor input[name="subject_id"]')->attr('value'));
        self::assertSame($savedAssessments, $this->assessmentCount());
        self::assertSame($savedRevisions, $this->revisionCount());
        self::assertSame($savedAudits, $this->successAuditCount());

        $fresh = $client->request('GET', $path.'/duzenle');
        self::assertSame('Duzen duzeni', (string) $fresh->filter('#test-title')->attr('value'));
        self::assertSame('1', (string) $fresh->filter('#test-editor input[name="expected_revision"]')->attr('value'));
        self::assertSame('1', (string) $fresh->filter('#test-item-position-0')->attr('value'));
        self::assertNotSame('0', (string) $fresh->filter('#test-item-points-0')->attr('value'));
    }

    public function testDetailShowsCurrentRevisionAndKeepsWorkflowSeparate(): void
    {
        $seed = $this->seed('tbdet');
        $this->createPrivileged('tbdet-teacher@example.com', UserRole::Teacher);
        $this->createPrivileged('tbdet-moderator@example.com', UserRole::Moderator);
        $this->createPrivileged('tbdet-admin@example.com', UserRole::Admin);
        $client = $this->newClient();
        $this->login($client, 'tbdet-teacher@example.com');
        $path = $this->createDraft($client, $seed, 'Detay ilk', [
            ['question_id' => $seed['question'], 'position' => '1', 'points' => '2'],
            ['question_id' => $seed['second_question'], 'position' => '2', 'points' => '3'],
        ]);
        $assessments = $this->assessmentCount();
        $revisions = $this->revisionCount();
        $audits = $this->successAuditCount();
        $page = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame($assessments, $this->assessmentCount());
        self::assertSame($revisions, $this->revisionCount());
        self::assertSame($audits, $this->successAuditCount());
        self::assertSelectorTextContains('h1', 'Detay ilk');
        self::assertSelectorTextContains('.admin-test-detail__count', '2');
        self::assertSelectorTextContains('.admin-test-detail__points', '5');
        self::assertSelectorTextContains('.admin-test-detail__duration', '20 dk');
        self::assertStringContainsString('Yonerge metni', $html);
        self::assertStringContainsString('Uc nedir?', $html);
        self::assertStringContainsString('Dort nedir?', $html);
        self::assertStringContainsString('1. sınıf', $html);
        self::assertStringContainsString('Kısa test', $html);
        self::assertSelectorExists('a[href$="/duzenle"]');
        self::assertSelectorExists('form[action$="/incelemeye-gonder"]');
        self::assertSelectorNotExists('form[action$="/yayinla"]');
        self::assertSelectorNotExists('form[action$="/arsivle"]');
        self::assertStringNotContainsString('/sonuclar', $html);
        self::assertStringNotContainsString('Yeni sürüm', $html);
        self::assertStringNotContainsString('correctStableKey', $html);
        self::assertStringNotContainsString('opt_a', $html);
        self::assertStringNotContainsString('Doğru cevap', $html);
        self::assertStringNotContainsString('Taslak soru', $html);
        self::assertStringNotContainsString('Baska ders', $html);
        self::assertStringNotContainsString($seed['question_revision'], $html);
        self::assertSelectorTextContains('.admin-test-detail__policy', 'Politika tanımlanmamış');

        $token = (string) $page->filter('form[action$="/incelemeye-gonder"] input[name="_token"]')->attr('value');
        $edit = $client->request('GET', $path.'/duzenle');
        $client->request('POST', $path.'/duzenle', $this->payload($seed, [
            '_token' => (string) $edit->filter('#test-editor input[name="_token"]')->attr('value'),
            'expected_revision' => '1',
            'title' => 'Detay guncel',
            'instructions' => '',
            'items' => [[
                'question_id' => $seed['question'],
                'position' => '1',
                'points' => '2',
            ]],
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        $assessments = $this->assessmentCount();
        $revisions = $this->revisionCount();
        $audits = $this->successAuditCount();
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame($assessments, $this->assessmentCount());
        self::assertSame($revisions, $this->revisionCount());
        self::assertSame($audits, $this->successAuditCount());
        $current = (string) $client->getResponse()->getContent();
        self::assertSelectorTextContains('h1', 'Detay guncel');
        self::assertStringNotContainsString('Detay ilk', $current);
        self::assertStringContainsString('Uc nedir?', $current);
        self::assertStringNotContainsString('Dort nedir?', $current);
        self::assertSelectorTextContains('.admin-test-detail__count', '1');
        self::assertSelectorTextContains('.admin-test-detail__points', '2');
        self::assertStringContainsString('Yönerge yok.', $current);

        $client->request('POST', $path.'/incelemeye-gonder', [
            '_token' => $token,
            'expected_revision' => '2',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        $author = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kendi hazırladığınız testi yayınlayamazsınız.', $author);
        self::assertStringNotContainsString('/yayinla', $author);
        self::assertSelectorNotExists('a[href$="/duzenle"]');

        $client = $this->newClient();
        $this->login($client, 'tbdet-moderator@example.com');
        $client->request('GET', $path);
        $moderator = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/taslaga-dondur', $moderator);
        self::assertStringNotContainsString('/yayinla', $moderator);
        self::assertStringNotContainsString('/duzenle', $moderator);
        self::assertStringNotContainsString('/sonuclar', $moderator);

        $client = $this->newClient();
        $this->login($client, 'tbdet-admin@example.com');
        $client->request('GET', $path);
        $review = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/yayinla', $review);
        self::assertSelectorExists('form[action$="/yayinla"] input[name="expected_revision"]');
        self::assertSelectorExists('form[action$="/yayinla"] textarea[name="operator_note"]');
        self::assertStringNotContainsString('/duzenle', $review);
        self::assertStringNotContainsString('Yeni sürüm', $review);
        self::assertStringContainsString('/sonuclar', $review);
        $this->submitVisible($client, '/yayinla');
        self::assertStringContainsString('Test yayınlandı', (string) $client->getResponse()->getContent());
        $client->request('GET', $path);
        self::assertSelectorTextContains('.admin-test-detail__status', 'Yayında');
        self::assertSelectorExists('form[action$="/arsivle"] input[name="expected_revision"]');
        self::assertSelectorExists('form[action$="/arsivle"] input[name="_token"]');
        self::assertSelectorExists('form[action$="/arsivle"] textarea[name="operator_note"]');
        $published = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('/duzenle', $published);
        self::assertStringNotContainsString('Yeni sürüm', $published);
        self::assertSelectorTextContains('.admin-test-detail__policy', 'Politika tanımlanmamış');
        $this->submitVisible($client, '/arsivle');
        $archived = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Arşivlenmiş', $archived);
        self::assertStringNotContainsString('/arsivle', $archived);
        self::assertStringNotContainsString('/yayinla', $archived);
        self::assertStringNotContainsString('/taslaga-dondur', $archived);
        self::assertStringNotContainsString('/duzenle', $archived);
        self::assertStringNotContainsString('Yeni sürüm', $archived);
    }

    /**
     * @param array{subject: string, question: string} $seed
     */
    private function publishRichQuestion(array $seed, string $longOption): string
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $base = $em->find(Question::class, Uuid::fromString($seed['question']));
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $actor = $users->findOneBy(['email' => 'tbrich-sa@example.com']);
        $publisher = $users->findOneBy(['email' => 'tbrich-editor@example.com']);
        self::assertInstanceOf(Question::class, $base);
        self::assertInstanceOf(User::class, $actor);
        self::assertInstanceOf(User::class, $publisher);
        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($base, $base->getCurrentRevisionNumber());
        self::assertInstanceOf(QuestionRevision::class, $revision);
        /** @var QuestionRevisionAlignmentRepository $alignments */
        $alignments = static::getContainer()->get(QuestionRevisionAlignmentRepository::class);
        $alignment = $alignments->findByRevision($revision)[0] ?? null;
        self::assertInstanceOf(QuestionRevisionAlignment::class, $alignment);
        /** @var QuestionManager $questions */
        $questions = static::getContainer()->get(QuestionManager::class);
        $rich = $questions->createDraftQuestion(
            $actor,
            QuestionScope::Platform,
            null,
            $base->getSubject(),
            GradeLevel::Grade1,
            QuestionType::SingleChoice,
            new QuestionContentDocument(QuestionContentDocument::SCHEMA_VERSION, [
                ['type' => 'paragraph', 'inlines' => [
                    ['type' => 'text', 'text' => 'Kalın kök ', 'marks' => ['bold']],
                    ['type' => 'text', 'text' => 'eğik devam', 'marks' => ['italic']],
                ]],
                ['type' => 'list', 'style' => 'bullet', 'items' => ['Liste maddesi bir', 'Liste maddesi iki']],
                ['type' => 'math', 'latex' => '\\frac{3}{4}'],
                ['type' => 'paragraph', 'text' => 'Eski duz paragraf'],
            ]),
            QuestionContentDocument::paragraph('Gizli cozum cumlesi')->toArray(),
            [
                ['stableKey' => 'opt_a', 'content' => new QuestionContentDocument(QuestionContentDocument::SCHEMA_VERSION, [
                    ['type' => 'paragraph', 'inlines' => [
                        ['type' => 'text', 'text' => 'Birinci paragraf baslangici'],
                    ]],
                    ['type' => 'paragraph', 'inlines' => [
                        ['type' => 'text', 'text' => 'Ikinci paragraf tamami burada'],
                    ]],
                ]), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph($longOption), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $alignment->getLearningOutcome(), 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_rich',
        );
        $questions->submitForReview($rich, $actor, 'ready_for_review');
        $questions->publish($rich, $publisher, 'publish_approved');
        $id = $rich->getId()->toRfc4122();
        self::ensureKernelShutdown();

        return $id;
    }

    /**
     * @return array{title: string, position: int, questionOrderMode: QuestionOrderMode, items: list<array{questionId: Uuid, questionRevisionId: Uuid, position: int, points: string, penaltyPoints: string, required: true}>}
     */
    private function previewSection(Question $question, QuestionRevision $revision, int $position, string $points): array
    {
        return [
            'title' => 'Bolum '.$position,
            'position' => $position,
            'questionOrderMode' => QuestionOrderMode::Fixed,
            'items' => [[
                'questionId' => $question->getId(),
                'questionRevisionId' => $revision->getId(),
                'position' => 1,
                'points' => $points,
                'penaltyPoints' => '0.00',
                'required' => true,
            ]],
        ];
    }

    /**
     * @return array{items: int, options: int, leaks: int, writes: int}
     */
    private function previewQueryCounts(KernelBrowser $client, string $path): array
    {
        $holder = $client->getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $counts = ['items' => 0, 'options' => 0, 'leaks' => 0, 'writes' => 0];
        foreach ($holder->getData() as $queries) {
            foreach ($queries as $query) {
                $sql = $query['sql'] ?? null;
                if (!\is_string($sql)) {
                    continue;
                }
                $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $sql))));
                if (str_contains($normalized, 'from assessment_items')) {
                    ++$counts['items'];
                }
                if (str_contains($normalized, 'from question_revision_options')) {
                    ++$counts['options'];
                }
                if (str_contains($normalized, 'explanation_content') || str_contains($normalized, 'stable_key') || str_contains($normalized, 'question_answer_keys')) {
                    ++$counts['leaks'];
                }
                if (1 === preg_match('/^(insert|update|delete)\b/', $normalized)
                    && (str_contains($normalized, 'assessment') || str_contains($normalized, 'question_answer') || str_contains($normalized, 'security_audit'))
                ) {
                    ++$counts['writes'];
                }
            }
        }

        return $counts;
    }

    private function tableCount(string $table): int
    {
        $sql = match ($table) {
            'assessment_attempts' => 'SELECT COUNT(*) FROM assessment_attempts',
            'assessment_attempt_answers' => 'SELECT COUNT(*) FROM assessment_attempt_answers',
            default => throw new \InvalidArgumentException('Unexpected table.'),
        };
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->getConnection()->fetchOne($sql);
    }

    private function assessmentCount(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->createQuery('SELECT COUNT(a.id) FROM '.Assessment::class.' a')->getSingleScalarResult();
    }

    private function revisionCount(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->createQuery('SELECT COUNT(r.id) FROM '.AssessmentRevision::class.' r')->getSingleScalarResult();
    }

    private function successAuditCount(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM security_audit_events WHERE action IN ('assessment_created', 'assessment_revision_created')",
        );
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
