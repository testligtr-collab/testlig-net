<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\Assessment;
use App\Entity\AssessmentAttempt;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\LearningContent;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessPackageTargetType;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResourceAccessClass;
use App\Enum\ResultReleasePolicy;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\StudentPracticeException;
use App\LearningContent\Content\LearningContentDocument;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentAttemptRepository;
use App\Repository\AssessmentPlatformPracticeRepository;
use App\Repository\AssessmentRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\AccessLicenseManager;
use App\Service\AccessPackageManager;
use App\Service\AccessPackageVersionManager;
use App\Service\AssessmentManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\QuestionManager;
use App\Service\StudentAssessmentPractice;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class StudentAssessmentEntitlementGateTest extends WebTestCase
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

    public function testMissingPolicyHidesDiscoveryAndBlocksNewStartWithoutArtifacts(): void
    {
        $seed = $this->seedPublished('egmiss', null);
        $this->onboardStudent('egmiss-stu@example.com', GradeLevel::Grade1);
        $beforeDeliveries = $this->countRows('assessment_deliveries');
        $beforePractices = $this->countRows('assessment_platform_practices');
        $beforeAttempts = $this->countRows('assessment_attempts');
        $beforeInstitutions = $this->countRows('institutions');
        $beforeMemberships = $this->countRows('institution_memberships');

        $client = static::createClient();
        $this->login($client, 'egmiss-stu@example.com');
        $client->request('GET', '/ogrenci/testler');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Gate testi', (string) $client->getResponse()->getContent());

        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseStatusCodeSame(404);

        self::ensureKernelShutdown();
        self::bootKernel();
        $practice = static::getContainer()->get(StudentAssessmentPractice::class);
        self::assertInstanceOf(StudentAssessmentPractice::class, $practice);
        try {
            $practice->start($this->freshUser('egmiss-stu@example.com'), GradeLevel::Grade1, $seed['code']);
            self::fail('Expected entitlement deny before provision');
        } catch (StudentPracticeException $exception) {
            self::assertSame('not_found', $exception->getReason());
        }
        self::assertSame($beforeDeliveries, $this->countRows('assessment_deliveries'));
        self::assertSame($beforePractices, $this->countRows('assessment_platform_practices'));
        self::assertSame($beforeAttempts, $this->countRows('assessment_attempts'));
        self::assertSame($beforeInstitutions, $this->countRows('institutions'));
        self::assertSame($beforeMemberships, $this->countRows('institution_memberships'));
    }

    public function testFreePolicyAllowsDiscoveryAndStart(): void
    {
        $seed = $this->seedPublished('egfree', ResourceAccessClass::Free);
        $this->onboardStudent('egfree-stu@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'egfree-stu@example.com');
        $client->request('GET', '/ogrenci/testler');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Gate testi', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action$="/baslat"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/baslat', ['_token' => $token]);
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['code'].'/coz');
        self::assertGreaterThan(0, $this->countRows('assessment_attempts'));
    }

    public function testRequiredPolicyNeedsCoveringLicense(): void
    {
        $seed = $this->seedPublished('egreq', ResourceAccessClass::EntitlementRequired);
        $this->onboardStudent('egreq-stu@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'egreq-stu@example.com');
        $client->request('GET', '/ogrenci/testler');
        self::assertStringNotContainsString('Gate testi', (string) $client->getResponse()->getContent());
        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseStatusCodeSame(404);

        $this->grantAssessmentLicense($seed['id'], 'egreq-stu@example.com', '+30 days', 'egreq_ok');
        $client = static::createClient();
        $this->login($client, 'egreq-stu@example.com');
        $client->request('GET', '/ogrenci/testler');
        self::assertStringContainsString('Gate testi', (string) $client->getResponse()->getContent());
        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action$="/baslat"] input[name="_token"]')->attr('value');
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/baslat', ['_token' => $token]);
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['code'].'/coz');
    }

    public function testExpiredOrUnrelatedLicenseDoesNotOpenAssessment(): void
    {
        $seed = $this->seedPublished('egbad', ResourceAccessClass::EntitlementRequired);
        $this->onboardStudent('egbad-stu@example.com', GradeLevel::Grade1);
        $this->grantAssessmentLicense($seed['id'], 'egbad-stu@example.com', '-1 day', 'egbad_exp', startsAt: '-10 days');
        $client = static::createClient();
        $this->login($client, 'egbad-stu@example.com');
        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseStatusCodeSame(404);

        $other = $this->seedPublished('egbad2', ResourceAccessClass::EntitlementRequired);
        $this->grantAssessmentLicense($other['id'], 'egbad-stu@example.com', '+30 days', 'egbad_other');
        $client = static::createClient();
        $this->login($client, 'egbad-stu@example.com');
        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/ogrenci/testler/'.$other['code']);
        self::assertResponseIsSuccessful();
    }

    public function testInProgressSurvivesPolicyChangeToRequired(): void
    {
        $seed = $this->seedPublished('eginp', ResourceAccessClass::Free);
        $this->onboardStudent('eginp-stu@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'eginp-stu@example.com');
        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        $token = $crawler->filter('form[action$="/baslat"] input[name="_token"]')->attr('value');
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/baslat', ['_token' => $token]);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->setPolicy($seed['id'], ResourceAccessClass::EntitlementRequired, 'flip_required');
        $client->request('GET', '/ogrenci/testler/'.$seed['code'].'/coz');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/ogrenci');
        self::assertSelectorExists('section[aria-labelledby="continue-heading"] a[href="/ogrenci/testler/'.$seed['code'].'/coz"]');

        // After flip to required without a license, discovery hides the test but B continue remains.
        $client->request('GET', '/ogrenci/testler');
        self::assertStringNotContainsString('Gate testi', (string) $client->getResponse()->getContent());
        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/coz"]');
    }

    public function testInProgressSurvivesLicenseExpiryWhileAttemptWindowOpen(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC')));
        Clock::set($clock);
        $seed = $this->seedPublished('eglic', ResourceAccessClass::EntitlementRequired, durationSeconds: 3600);
        $this->onboardStudent('eglic-stu@example.com', GradeLevel::Grade1);
        $this->grantAssessmentLicense(
            $seed['id'],
            'eglic-stu@example.com',
            $clock->now()->modify('+30 minutes'),
            'eglic_ok',
            $clock->now()->modify('-1 day'),
        );

        $client = static::createClient();
        $this->login($client, 'eglic-stu@example.com');
        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action$="/baslat"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/baslat', ['_token' => $token]);
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['code'].'/coz');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $attemptId = $this->attemptIdFor('eglic-stu@example.com', $seed['code']);
        $beforeDeliveries = $this->countRows('assessment_deliveries');
        $beforeAttempts = $this->countRows('assessment_attempts');

        // License ends; attempt window (1h) is still open.
        $clock->modify('+45 minutes');
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->login($client, 'eglic-stu@example.com');

        $client->request('GET', '/ogrenci/testler');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Gate testi', (string) $client->getResponse()->getContent());

        $client->request('GET', '/ogrenci');
        self::assertSelectorExists('section[aria-labelledby="continue-heading"] a[href="/ogrenci/testler/'.$seed['code'].'/coz"]');

        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/coz"]');
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/baslat', [
            '_token' => $this->studentCsrf($client),
        ]);
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['code'].'/coz');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame($attemptId, $this->attemptIdFor('eglic-stu@example.com', $seed['code']));

        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['code'].'/coz?s=1');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/cevap', [
            '_token' => (string) $crawler->filter('#student-test-answer input[name="_token"]')->attr('value'),
            'position' => '1',
            'choice' => '2',
            'expected_version' => (string) $crawler->filter('input[name="expected_version"]')->attr('value'),
        ]);
        $client->followRedirect();
        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['code'].'/coz?s=1');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/bitir', [
            '_token' => (string) $crawler->filter('#student-test-finish input[name="_token"]')->attr('value'),
            'confirm' => '1',
        ]);
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['code'].'/sonuc');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        self::assertSame($attemptId, $this->attemptIdFor('eglic-stu@example.com', $seed['code']));
        self::assertSame($beforeDeliveries, $this->countRows('assessment_deliveries'));
        self::assertSame($beforeAttempts, $this->countRows('assessment_attempts'));
        Clock::set(new NativeClock());
    }

    public function testExpiredAttemptIsNotContinuableWithoutEntitlement(): void
    {
        $seed = $this->seedPublished('egexp', ResourceAccessClass::Free, durationSeconds: 60);
        $this->onboardStudent('egexp-stu@example.com', GradeLevel::Grade1);
        $clock = new MockClock(new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('UTC')));
        Clock::set($clock);
        $client = static::createClient();
        $this->login($client, 'egexp-stu@example.com');
        $practice = static::getContainer()->get(StudentAssessmentPractice::class);
        self::assertInstanceOf(StudentAssessmentPractice::class, $practice);
        $practice->start($this->freshUser('egexp-stu@example.com'), GradeLevel::Grade1, $seed['code']);
        $attemptId = $this->attemptIdFor('egexp-stu@example.com', $seed['code']);
        $beforeAttempts = $this->countRows('assessment_attempts');
        $this->setPolicy($seed['id'], ResourceAccessClass::EntitlementRequired, 'flip_after_start');
        $clock->modify('+2 minutes');

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->login($client, 'egexp-stu@example.com');

        $client->request('GET', '/ogrenci');
        self::assertSelectorNotExists('section[aria-labelledby="continue-heading"] a[href="/ogrenci/testler/'.$seed['code'].'/coz"]');
        $client->request('GET', '/ogrenci/testler');
        self::assertStringNotContainsString('Gate testi', (string) $client->getResponse()->getContent());

        // Own expired attempt may open detail, but solve finalizes — no continued answering session.
        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/ogrenci/testler/'.$seed['code'].'/coz');
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['code'].'/sonuc');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/baslat', [
            '_token' => $this->studentCsrf($client),
        ]);
        self::assertResponseRedirects('/ogrenci/testler/'.$seed['code'].'/sonuc');
        self::assertSame($attemptId, $this->attemptIdFor('egexp-stu@example.com', $seed['code']));
        self::assertSame($beforeAttempts, $this->countRows('assessment_attempts'));
        $attempt = $this->attemptFor('egexp-stu@example.com', $seed['code']);
        self::assertNotSame(AssessmentAttemptStatus::InProgress, $attempt->getStatus());
        Clock::set(new NativeClock());
    }

    public function testCompletedResultAndHistoryRemainWithoutCurrentEntitlement(): void
    {
        $seed = $this->seedPublished('egdone', ResourceAccessClass::Free);
        $this->onboardStudent('egdone-stu@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'egdone-stu@example.com');
        $crawler = $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        $token = $crawler->filter('form[action$="/baslat"] input[name="_token"]')->attr('value');
        $client->request('POST', '/ogrenci/testler/'.$seed['code'].'/baslat', ['_token' => $token]);
        $client->followRedirect();
        $this->answerAndFinish($client, $seed['code']);
        $this->setPolicy($seed['id'], ResourceAccessClass::EntitlementRequired, 'flip_after_done');

        $client->request('GET', '/ogrenci/testler');
        self::assertStringNotContainsString('Gate testi', (string) $client->getResponse()->getContent());
        $client->request('GET', '/ogrenci/testler/'.$seed['code'].'/sonuc');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/ogrenci/testler/gecmisim');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Gate testi', (string) $client->getResponse()->getContent());
    }

    public function testForeignAttemptAndLearningContentFreeDoNotOpenAssessment(): void
    {
        $seed = $this->seedPublished('egidor', ResourceAccessClass::Free);
        $this->onboardStudent('egidor-a@example.com', GradeLevel::Grade1);
        $this->onboardStudent('egidor-b@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'egidor-a@example.com');
        $practice = static::getContainer()->get(StudentAssessmentPractice::class);
        self::assertInstanceOf(StudentAssessmentPractice::class, $practice);
        $practice->start($this->freshUser('egidor-a@example.com'), GradeLevel::Grade1, $seed['code']);
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'egidor-b@example.com');
        $client->request('GET', '/ogrenci/testler/'.$seed['code'].'/coz');
        self::assertResponseStatusCodeSame(404);

        $this->setPolicy($seed['id'], ResourceAccessClass::EntitlementRequired, 'flip_idor');
        $this->seedLearningContentFree('egidor');
        $client = static::createClient();
        $this->login($client, 'egidor-b@example.com');
        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseStatusCodeSame(404);
        $before = $this->countRows('assessment_attempts');
        self::ensureKernelShutdown();
        self::bootKernel();
        $practice = static::getContainer()->get(StudentAssessmentPractice::class);
        self::assertInstanceOf(StudentAssessmentPractice::class, $practice);
        try {
            $practice->start($this->freshUser('egidor-b@example.com'), GradeLevel::Grade1, $seed['code']);
            self::fail('LC free must not open assessment');
        } catch (StudentPracticeException $exception) {
            self::assertSame('not_found', $exception->getReason());
        }
        self::assertSame($before, $this->countRows('assessment_attempts'));
    }

    public function testPlatformDenyDoesNotFallThroughToInstitutionAssignmentPath(): void
    {
        $seed = $this->seedPublished('egfb', ResourceAccessClass::EntitlementRequired);
        $this->onboardStudent('egfb-stu@example.com', GradeLevel::Grade1);
        $client = static::createClient();
        $this->login($client, 'egfb-stu@example.com');
        // Platform code matches grade; entitlement deny must stay platform-scoped 404 (not assigned detail).
        $client->request('GET', '/ogrenci/testler/'.$seed['code']);
        self::assertResponseStatusCodeSame(404);
        // Opaque platform deny must not render the assigned-test detail chrome.
        self::assertSelectorNotExists('dt');
        self::assertSelectorNotExists('form[action$="/baslat"]');
    }

    /**
     * @return array{code: string, id: string}
     */
    private function seedPublished(string $prefix, ?ResourceAccessClass $policy, ?int $durationSeconds = null): array
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
        $publisher = $factory->createAndPersist($prefix.'-pub@example.com', 'Guclu-Parola-123!', 'P', 'U', UserRole::Teacher);
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

        $subject = $subjects->create($sa, $prefix.'_s', 'Ders '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'create_p');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'create_t');
        $outcome = $outcomes->create($topic, $sa, $prefix.'_lo', 'Kazanim', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');

        $question = $questions->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Soru '.$prefix.'?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.$prefix,
        );
        $questions->submitForReview($question, $sa, 'ready_for_review');
        $questions->publish($question, $publisher, 'publish_approved');
        /** @var QuestionRevisionRepository $revisions */
        $revisions = $container->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        self::assertInstanceOf(QuestionRevision::class, $revision);

        $assessment = $assessments->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade1,
            'Gate testi '.$prefix,
            null,
            null,
            $durationSeconds,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Manual,
            null,
            [[
                'title' => 'Bölüm',
                'position' => 1,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $question->getId(),
                    'questionRevisionId' => $revision->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'create_a_'.$prefix,
            $subject,
        );
        $assessments->submitForReview($assessment, $sa, 'ready_for_review');
        $assessments->publish($assessment, $publisher, 'publish_approved');
        if ($policy instanceof ResourceAccessClass) {
            /** @var AccessPackageManager $packages */
            $packages = $container->get(AccessPackageManager::class);
            $packages->setAssessmentAccessPolicy($assessment, $publisher, $policy, 'seed_policy');
        }
        $ids = ['code' => $assessment->getCode(), 'id' => $assessment->getId()->toRfc4122()];
        self::ensureKernelShutdown();

        return $ids;
    }

    private function seedLearningContentFree(string $prefix): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $sa = $users->findOneByNormalizedEmail(mb_strtolower($prefix.'-sa@example.com'));
        $publisher = $users->findOneByNormalizedEmail(mb_strtolower($prefix.'-pub@example.com'));
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(User::class, $publisher);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $subject = $em->getRepository(Subject::class)->findOneBy(['code' => $prefix.'_s']);
        self::assertInstanceOf(Subject::class, $subject);
        $outcome = $em->getRepository(CurriculumLearningOutcome::class)->findOneBy(['code' => $prefix.'_lo']);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $outcome);
        /** @var LearningContentManager $contents */
        $contents = $container->get(LearningContentManager::class);
        /** @var AccessPackageManager $packages */
        $packages = $container->get(AccessPackageManager::class);
        $content = $contents->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            $prefix.'_lc',
            'LC free '.$prefix,
            null,
            LearningContentDocument::paragraph('Body'),
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            'create_lc',
        );
        $contents->submitForReview($content, $sa, 'ready');
        $contents->publish($content, $publisher, 'publish_lc');
        $packages->setLearningContentAccessPolicy($content, $publisher, ResourceAccessClass::Free, 'lc_free');
        self::assertInstanceOf(LearningContent::class, $content);
        self::ensureKernelShutdown();
    }

    private function grantAssessmentLicense(
        string $assessmentId,
        string $studentEmail,
        string|\DateTimeImmutable $endsAt,
        string $suffix,
        string|\DateTimeImmutable $startsAt = '-1 day',
    ): void {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var AssessmentRepository $assessments */
        $assessments = $container->get(AssessmentRepository::class);
        $assessment = $assessments->findOneById(Uuid::fromString($assessmentId));
        self::assertInstanceOf(Assessment::class, $assessment);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $student = $users->findOneByNormalizedEmail(mb_strtolower($studentEmail));
        $sa = null;
        foreach ($users->findAll() as $candidate) {
            if (\in_array('ROLE_SUPER_ADMIN', $candidate->getRoles(), true)) {
                $sa = $candidate;
                break;
            }
        }
        self::assertInstanceOf(User::class, $student);
        self::assertInstanceOf(User::class, $sa);
        /** @var AccessPackageManager $packages */
        $packages = $container->get(AccessPackageManager::class);
        /** @var AccessPackageVersionManager $versions */
        $versions = $container->get(AccessPackageVersionManager::class);
        /** @var AccessLicenseManager $licenses */
        $licenses = $container->get(AccessLicenseManager::class);
        $package = $packages->create($sa, $suffix.'_pkg', 'Pkg '.$suffix, null, AccessPackageTargetType::Individual, 30, null, 'create_pkg');
        $version = $versions->createDraftVersion($package, $sa, 30, null, 'create_v');
        $versions->addAssessmentGrant($version, $assessment, $sa, 'add_grant');
        $version = $versions->activate($version, $sa, 'activate_v');
        $validFrom = $startsAt instanceof \DateTimeImmutable ? $startsAt : new \DateTimeImmutable($startsAt);
        $validUntil = $endsAt instanceof \DateTimeImmutable ? $endsAt : new \DateTimeImmutable($endsAt);
        $license = $licenses->createUserLicense(
            $version,
            $student,
            $sa,
            AccessLicenseSourceType::Manual,
            $validFrom,
            $validUntil,
            'lic_'.$suffix,
        );
        $licenses->activate($license, $sa, 'activate_lic');
        self::ensureKernelShutdown();
    }

    private function attemptFor(string $email, string $code): AssessmentAttempt
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        $student = $this->freshUser($email);
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
        self::assertInstanceOf(AssessmentAttempt::class, $attempt);

        return $attempt;
    }

    private function attemptIdFor(string $email, string $code): string
    {
        return $this->attemptFor($email, $code)->getId()->toRfc4122();
    }

    private function studentCsrf(KernelBrowser $client): string
    {
        $request = $client->getRequest();
        $session = $request->getSession();
        /** @var RequestStack $stack */
        $stack = $client->getContainer()->get('request_stack');
        $stack->push($request);
        try {
            /** @var CsrfTokenManagerInterface $tokens */
            $tokens = $client->getContainer()->get('security.csrf.token_manager');
            $value = $tokens->getToken('student_test')->getValue();
            $session->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }

    private function setPolicy(string $assessmentId, ResourceAccessClass $class, string $reason): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var AssessmentRepository $assessments */
        $assessments = $container->get(AssessmentRepository::class);
        $assessment = $assessments->findOneById(Uuid::fromString($assessmentId));
        self::assertInstanceOf(Assessment::class, $assessment);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $actor = null;
        foreach ($users->findAll() as $candidate) {
            if (\in_array('ROLE_ADMIN', $candidate->getRoles(), true) || \in_array('ROLE_SUPER_ADMIN', $candidate->getRoles(), true)) {
                $actor = $candidate;
                break;
            }
        }
        self::assertInstanceOf(User::class, $actor);
        /** @var AccessPackageManager $packages */
        $packages = $container->get(AccessPackageManager::class);
        $packages->setAssessmentAccessPolicy($assessment, $actor, $class, $reason);
        self::ensureKernelShutdown();
    }

    private function onboardStudent(string $email, GradeLevel $grade): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'O', 'S', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);
        /** @var StudentProfileManager $profiles */
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $grade;
        $profiles->completeOnboarding($user, $dto);
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
        $user = $users->findOneByNormalizedEmail(mb_strtolower($email));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function countRows(string $table): int
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $count = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::ensureKernelShutdown();

        return $count;
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
