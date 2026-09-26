<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\Assessment;
use App\Entity\AssessmentAttemptItem;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentAttemptFailureReason;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResultReleasePolicy;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AssessmentAttemptException;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentAttemptItemRepository;
use App\Repository\AssessmentAttemptRepository;
use App\Repository\AssessmentPlatformPracticeRepository;
use App\Repository\AssessmentRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\AssessmentAttemptManager;
use App\Service\AssessmentManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\StudentProfileManager;
use App\Service\SubjectManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class StudentAssessmentPracticeTest extends WebTestCase
{
    protected function setUp(): void
    {
        Clock::set(new NativeClock());
        $this->purge();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        $this->purge();
        parent::tearDown();
    }

    public function testAnonymousRedirectsAndOtherRolesAreForbidden(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ogrenci/testler');
        self::assertResponseRedirects('/giris');

        $this->createActive('practice-teacher@example.com', UserRole::Teacher);
        $client = static::createClient();
        $this->login($client, 'practice-teacher@example.com');
        $client->request('GET', '/ogrenci/testler');
        self::assertResponseStatusCodeSame(403);

        $this->createPrivileged('practice-admin@example.com', UserRole::Admin);
        $client = static::createClient();
        $this->login($client, 'practice-admin@example.com');
        $client->request('GET', '/ogrenci/testler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIncompleteOnboardingRedirects(): void
    {
        $this->createActive('practice-new@example.com', UserRole::Student);
        $client = static::createClient();
        $this->login($client, 'practice-new@example.com');
        $client->request('GET', '/ogrenci/testler');
        self::assertResponseRedirects('/ogrenci/kurulum');
    }

    public function testStudentSeesOnlyPublishedTestsForTheirGrade(): void
    {
        $seed = $this->seed('p61a');
        $student = $this->createActive('practice-grade@example.com', UserRole::Student);
        $this->completeOnboarding($student, GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'practice-grade@example.com');

        $client->request('GET', '/ogrenci/testler');
        self::assertResponseIsSuccessful();
        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('Sinif testi', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Başla', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Baska sinif testi', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Taslak testi', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Arsiv testi', (string) $client->getResponse()->getContent());

        $client->request('GET', '/ogrenci/testler/'.$seed['other']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/ogrenci/testler/'.$seed['draft']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/ogrenci/testler/'.$seed['archived']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/ogrenci/testler/'.str_repeat('a', 32));
        self::assertResponseStatusCodeSame(404);
    }

    public function testStartIsUniqueAndAnswersStayWithTheOwner(): void
    {
        $seed = $this->seed('p61b');
        $owner = $this->createActive('practice-owner@example.com', UserRole::Student);
        $this->completeOnboarding($owner, GradeLevel::Grade1);
        $other = $this->createActive('practice-other@example.com', UserRole::Student);
        $this->completeOnboarding($other, GradeLevel::Grade1);

        $client = static::createClient();
        $this->login($client, 'practice-owner@example.com');
        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/baslat', ['_token' => 'nope']);
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['main']);
        self::assertSame(0, $this->countRows('assessment_attempts'));

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['main']);
        $client->submit($crawler->filter('#student-test-start')->form());
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Doğru cevap', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('opt_b', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($seed['hidden'], (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Bir nedir?', (string) $client->getResponse()->getContent());

        $token = (string) $crawler->filter('#student-test-answer input[name="_token"]')->attr('value');
        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/baslat', ['_token' => $token]);
        $client->followRedirect();
        self::assertSame(1, $this->countRows('assessment_attempts'));
        $client->request('GET', '/ogrenci/testler');
        self::assertStringContainsString('Devam et', (string) $client->getResponse()->getContent());

        self::ensureKernelShutdown();
        $otherClient = static::createClient();
        $this->login($otherClient, 'practice-other@example.com');
        $otherClient->request('GET', '/ogrenci/testler/'.$seed['main'].'/sonuc');
        self::assertResponseStatusCodeSame(404);
        $crawler = $otherClient->request('GET', '/ogrenci/testler/'.$seed['main']);
        $otherClient->submit($crawler->filter('#student-test-start')->form());
        $otherClient->followRedirect();
        self::assertStringNotContainsString('Doğru cevap', (string) $otherClient->getResponse()->getContent());
        self::assertSame(2, $this->countRows('assessment_attempts'));
    }

    public function testScoringIsDeterministicAndSubmittedAnswersStayFixed(): void
    {
        $seed = $this->seed('p61c');
        $student = $this->createActive('practice-score@example.com', UserRole::Student);
        $this->completeOnboarding($student, GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'practice-score@example.com');
        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['main']);
        self::assertStringContainsString('6.00', (string) $client->getResponse()->getContent());
        $client->submit($crawler->filter('#student-test-start')->form());
        $client->followRedirect();

        $crawler = $client->getCrawler();
        $token = (string) $crawler->filter('#student-test-answer input[name="_token"]')->attr('value');
        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/cevap', [
            '_token' => $token,
            'position' => '1',
            'choice' => '1',
            'expected_version' => '0',
            'score' => '100',
            'correct' => '1',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Kaydedildi', (string) $client->getResponse()->getContent());

        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/cevap', [
            '_token' => $token,
            'position' => '1',
            'choice' => '1',
            'expected_version' => '0',
        ]);
        $client->followRedirect();
        self::assertStringContainsString('başka bir kayıtla güncellendi', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['main'].'/coz?s=1');
        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/cevap', [
            '_token' => (string) $crawler->filter('#student-test-answer input[name="_token"]')->attr('value'),
            'position' => '1',
            'choice' => '2',
            'expected_version' => (string) $crawler->filter('input[name="expected_version"]')->attr('value'),
        ]);
        $client->followRedirect();

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['main'].'/coz?s=2');
        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/cevap', [
            '_token' => (string) $crawler->filter('#student-test-answer input[name="_token"]')->attr('value'),
            'position' => '2',
            'choice' => '1',
            'expected_version' => (string) $crawler->filter('input[name="expected_version"]')->attr('value'),
        ]);
        $client->followRedirect();

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['main'].'/coz?s=1');
        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/bitir', [
            '_token' => (string) $crawler->filter('#student-test-finish input[name="_token"]')->attr('value'),
        ]);
        $client->followRedirect();
        self::assertStringContainsString('onay kutusunu işaretle', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['main'].'/coz?s=1');
        $finishToken = (string) $crawler->filter('#student-test-finish input[name="_token"]')->attr('value');
        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/bitir', [
            '_token' => $finishToken,
            'confirm' => '1',
        ]);
        $client->followRedirect();
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Doğru cevap', $page);
        self::assertStringContainsString('Bir nedir? B', $page);
        self::assertStringContainsString('Iki nedir? A', $page);
        self::assertStringContainsString('Cozum metni', $page);
        self::assertStringContainsString('>Doğru<', $page);
        self::assertStringContainsString('>Yanlış<', $page);
        self::assertStringContainsString('>Boş<', $page);
        self::assertStringContainsString('1.00', $page);
        self::assertStringContainsString('6.00', $page);
        self::assertStringContainsString('16.6666', $page);
        self::assertStringNotContainsString('opt_', $page);
        self::assertStringNotContainsString($seed['hidden'], $page);
        self::assertStringNotContainsString('correctStableKey', $page);
        self::assertSame(1, $this->countRows('assessment_scoring_runs'));

        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/bitir', [
            '_token' => $finishToken,
            'confirm' => '1',
        ]);
        $client->followRedirect();
        self::assertSame(1, $this->countRows('assessment_scoring_runs'));

        $client->request('POST', '/ogrenci/testler/'.$seed['main'].'/cevap', [
            '_token' => $token,
            'position' => '1',
            'choice' => '1',
            'expected_version' => '1',
        ]);
        $client->followRedirect();
        $client->request('GET', '/ogrenci/testler/'.$seed['main'].'/sonuc');
        self::assertStringContainsString('Bir nedir? B', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['penalty']);
        $client->submit($crawler->filter('#student-test-start')->form());
        $client->followRedirect();
        self::assertStringContainsString('Bu test şu an çözülemiyor', (string) $client->getResponse()->getContent());
    }

    public function testServerClosesATimedAttemptAndRejectsForeignAnswers(): void
    {
        $seed = $this->seed('p61d', 60);
        $student = $this->createActive('practice-time@example.com', UserRole::Student);
        $this->completeOnboarding($student, GradeLevel::Grade1);
        $clock = new MockClock(new \DateTimeImmutable('2026-09-26 10:00:00', new \DateTimeZone('UTC')));
        Clock::set($clock);

        $client = static::createClient();
        $this->login($client, 'practice-time@example.com');
        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['timed']);
        $client->submit($crawler->filter('#student-test-start')->form());
        $client->followRedirect();
        self::assertStringContainsString('Süre bitişi', (string) $client->getResponse()->getContent());

        $clock->modify('+3 minutes');
        $client->request('GET', '/ogrenci/testler/'.$seed['timed'].'/coz');
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['timed'].'/sonuc');
        $client->followRedirect();
        self::assertStringContainsString('Boş', (string) $client->getResponse()->getContent());

        Clock::set(new NativeClock());
        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['main']);
        $client->submit($crawler->filter('#student-test-start')->form());
        $client->followRedirect();

        $owner = $this->freshUser('practice-time@example.com');
        $first = $this->attemptFor($owner, $seed['main']);
        $second = $this->attemptFor($owner, $seed['timed']);
        $foreign = $this->firstItem($second);
        $manager = static::getContainer()->get(AssessmentAttemptManager::class);
        self::assertInstanceOf(AssessmentAttemptManager::class, $manager);
        try {
            $manager->saveAnswer($first, $foreign, $owner, [
                'version' => 1,
                'answerType' => 'single_choice',
                'selectedStableKey' => 'opt_b',
            ], 0, 'practice_save');
            self::fail('An item from another attempt must be rejected.');
        } catch (AssessmentAttemptException $exception) {
            self::assertSame(AssessmentAttemptFailureReason::ItemNotFound, $exception->getReason());
        }

        $own = $this->firstItem($first);
        try {
            $manager->saveAnswer($first, $own, $owner, [
                'version' => 1,
                'answerType' => 'single_choice',
                'selectedStableKey' => 'opt_z',
            ], 0, 'practice_save');
            self::fail('An option from outside the sealed question must be rejected.');
        } catch (AssessmentAttemptException $exception) {
            self::assertSame(AssessmentAttemptFailureReason::AnswerInvalid, $exception->getReason());
        }
    }

    public function testHistoryAndAuthorizedReportStayInsideTheProvenScope(): void
    {
        $seed = $this->seed('p62');
        $owner = $this->createActive('practice-history@example.com', UserRole::Student);
        $this->completeOnboarding($owner, GradeLevel::Grade1);
        $other = $this->createActive('practice-history-other@example.com', UserRole::Student);
        $this->completeOnboarding($other, GradeLevel::Grade1);

        $client = static::createClient();
        $client->request('GET', '/ogrenci/testler/gecmisim');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar');
        self::assertResponseRedirects('/giris');

        $this->login($client, 'practice-history@example.com');
        $client->request('GET', '/ogrenci/testler/gecmisim');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Henüz tamamladığın', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['main']);
        $client->submit($crawler->filter('#student-test-start')->form());
        $client->followRedirect();
        $client->request('GET', '/ogrenci/testler/gecmisim');
        $open = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Devam ediyor', $open);
        self::assertStringContainsString('Çözüme dön', $open);
        self::assertStringNotContainsString('16.6666', $open);
        self::assertStringNotContainsString('Doğru cevap', $open);

        $this->answerAndFinish($client, $seed['main']);
        $client->request('GET', '/ogrenci/testler/gecmisim');
        $history = (string) $client->getResponse()->getContent();
        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('Sinif testi', $history);
        self::assertStringContainsString('Tamamlandı', $history);
        self::assertStringContainsString('16.6666', $history);
        self::assertStringContainsString('1.00', $history);
        self::assertStringContainsString('6.00', $history);
        self::assertStringContainsString('Sonucu görüntüle', $history);
        self::assertStringNotContainsString('Doğru cevap', $history);
        self::assertStringNotContainsString('practice-history@example.com', $history);
        self::assertStringNotContainsString($seed['hidden'], $history);
        self::assertStringNotContainsString('opt_', $history);
        self::assertStringNotContainsString('correctStableKey', $history);

        self::ensureKernelShutdown();
        $otherClient = static::createClient();
        $this->login($otherClient, 'practice-history-other@example.com');
        $otherClient->request('GET', '/ogrenci/testler/gecmisim');
        self::assertStringNotContainsString('16.6666', (string) $otherClient->getResponse()->getContent());
        $otherClient->request('GET', '/ogrenci/testler/'.$seed['main'].'/sonuc');
        self::assertResponseStatusCodeSame(404);

        self::ensureKernelShutdown();
        $admin = static::createClient();
        $this->login($admin, 'p62-ed@example.com');
        $admin->request('GET', '/yonetim/testler/'.$seed['hidden']);
        self::assertStringContainsString('Sonuçlar', (string) $admin->getResponse()->getContent());
        $admin->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar');
        $report = (string) $admin->getResponse()->getContent();
        $reportCache = (string) $admin->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $reportCache);
        self::assertStringContainsString('private', $reportCache);
        self::assertStringContainsString('Ayşe Yılmaz', $report);
        self::assertStringContainsString('16.6666', $report);
        self::assertStringContainsString('1.00', $report);
        self::assertStringNotContainsString('practice-history@example.com', $report);
        self::assertStringNotContainsString('Doğru cevap', $report);
        self::assertStringNotContainsString('opt_', $report);
        self::assertStringNotContainsString('correctStableKey', $report);
        $admin->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar?page=2');
        self::assertStringContainsString('Bu sayfada sonuç yok', (string) $admin->getResponse()->getContent());
        $admin->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar?q=Yokboyle');
        self::assertStringContainsString('Aramanla eşleşen sonuç yok', (string) $admin->getResponse()->getContent());
        $admin->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar/1');
        $detail = (string) $admin->getResponse()->getContent();
        self::assertStringContainsString('Doğru cevap', $detail);
        self::assertStringContainsString('Bir nedir? B', $detail);
        self::assertStringContainsString('Cozum metni', $detail);
        self::assertStringNotContainsString('practice-history@example.com', $detail);
        self::assertStringNotContainsString('opt_', $detail);
        $admin->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar/9');
        self::assertResponseStatusCodeSame(404);

        self::ensureKernelShutdown();
        $super = static::createClient();
        $this->login($super, 'p62-sa@example.com');
        $super->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ayşe Yılmaz', (string) $super->getResponse()->getContent());

        $this->createActive('practice-report-teacher@example.com', UserRole::Teacher);
        self::ensureKernelShutdown();
        $teacher = static::createClient();
        $this->login($teacher, 'practice-report-teacher@example.com');
        $teacher->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar');
        self::assertResponseStatusCodeSame(403);

        $this->createPrivileged('practice-report-mod@example.com', UserRole::Moderator);
        self::ensureKernelShutdown();
        $moderator = static::createClient();
        $this->login($moderator, 'practice-report-mod@example.com');
        $moderator->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar');
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $studentAdmin = static::createClient();
        $this->login($studentAdmin, 'practice-history@example.com');
        $studentAdmin->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar');
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $empty = static::createClient();
        $this->login($empty, 'p62-ed@example.com');
        $empty->request('GET', '/yonetim/testler/'.$seed['hidden'].'/sonuclar?q=');
        self::assertResponseIsSuccessful();
    }

    public function testMigrationDeclaresThePracticeBinding(): void
    {
        $path = \dirname(__DIR__, 2).'/migrations/Version20260926120000.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('assessment_platform_practices', $source);
        self::assertStringContainsString('uniq_platform_practice_user_assessment', $source);
        self::assertStringContainsString('FK_C126490DA76ED395', $source);
        self::assertStringContainsString('ON DELETE RESTRICT', $source);
    }

    /**
     * @return array{main: string, other: string, draft: string, archived: string, penalty: string, timed: string, hidden: string}
     */
    private function seed(string $prefix, ?int $timedSeconds = null): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var UserFactory $factory */
        $factory = $container->get(UserFactory::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $author = $factory->createAndPersist($prefix.'-sa@example.com', 'Guclu-Parola-123!', 'S', 'A', UserRole::Student);
        $author->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $author->transitionTo(UserStatus::Active);
        $author->addGlobalRole(UserRole::SuperAdmin);
        $users->save($author);
        $publisher = $factory->createAndPersist($prefix.'-ed@example.com', 'Guclu-Parola-123!', 'E', 'D', UserRole::Teacher);
        $publisher->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $publisher->transitionTo(UserStatus::Active);
        $publisher->addGlobalRole(UserRole::Admin);
        $users->save($publisher);

        /** @var SubjectManager $subjects */
        $subjects = $container->get(SubjectManager::class);
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
        /** @var AssessmentManager $assessments */
        $assessments = $container->get(AssessmentManager::class);

        $subject = $subjects->create($author, $prefix.'_s', 'Ders '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $author, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'create_p');
        $unit = $units->create($program, $author, $prefix.'_u', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $author, $prefix.'_t', 'T', 1, 'create_t');
        $outcome = $outcomes->create($topic, $author, $prefix.'_lo', 'Kazanim', 1, 'create_lo');
        $programs->publish($program, $author, 'publish_p');
        $grade2 = $programs->createDraft($subject, $author, GradeLevel::Grade2, $prefix.'_p2', 'P2', '1.0', 'create_p2');
        $unit2 = $units->create($grade2, $author, $prefix.'_u2', 'U2', 1, 'create_u2');
        $topic2 = $topics->createRoot($unit2, $author, $prefix.'_t2', 'T2', 1, 'create_t2');
        $outcome2 = $outcomes->create($topic2, $author, $prefix.'_lo2', 'Kazanim 2', 1, 'create_lo2');
        $programs->publish($grade2, $author, 'publish_p2');

        $first = $this->question($questions, $author, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Bir nedir?', null);
        $second = $this->question($questions, $author, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Iki nedir?', null);
        $third = $this->question($questions, $author, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Uc nedir?', 'Cozum metni');
        $otherQuestion = $this->question($questions, $author, $publisher, $subject, GradeLevel::Grade2, $outcome2, 'Diger nedir?', null);
        $penaltyQuestion = $this->question($questions, $author, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Ceza nedir?', null);

        $main = $this->publishAssessment($assessments, $author, $publisher, $subject, GradeLevel::Grade1, 'Sinif testi', 'Yönerge metni', null, [
            [$first, '1.00', '0.00'],
            [$second, '3.00', '0.00'],
            [$third, '2.00', '0.00'],
        ]);
        $other = $this->publishAssessment($assessments, $author, $publisher, $subject, GradeLevel::Grade2, 'Baska sinif testi', null, null, [
            [$otherQuestion, '1.00', '0.00'],
        ]);
        $draft = $this->publishAssessment($assessments, $author, $publisher, $subject, GradeLevel::Grade1, 'Taslak testi', null, null, [
            [$first, '1.00', '0.00'],
        ], false);
        $archived = $this->publishAssessment($assessments, $author, $publisher, $subject, GradeLevel::Grade1, 'Arsiv testi', null, null, [
            [$first, '1.00', '0.00'],
        ]);
        $assessments->archive($archived, $publisher, 'archive_obsolete');
        $penalty = $this->publishAssessment($assessments, $author, $publisher, $subject, GradeLevel::Grade1, 'Ceza testi', null, null, [
            [$penaltyQuestion, '1.00', '1.00'],
        ]);
        $timedQuestion = $this->question($questions, $author, $publisher, $subject, GradeLevel::Grade1, $outcome, 'Sure nedir?', null);
        $timed = $this->publishAssessment($assessments, $author, $publisher, $subject, GradeLevel::Grade1, 'Sureli testi', null, $timedSeconds ?? 60, [
            [$timedQuestion, '1.00', '0.00'],
        ]);

        $ids = [
            'main' => $main->getCode(),
            'other' => $other->getCode(),
            'draft' => $draft->getCode(),
            'archived' => $archived->getCode(),
            'penalty' => $penalty->getCode(),
            'timed' => $timed->getCode(),
            'hidden' => $main->getId()->toRfc4122(),
        ];
        self::ensureKernelShutdown();

        return $ids;
    }

    /**
     * @param list<array{0: Question, 1: string, 2: string}> $items
     */
    private function publishAssessment(
        AssessmentManager $assessments,
        User $author,
        User $publisher,
        Subject $subject,
        GradeLevel $grade,
        string $title,
        ?string $instructions,
        ?int $durationSeconds,
        array $items,
        bool $publish = true,
    ): Assessment {
        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $payload = [];
        $position = 1;
        foreach ($items as [$question, $points, $penalty]) {
            $revision = $revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
            self::assertInstanceOf(QuestionRevision::class, $revision);
            $payload[] = [
                'questionId' => $question->getId(),
                'questionRevisionId' => $revision->getId(),
                'position' => $position,
                'points' => $points,
                'penaltyPoints' => $penalty,
                'required' => true,
            ];
            ++$position;
        }
        $assessment = $assessments->createDraftAssessment(
            $author,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            $grade,
            $title,
            null,
            $instructions,
            $durationSeconds,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Manual,
            null,
            [[
                'title' => 'Bolum',
                'instructions' => null,
                'position' => 1,
                'durationSeconds' => null,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => $payload,
            ]],
            'create_'.substr(sha1($title.$grade->value), 0, 16),
            $subject,
        );
        if ($publish) {
            $assessments->submitForReview($assessment, $author, 'ready_for_review');
            $assessments->publish($assessment, $publisher, 'publish_approved');
        }

        return $assessment;
    }

    private function question(
        QuestionManager $questions,
        User $actor,
        User $publisher,
        Subject $subject,
        GradeLevel $grade,
        CurriculumLearningOutcome $outcome,
        string $stem,
        ?string $explanation,
    ): Question {
        $question = $questions->createDraftQuestion(
            $actor,
            QuestionScope::Platform,
            null,
            $subject,
            $grade,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph($stem),
            null === $explanation ? null : QuestionContentDocument::paragraph($explanation)->toArray(),
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph($stem.' A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph($stem.' B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.substr(sha1($stem.$grade->value), 0, 12),
        );
        $questions->submitForReview($question, $actor, 'ready_for_review');
        $questions->publish($question, $publisher, 'publish_approved');

        return $question;
    }

    private function createActive(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ayşe', 'Yılmaz', $role);
        $lifecycle->markEmailVerifiedAndActivate($user);
        self::ensureKernelShutdown();

        return $user;
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

    private function completeOnboarding(User $user, GradeLevel $grade): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $fresh = $this->freshUser($user->getEmail());
        /** @var StudentProfileManager $manager */
        $manager = static::getContainer()->get(StudentProfileManager::class);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $grade;
        $manager->completeOnboarding($fresh, $dto);
        self::ensureKernelShutdown();
    }

    private function answerAndFinish(KernelBrowser $client, string $code): void
    {
        $crawler = $client->request('GET', '/ogrenci/testler/'.$code.'/coz?s=1');
        $client->request('POST', '/ogrenci/testler/'.$code.'/cevap', [
            '_token' => (string) $crawler->filter('#student-test-answer input[name="_token"]')->attr('value'),
            'position' => '1',
            'choice' => '2',
            'expected_version' => (string) $crawler->filter('input[name="expected_version"]')->attr('value'),
        ]);
        $client->followRedirect();
        $crawler = $client->request('GET', '/ogrenci/testler/'.$code.'/coz?s=2');
        $client->request('POST', '/ogrenci/testler/'.$code.'/cevap', [
            '_token' => (string) $crawler->filter('#student-test-answer input[name="_token"]')->attr('value'),
            'position' => '2',
            'choice' => '1',
            'expected_version' => (string) $crawler->filter('input[name="expected_version"]')->attr('value'),
        ]);
        $client->followRedirect();
        $crawler = $client->request('GET', '/ogrenci/testler/'.$code.'/coz?s=1');
        $client->request('POST', '/ogrenci/testler/'.$code.'/bitir', [
            '_token' => (string) $crawler->filter('#student-test-finish input[name="_token"]')->attr('value'),
            'confirm' => '1',
        ]);
        $client->followRedirect();
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

    private function freshUser(string $email): User
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function attemptFor(User $student, string $code): \App\Entity\AssessmentAttempt
    {
        /** @var AssessmentRepository $assessments */
        $assessments = static::getContainer()->get(AssessmentRepository::class);
        $assessment = $assessments->findOneBy(['code' => $code]);
        self::assertInstanceOf(Assessment::class, $assessment);
        /** @var AssessmentPlatformPracticeRepository $practices */
        $practices = static::getContainer()->get(AssessmentPlatformPracticeRepository::class);
        $practice = $practices->findForUserAndAssessment($student, $assessment);
        self::assertNotNull($practice);
        /** @var AssessmentAttemptRepository $attempts */
        $attempts = static::getContainer()->get(AssessmentAttemptRepository::class);
        $attempt = $attempts->findOwnedForDelivery($practice->getDelivery()->getId(), $student->getId());
        self::assertNotNull($attempt);

        return $attempt;
    }

    private function firstItem(\App\Entity\AssessmentAttempt $attempt): AssessmentAttemptItem
    {
        /** @var AssessmentAttemptItemRepository $items */
        $items = static::getContainer()->get(AssessmentAttemptItemRepository::class);
        $rows = $items->findItemsForAttemptOrdered($attempt->getId());
        self::assertNotEmpty($rows);

        return $rows[0];
    }

    private function countRows(string $table): int
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function purge(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            QuestionBankDbCleanup::deleteTables($connection, [
                'curriculum_learning_outcomes',
                'curriculum_topics',
                'curriculum_units',
                'curriculum_programs',
                'subjects',
                'security_audit_events',
                'users',
            ]);
            if ($connection->createSchemaManager()->tablesExist(['institutions'])) {
                $connection->executeStatement("DELETE FROM institutions WHERE slug = 'bireysel-deneme'");
            }
        } catch (\Throwable) {
        }
        self::ensureKernelShutdown();
    }
}
