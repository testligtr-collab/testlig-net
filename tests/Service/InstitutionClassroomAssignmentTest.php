<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\StudentProfileRequest;
use App\Entity\Assessment;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentScoringRun;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\SecurityAuditEvent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionType;
use App\Enum\ResourceAccessClass;
use App\Enum\ResultReleasePolicy;
use App\Enum\ResultReleaseStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InstitutionTestAssignmentException;
use App\Exception\StudentPracticeException;
use App\Repository\AssessmentAttemptRepository;
use App\Repository\AssessmentPublicationRepository;
use App\Repository\QuestionRevisionRepository;
use App\Service\AccessPackageManager;
use App\Service\AssessmentResultReleaseManager;
use App\Service\InstitutionClassroomTestAssigner;
use App\Service\InstitutionDeliveryReport;
use App\Service\InvitationCodeDigestHasher;
use App\Service\StudentAssessmentPractice;
use App\Service\StudentAssignedTestCatalog;
use App\Service\StudentProfileManager;
use App\Service\StudentTestHistoryQuery;
use App\Tests\Support\AssessmentDeliveryTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class InstitutionClassroomAssignmentTest extends WebTestCase
{
    use AssessmentDeliveryTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testOwnerAssignsAndStudentSolvesOnce(): void
    {
        $ctx = $this->institutionClass('solve');
        $refs = $this->references($ctx['assessment'], $ctx['classroom']);
        $deliveryReference = $this->assigner()->createDraft(
            $ctx['owner'],
            $ctx['institution'],
            $refs['assessment'],
            $refs['classroom'],
            null,
            null,
            'Kalem getirin',
        );
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $deliveryReference);

        $report = $this->report()->result($ctx['owner'], $this->requireDelivery($ctx['institution'], $deliveryReference), 1);
        self::assertNotNull($report);
        self::assertSame(1, $report->recipientCount);
        self::assertSame(1, $report->notStarted);
        self::assertSame('%0', $report->completionRate);

        $student = $this->fresh($ctx['student']);
        $cards = $this->catalog()->listFor($student);
        self::assertCount(1, $cards);
        self::assertSame('Başlayabilir', $cards[0]['status_label']);
        self::assertSame($ctx['institution']->getName(), $cards[0]['institution']);
        $code = $cards[0]['code'];
        self::assertSame(32, \strlen($code));
        self::assertNotSame(str_replace('-', '', $this->requireDelivery($ctx['institution'], $deliveryReference)->getId()->toRfc4122()), $code);

        $practice = $this->practice();
        $practice->start($student, GradeLevel::Grade9, $code);
        $continue = $practice->continueCard($student, GradeLevel::Grade9);
        self::assertNotNull($continue);
        self::assertSame($code, $continue->code);
        self::assertSame('Devam ediyor', $continue->stateLabel);
        self::assertSame($ctx['institution']->getName(), $continue->institutionName);
        $encodedContinue = json_encode($continue, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('correctStableKey', $encodedContinue);
        self::assertStringNotContainsString('ciphertext', $encodedContinue);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $encodedContinue);
        $view = $practice->solve($student, GradeLevel::Grade9, $code, 1);
        $encoded = json_encode($view, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('correctStableKey', $encoded);
        self::assertStringNotContainsString('ciphertext', $encoded);
        self::assertStringNotContainsString('nonce', $encoded);

        $practice->saveChoice($student, GradeLevel::Grade9, $code, 1, 2, 0);
        $practice->finish($student, GradeLevel::Grade9, $code);
        $result = $practice->result($student, GradeLevel::Grade9, $code);
        self::assertIsArray($result);
        self::assertNull($practice->continueCard($student, GradeLevel::Grade9));
        $again = $practice->start($student, GradeLevel::Grade9, $code);
        self::assertSame(1, $this->attemptCount($again->getDelivery()->getId()));

        $history = $this->history()->listFor($student);
        self::assertNotSame([], $history);
        self::assertSame($ctx['institution']->getName(), $history[0]->institutionName);

        $scored = $this->report()->result($ctx['owner'], $this->requireDelivery($ctx['institution'], $deliveryReference), 1);
        self::assertNotNull($scored);
        self::assertSame(1, $scored->completed);
        self::assertSame('%100', $scored->completionRate);
        self::assertSame('%100', $scored->averagePercentage);
        self::assertSame('2,5', $scored->students[0]->earned);
        self::assertStringNotContainsString('@', $scored->students[0]->name);

        $other = $this->activeUser('solve-other@example.com', UserRole::Student);
        self::assertNull($this->catalog()->deliveryFor($other, $code));
        try {
            $practice->detail($other, GradeLevel::Grade9, $code);
            self::fail('Foreign student must not open the delivery.');
        } catch (StudentPracticeException $exception) {
            self::assertSame('not_found', $exception->getReason());
        }

        $audit = $this->latestAudit(SecurityAuditAction::AssessmentDeliveryCreated);
        self::assertSame(1, $audit->getMetadata()['max_attempts'] ?? null);
        $auditJson = json_encode($audit->getMetadata(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('@', $auditJson);
        self::assertStringNotContainsString('Kalem', $auditJson);
    }

    public function testAssignmentRulesAndRoles(): void
    {
        $ctx = $this->institutionClass('rules');
        $refs = $this->references($ctx['assessment'], $ctx['classroom']);
        $assigner = $this->assigner();

        $manager = $this->activeUser('rules-manager@example.com');
        $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $manager, InstitutionMembershipRole::Manager, 'add_manager');
        $managerDraft = $assigner->createDraft($manager, $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null);
        $assigner->close($ctx['owner'], $ctx['institution'], $managerDraft);

        $teacherDraft = $assigner->createDraft($ctx['teacher'], $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null);
        $otherClass = $this->classroomManager()->create(
            $ctx['classroom']->getAcademicYear(),
            $ctx['owner'],
            'rules other',
            GradeLevel::Grade9,
            'cls2',
            'B',
            40,
        );
        $otherRef = $this->hasher()->workspaceReference('classroom', $otherClass->getId());
        $this->expectReason(static fn () => $assigner->createDraft($ctx['teacher'], $ctx['institution'], $refs['assessment'], $otherRef, null, null, null), 'forbidden');

        $staff = $this->activeUser('rules-staff@example.com');
        $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $staff, InstitutionMembershipRole::Staff, 'add_staff');
        $this->expectReason(static fn () => $assigner->createDraft($staff, $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null), 'forbidden');
        $admin = $this->activeUser('rules-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        foreach ([
            [$ctx['student'], 'student'],
            [$ctx['sa'], 'sa'],
            [$this->activeUser('rules-parent@example.com', UserRole::Parent), 'parent'],
            [$this->activeUser('rules-mod@example.com', UserRole::Moderator), 'mod'],
            [$admin, 'admin'],
            [$this->activeUser('rules-role-teacher@example.com', UserRole::Teacher), 'global-teacher'],
        ] as [$actor, $label]) {
            $this->expectReason(
                static fn () => $assigner->createDraft($actor, $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null),
                'forbidden',
                $label,
            );
        }

        $this->expectReason(
            static fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $ctx['platform']->getCode(), $refs['classroom'], null, null, null),
            'not_found',
        );
        $foreign = $this->institutionClass('foreign');
        $foreignRef = $this->hasher()->workspaceReference('assessment', $foreign['assessment']->getId());
        $this->expectReason(
            static fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $foreignRef, $refs['classroom'], null, null, null),
            'not_found',
        );

        $owned = [
            'owner' => $ctx['owner'],
            'sa' => $ctx['sa'],
            'institution' => $ctx['institution'],
        ];
        $gradeMismatch = $this->publishInstitutionAssessment($owned, 'mismatch', GradeLevel::Grade10, '0.00');
        $this->expectReason(
            fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $this->hasher()->workspaceReference('assessment', $gradeMismatch->getId()), $refs['classroom'], null, null, null),
            'not_assignable',
        );
        $penalized = $this->publishInstitutionAssessment($owned, 'penalty', GradeLevel::Grade9, '0.50');
        $this->expectReason(
            fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $this->hasher()->workspaceReference('assessment', $penalized->getId()), $refs['classroom'], null, null, null),
            'not_assignable',
        );
        $draft = $this->draftInstitutionAssessment($ctx, 'draft');
        $this->expectReason(
            fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $this->hasher()->workspaceReference('assessment', $draft->getId()), $refs['classroom'], null, null, null),
            'not_assignable',
        );
        $archived = $this->publishInstitutionAssessment($owned, 'archived', GradeLevel::Grade9, '0.00');
        $this->assessments()->archive($archived, $ctx['sa'], 'archive_a');
        $archived = $this->em->find(Assessment::class, $archived->getId());
        self::assertInstanceOf(Assessment::class, $archived);
        $this->expectReason(
            fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $this->hasher()->workspaceReference('assessment', $archived->getId()), $refs['classroom'], null, null, null),
            'not_assignable',
        );

        $this->expectReason(
            static fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $refs['assessment'], $refs['classroom'], '2026-09-28T18:00', '2026-09-28T09:00', null),
            'window',
        );
        $this->expectReason(
            static fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $refs['assessment'], $refs['classroom'], 'not-a-date', null, null),
            'window',
        );
        $this->expectReason(
            static fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null),
            'overlap',
        );

        $emptyRoom = $this->classroomManager()->create(
            $ctx['classroom']->getAcademicYear(),
            $ctx['owner'],
            'empty room',
            GradeLevel::Grade9,
            'empty',
            'C',
            20,
        );
        $this->expectReason(
            fn () => $assigner->createDraft(
                $ctx['owner'],
                $ctx['institution'],
                $refs['assessment'],
                $this->hasher()->workspaceReference('classroom', $emptyRoom->getId()),
                null,
                null,
                null,
            ),
            'empty_class',
        );

        $this->subjects()->archive($ctx['subject'], $ctx['sa'], 'archive_subject');
        $this->expectReason(
            static fn () => $assigner->createDraft($ctx['owner'], $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null),
            'not_assignable',
        );

        unset($teacherDraft);
    }

    public function testUnsupportedTypeEmptyItemsInactiveClassAndClosedYear(): void
    {
        $ctx = $this->institutionClass('shape');
        $assigner = $this->assigner();
        $typed = $this->publishInstitutionAssessment([
            'owner' => $ctx['owner'],
            'sa' => $ctx['sa'],
            'institution' => $ctx['institution'],
        ], 'typed', GradeLevel::Grade9, '0.00', 3600, QuestionType::MultipleChoice);
        $this->expectReason(
            fn () => $assigner->createDraft(
                $ctx['owner'],
                $ctx['institution'],
                $this->hasher()->workspaceReference('assessment', $typed->getId()),
                $this->hasher()->workspaceReference('classroom', $ctx['classroom']->getId()),
                null,
                null,
                null,
            ),
            'not_assignable',
        );

        $rejected = false;
        try {
            $this->assessments()->createDraftAssessment(
                $ctx['owner'],
                AssessmentScope::Institution,
                $ctx['institution'],
                AssessmentType::Quiz,
                GradeLevel::Grade9,
                'Bos test',
                null,
                null,
                null,
                NavigationMode::Free,
                QuestionOrderMode::Fixed,
                OptionOrderMode::Fixed,
                ResultReleasePolicy::Immediate,
                null,
                [],
                'create_empty',
                $ctx['subject'],
            );
        } catch (\App\Exception\AssessmentException) {
            $rejected = true;
        }
        self::assertTrue($rejected);

        $archivedClass = $this->classroomManager()->create(
            $ctx['classroom']->getAcademicYear(),
            $ctx['owner'],
            'archived class',
            GradeLevel::Grade9,
            'archcls',
            'D',
            20,
        );
        $this->classroomManager()->archive($archivedClass, $ctx['owner'], 'archive_cls');
        $this->expectReason(
            fn () => $assigner->createDraft(
                $ctx['owner'],
                $ctx['institution'],
                $this->hasher()->workspaceReference('assessment', $ctx['assessment']->getId()),
                $this->hasher()->workspaceReference('classroom', $archivedClass->getId()),
                null,
                null,
                null,
            ),
            'not_assignable',
        );

        $closedYearClass = $this->classroomManager()->create(
            $ctx['classroom']->getAcademicYear(),
            $ctx['owner'],
            'closing class',
            GradeLevel::Grade9,
            'closey',
            'E',
            20,
        );
        $this->yearManager()->close($ctx['classroom']->getAcademicYear(), $ctx['owner'], 'close_year');
        $this->expectReason(
            fn () => $assigner->createDraft(
                $ctx['owner'],
                $ctx['institution'],
                $this->hasher()->workspaceReference('assessment', $ctx['assessment']->getId()),
                $this->hasher()->workspaceReference('classroom', $closedYearClass->getId()),
                null,
                null,
                null,
            ),
            'not_assignable',
        );
    }

    public function testRecipientSnapshotStaysHistorical(): void
    {
        $ctx = $this->institutionClass('snap');
        $refs = $this->references($ctx['assessment'], $ctx['classroom']);
        $deliveryReference = $this->assigner()->createDraft($ctx['owner'], $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null);
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $deliveryReference);
        $delivery = $this->requireDelivery($ctx['institution'], $deliveryReference);
        self::assertSame(1, $this->recipientCount($delivery->getId()));

        $next = $this->classroomManager()->create(
            $ctx['classroom']->getAcademicYear(),
            $ctx['owner'],
            'snap next',
            GradeLevel::Grade9,
            'next',
            'N',
            30,
        );
        $enrollment = $this->em->getRepository(ClassroomStudentEnrollment::class)->findOneBy([
            'classroom' => $ctx['classroom'],
            'status' => StudentEnrollmentStatus::Active,
        ]);
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $enrollment);
        $this->enrollmentManager()->transfer($enrollment, $ctx['owner'], $next, 'transfer_s');
        self::assertSame(1, $this->recipientCount($delivery->getId()));
        self::assertNotNull($this->catalog()->deliveryFor($this->fresh($ctx['student']), $this->hasher()->studentAssignmentCode($delivery->getId())));

        $late = $this->activeUser('snap-late@example.com', UserRole::Student);
        $lateMembership = $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $late, InstitutionMembershipRole::Student, 'add_late');
        $this->enrollmentManager()->enroll($ctx['classroom'], $ctx['owner'], $lateMembership, 'enroll_late');
        self::assertSame(1, $this->recipientCount($delivery->getId()));
        self::assertSame([], $this->catalog()->listFor($this->fresh($late)));
    }

    public function testAttemptExpiresAndReportHidesOtherClasses(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-09-27 10:00:00', new \DateTimeZone('UTC')));
        Clock::set($clock);
        $ctx = $this->institutionClass('clock', 60);
        $refs = $this->references($ctx['assessment'], $ctx['classroom']);
        $deliveryReference = $this->assigner()->createDraft($ctx['owner'], $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null);
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $deliveryReference);
        $student = $this->fresh($ctx['student']);
        $code = $this->catalog()->listFor($student)[0]['code'];
        $this->practice()->start($student, GradeLevel::Grade9, $code);
        $clock->modify('+3 minutes');
        try {
            $this->practice()->saveChoice($student, GradeLevel::Grade9, $code, 1, 1, 0);
            self::fail('Expired attempt must not accept an answer.');
        } catch (StudentPracticeException $exception) {
            self::assertSame('finished', $exception->getReason());
        }

        $teacherPage = $this->report()->result($ctx['teacher'], $this->requireDelivery($ctx['institution'], $deliveryReference), 1);
        self::assertNotNull($teacherPage);
        $outsider = $this->activeUser('clock-stranger-teacher@example.com');
        $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $outsider, InstitutionMembershipRole::Teacher, 'add_outsider');
        self::assertNull($this->report()->result($outsider, $this->requireDelivery($ctx['institution'], $deliveryReference), 1));
        self::assertNull($this->report()->result($ctx['sa'], $this->requireDelivery($ctx['institution'], $deliveryReference), 1));
        self::assertNull($this->report()->deliveryForInstitution($ctx['institution'], 'aaaaaaaaaaaaaaaaaaaa'));
    }

    public function testReportPaginatesWithoutAnswerPayload(): void
    {
        $ctx = $this->institutionClass('page');
        for ($i = 2; $i <= 21; ++$i) {
            $student = $this->activeUser(\sprintf('page-s%02d@example.com', $i), UserRole::Student);
            $membership = $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $student, InstitutionMembershipRole::Student, 'add_s'.$i);
            $this->enrollmentManager()->enroll($ctx['classroom'], $ctx['owner'], $membership, 'enroll_'.$i);
        }
        $refs = $this->references($ctx['assessment'], $ctx['classroom']);
        $deliveryReference = $this->assigner()->createDraft($ctx['owner'], $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null);
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $deliveryReference);
        $page = $this->report()->result($ctx['owner'], $this->requireDelivery($ctx['institution'], $deliveryReference), 2);
        self::assertNotNull($page);
        self::assertSame(21, $page->recipientCount);
        self::assertSame(2, $page->page);
        self::assertSame(2, $page->pageCount);
        self::assertCount(1, $page->students);
        $encoded = json_encode($page, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('@', $encoded);
        self::assertStringNotContainsString('ciphertext', $encoded);
        self::assertStringNotContainsString('correctStableKey', $encoded);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $encoded);
    }

    public function testHttpAssignSolveAndLimitedReport(): void
    {
        $ctx = $this->institutionClass('http');
        $refs = $this->references($ctx['assessment'], $ctx['classroom']);
        $ownerEmail = $ctx['owner']->getEmail();
        $studentEmail = $ctx['student']->getEmail();
        $teacherEmail = $ctx['teacher']->getEmail();
        $profile = new StudentProfileRequest();
        $profile->gradeLevel = GradeLevel::Grade9;
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        self::assertInstanceOf(StudentProfileManager::class, $profiles);
        $profiles->completeOnboarding($this->fresh($ctx['student']), $profile);
        $institutionName = $ctx['institution']->getName();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, $ownerEmail);
        $client->request('POST', '/kurum/testler/'.$refs['assessment'].'/ata', [
            'classroom' => $refs['classroom'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/kurum/testler/'.$refs['assessment'].'/ata');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('Maksimum deneme: 1', (string) $client->getResponse()->getContent());
        $client->submit($crawler->filter('form[action*="/ata"]')->form([
            'classroom' => $refs['classroom'],
        ]));
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Etkinleştir')->form());
        self::assertResponseRedirects();
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Öğrenci sayısı: 1', $html);
        self::assertStringNotContainsString($studentEmail, $html);
        self::assertStringNotContainsString('correctStableKey', $html);
        self::assertStringNotContainsString('ciphertext', $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);

        $client->request('GET', '/ogretmen/siniflarim');
        self::assertResponseStatusCodeSame(404);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->login($client, $teacherEmail);
        $client->request('GET', '/ogretmen/siniflarim');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertStringContainsString('Sınıflarım', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($studentEmail, (string) $client->getResponse()->getContent());

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->login($client, $studentEmail);
        $crawler = $client->request('GET', '/ogrenci/testler');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString($institutionName, (string) $client->getResponse()->getContent());
        $href = '';
        foreach ($crawler->filter('.student-catalog-card') as $card) {
            if ($card instanceof \DOMElement && str_contains($card->textContent ?? '', $institutionName) && $card->hasAttribute('href')) {
                $href = $card->getAttribute('href');
                break;
            }
        }
        self::assertNotSame('', $href);
        $code = basename($href);
        $client->request('GET', '/ogrenci/testler/'.str_repeat('a', 32));
        self::assertResponseStatusCodeSame(404);
        $crawler = $client->request('GET', '/ogrenci/testler/'.$code.'/coz');
        self::assertResponseStatusCodeSame(404);
        $crawler = $client->request('GET', '/ogrenci/testler/'.$code);
        $client->submit($crawler->filter('#student-test-start')->form());
        $client->followRedirect();
        $client->request('GET', '/ogrenci');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('section[aria-labelledby="continue-heading"] a[href="/ogrenci/testler/'.$code.'/coz"]');
        self::assertSelectorTextContains('section[aria-labelledby="continue-heading"]', 'Devam ediyor');
        self::assertSelectorTextContains('section[aria-labelledby="continue-heading"]', $institutionName);
        $dashboardHtml = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('correctStableKey', $dashboardHtml);
        self::assertStringNotContainsString('ciphertext', $dashboardHtml);
        self::assertStringNotContainsString('Doğru cevap', $dashboardHtml);
        $crawler = $client->request('GET', '/ogrenci/testler/'.$code.'/coz?s=1');
        $solve = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('correctStableKey', $solve);
        self::assertStringNotContainsString('ciphertext', $solve);
        $client->request('POST', '/ogrenci/testler/'.$code.'/cevap', [
            '_token' => (string) $crawler->filter('#student-test-answer input[name="_token"]')->attr('value'),
            'position' => '1',
            'choice' => '2',
            'expected_version' => (string) $crawler->filter('input[name="expected_version"]')->attr('value'),
        ]);
        $client->request('POST', '/ogrenci/testler/'.$code.'/bitir', [
            '_token' => (string) $crawler->filter('#student-test-finish input[name="_token"]')->attr('value'),
            'confirm' => '1',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('2,5', (string) $client->getResponse()->getContent());
        $client->request('GET', '/ogrenci');
        self::assertSelectorTextContains('section[aria-labelledby="continue-heading"]', 'Şu anda devam eden bir testin yok.');
    }

    public function testTeacherClassroomResultHttpDoesNotLeakOrWrite(): void
    {
        $ctx = $this->institutionClass('httpr');
        $idle = $this->namedActiveUser('httpr-idle@example.com', 'Bos', 'Ogrenci', UserRole::Student);
        $idleMembership = $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $idle, InstitutionMembershipRole::Student, 'add_idle');
        $this->enrollmentManager()->enroll($ctx['classroom'], $ctx['owner'], $idleMembership, 'enroll_idle');
        $otherStudent = $this->namedActiveUser('httpr-other-student@example.com', 'Diger', 'Sinif', UserRole::Student);
        $otherMembership = $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $otherStudent, InstitutionMembershipRole::Student, 'add_other_s');
        $otherClassroom = $this->classroomManager()->create(
            $ctx['classroom']->getAcademicYear(),
            $ctx['owner'],
            'Diger Sinif',
            GradeLevel::Grade9,
            'other',
            'B',
            30,
        );
        $this->enrollmentManager()->enroll($otherClassroom, $ctx['owner'], $otherMembership, 'enroll_other');
        $otherTeacher = $this->namedActiveUser('httpr-other-teacher@example.com', 'Diger', 'Ogretmen', UserRole::Student);
        $otherTeacherMembership = $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $otherTeacher, InstitutionMembershipRole::Teacher, 'add_other_t');
        $this->teacherManager()->assign($otherClassroom, $ctx['owner'], $otherTeacherMembership, TeacherAssignmentRole::HomeroomTeacher, 'assign_other_t');
        $staff = $this->namedActiveUser('httpr-staff@example.com', 'Yetkisiz', 'Personel', UserRole::Student);
        $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $staff, InstitutionMembershipRole::Staff, 'add_staff');
        $globalTeacher = $this->namedActiveUser('httpr-global-teacher@example.com', 'Global', 'Ogretmen', UserRole::Teacher);
        [$outsider] = $this->readyClassroom('httprout');

        $refs = $this->references($ctx['assessment'], $ctx['classroom']);
        $deliveryReference = $this->assigner()->createDraft($ctx['owner'], $ctx['institution'], $refs['assessment'], $refs['classroom'], null, null, null);
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $deliveryReference);
        $otherRefs = $this->references($ctx['assessment'], $otherClassroom);
        $otherReference = $this->assigner()->createDraft($ctx['owner'], $ctx['institution'], $otherRefs['assessment'], $otherRefs['classroom'], null, null, null);
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $otherReference);

        $student = $this->fresh($ctx['student']);
        $cards = $this->catalog()->listFor($student);
        self::assertCount(1, $cards);
        $code = $cards[0]['code'];
        $this->practice()->start($student, GradeLevel::Grade9, $code);
        $this->practice()->saveChoice($student, GradeLevel::Grade9, $code, 1, 2, 0);
        $this->practice()->finish($student, GradeLevel::Grade9, $code);
        $delivery = $this->requireDelivery($ctx['institution'], $deliveryReference);
        $attempt = $this->em->getRepository(AssessmentAttempt::class)->findOneBy([
            'delivery' => $delivery,
            'user' => $student,
        ]);
        self::assertInstanceOf(AssessmentAttempt::class, $attempt);
        $run = $this->em->find(AssessmentScoringRun::class, $this->scoringRunId($attempt));
        self::assertInstanceOf(AssessmentScoringRun::class, $run);
        $release = $this->releaseManager()->release($run, $this->fresh($ctx['owner']), 'release_httpr');
        self::assertSame(ResultReleaseStatus::Released, $release->getStatus());

        $publication = $this->publicationFor($ctx['assessment']);
        [$opens, $closes] = $this->defaultWindow();
        $wide = $this->deliveries()->createDraft(
            $ctx['institution'],
            $publication,
            AssessmentDeliveryAudienceType::Institution,
            null,
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'wide_httpr',
        );
        $this->deliveries()->activate($wide, $ctx['owner'], 'wide_act_httpr');
        $wide = $this->em->find(AssessmentDelivery::class, $wide->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $wide);

        $practiceAssessment = $this->publishFreePracticeAssessment($this->fresh($ctx['sa']), 'httpr_prac');
        $idleFresh = $this->fresh($idle);
        $practiceAttempt = $this->practice()->start($idleFresh, GradeLevel::Grade9, $practiceAssessment->getCode());
        $practiceDelivery = $practiceAttempt->getDelivery();

        $attemptId = $attempt->getId()->toRfc4122();
        $teacherEmail = $ctx['teacher']->getEmail();
        $studentEmail = $ctx['student']->getEmail();
        $idleEmail = $idle->getEmail();
        $practiceTitle = 'Bireysel Pratik Disi';
        $practiceReference = $this->hasher()->workspaceReference('delivery', $practiceDelivery->getId());
        $wideReference = $this->hasher()->workspaceReference('delivery', $wide->getId());
        $practiceAssessmentId = $practiceAssessment->getId()->toRfc4122();
        self::assertGreaterThan(0, $this->countRows('assessment_attempts'));
        self::assertGreaterThan(0, $this->countRows('assessment_scoring_runs'));
        self::assertGreaterThan(0, $this->countRows('assessment_result_releases'));
        $before = $this->attemptScoreReleaseFingerprint();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, $teacherEmail);
        $crawler = $client->request('GET', '/ogretmen/atamalar/'.$deliveryReference.'/sonuclar');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kurum testi httpr_inst', $html);
        self::assertStringContainsString('%50', $html);
        self::assertStringContainsString('%100', $html);
        $completed = null;
        $idleRow = null;
        foreach ($crawler->filter('table.institution-table tbody tr') as $row) {
            self::assertInstanceOf(\DOMElement::class, $row);
            $cells = [];
            foreach ($row->getElementsByTagName('td') as $cell) {
                $cells[] = trim($cell->textContent ?? '');
            }
            self::assertCount(7, $cells);
            if ('Tamamlandı' === $cells[1]) {
                $completed = $cells;
            }
            if ('Bos Ogrenci' === $cells[0]) {
                $idleRow = $cells;
            }
        }
        self::assertSame(['A U', 'Tamamlandı', '1', '0', '0', '2,5 / 2,5', '%100'], $completed);
        self::assertSame(['Bos Ogrenci', 'Başlamadı', '—', '—', '—', '—', '—'], $idleRow);
        self::assertStringNotContainsString($practiceTitle, $html);
        self::assertStringNotContainsString('Diger Sinif', $html);
        self::assertStringNotContainsString($studentEmail, $html);
        self::assertStringNotContainsString($idleEmail, $html);
        self::assertStringNotContainsString($attemptId, $html);
        self::assertStringNotContainsString('correctStableKey', $html);
        self::assertStringNotContainsString('storageKey', $html);
        self::assertStringNotContainsString('ciphertext', $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);
        foreach ([$wideReference, $practiceReference, $otherReference, str_repeat('a', 20)] as $hidden) {
            $client->request('GET', '/ogretmen/atamalar/'.$hidden.'/sonuclar');
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('GET', '/yonetim/testler/'.$practiceAssessmentId.'/sonuclar');
        self::assertResponseStatusCodeSame(403);
        $this->rebindDeliveryFixtures();
        self::assertSame($before, $this->attemptScoreReleaseFingerprint());

        foreach ([
            $otherTeacher->getEmail(),
            $staff->getEmail(),
            $globalTeacher->getEmail(),
            $outsider->getEmail(),
        ] as $email) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->login($client, $email);
            $client->request('GET', '/ogretmen/atamalar/'.$deliveryReference.'/sonuclar');
            self::assertResponseStatusCodeSame(404, $email);
            $client->request('GET', '/yonetim/testler/'.$practiceAssessmentId.'/sonuclar');
            self::assertResponseStatusCodeSame(403, $email);
        }
        $this->rebindDeliveryFixtures();
        self::assertSame($before, $this->attemptScoreReleaseFingerprint());
    }

    public function testDashboardHidesAssignedContinueWhenDeliveryIsCancelled(): void
    {
        $started = $this->startedClassroomAssignment('contcan');
        $this->deliveries()->cancel($started['delivery'], $started['ctx']['owner'], 'cancel_d', 'cancelled_by_owner');
        $this->em->clear();
        $student = $this->fresh($started['ctx']['student']);
        self::assertNull($this->practice()->continueCard($student, GradeLevel::Grade9));
        $attempt = $this->ownedAttempt($student, $started['delivery']->getId());
        self::assertSame(AssessmentAttemptStatus::InProgress, $attempt->getStatus());
        $this->assertDashboardContinueEmptyAndUnchanged($started, $attempt);
    }

    public function testDashboardHidesAssignedContinueWhenInstitutionIsSuspended(): void
    {
        $started = $this->startedClassroomAssignment('contins');
        $this->institutionStatus()->suspend($started['ctx']['institution'], $started['ctx']['sa'], 'suspend_inst');
        $this->em->clear();
        $student = $this->fresh($started['ctx']['student']);
        self::assertNull($this->practice()->continueCard($student, GradeLevel::Grade9));
        $attempt = $this->ownedAttempt($student, $started['delivery']->getId());
        self::assertSame(AssessmentAttemptStatus::InProgress, $attempt->getStatus());
    }

    public function testDashboardHidesAssignedContinueWhenStudentMembershipIsSuspended(): void
    {
        $started = $this->startedClassroomAssignment('contmem');
        $membership = $this->studentMembership($started['ctx']['institution'], $this->fresh($started['ctx']['student']));
        $enrollment = $this->em->createQueryBuilder()
            ->select('enrollment')
            ->from(ClassroomStudentEnrollment::class, 'enrollment')
            ->andWhere('enrollment.studentMembership = :membership')
            ->andWhere('enrollment.status = :active')
            ->setParameter('membership', $membership->getId(), 'uuid')
            ->setParameter('active', StudentEnrollmentStatus::Active)
            ->getQuery()
            ->getSingleResult();
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $enrollment);
        $this->enrollmentManager()->endEnrollment($enrollment, $started['ctx']['owner'], 'end_enr');
        $membership = $this->studentMembership($started['ctx']['institution'], $this->fresh($started['ctx']['student']));
        $this->membershipManager()->suspend($membership, $started['ctx']['owner'], 'suspend_stu');
        $this->em->clear();
        $student = $this->fresh($started['ctx']['student']);
        self::assertNull($this->practice()->continueCard($student, GradeLevel::Grade9));
        $attempt = $this->ownedAttempt($student, $started['delivery']->getId());
        self::assertSame(AssessmentAttemptStatus::InProgress, $attempt->getStatus());
    }

    public function testDashboardHidesAssignedContinueWhenRecipientIsRevoked(): void
    {
        $started = $this->startedClassroomAssignment('contrev');
        $recipient = $this->recipientOn($started['delivery']);
        $this->deliveries()->revokeRecipient($started['delivery'], $recipient, $started['ctx']['owner'], 'revoke_r');
        $this->em->clear();
        $student = $this->fresh($started['ctx']['student']);
        self::assertNull($this->practice()->continueCard($student, GradeLevel::Grade9));
        $attempt = $this->ownedAttempt($student, $started['delivery']->getId());
        self::assertSame(AssessmentAttemptStatus::InProgress, $attempt->getStatus());
    }

    public function testDashboardHidesAssignedContinueWhenDeliveryWindowClosed(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-10-06 07:00:00', new \DateTimeZone('UTC')));
        Clock::set($clock);
        $closes = $clock->now()->modify('+5 minutes')->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d\TH:i');
        $started = $this->startedClassroomAssignment('contwin', $closes);
        $before = $this->ownedAttempt($this->fresh($started['ctx']['student']), $started['delivery']->getId());
        $clock->modify('+10 minutes');
        $this->em->clear();
        $student = $this->fresh($started['ctx']['student']);
        self::assertNull($this->practice()->continueCard($student, GradeLevel::Grade9));
        $after = $this->ownedAttempt($student, $started['delivery']->getId());
        self::assertSame(AssessmentAttemptStatus::InProgress, $after->getStatus());
        self::assertEquals($before->getStartedAt(), $after->getStartedAt());
        self::assertEquals($before->getExpiresAt(), $after->getExpiresAt());
        self::assertEquals($before->getSubmittedAt(), $after->getSubmittedAt());
        Clock::set(new NativeClock());
    }

    public function testDashboardContinueSkipsInaccessibleAssignedAttemptForTheNextEligible(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-10-06 08:00:00', new \DateTimeZone('UTC')));
        Clock::set($clock);
        $ctx = $this->institutionClass('contnxt');
        $second = $this->publishInstitutionAssessment([
            'owner' => $ctx['owner'],
            'sa' => $ctx['sa'],
            'institution' => $ctx['institution'],
        ], 'contnxt2', GradeLevel::Grade9, '0.00');
        $firstRefs = $this->references($ctx['assessment'], $ctx['classroom']);
        $secondRefs = $this->references($second, $ctx['classroom']);
        $firstReference = $this->assigner()->createDraft($ctx['owner'], $ctx['institution'], $firstRefs['assessment'], $firstRefs['classroom'], null, null, null);
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $firstReference);
        $secondReference = $this->assigner()->createDraft($ctx['owner'], $ctx['institution'], $secondRefs['assessment'], $secondRefs['classroom'], null, null, null);
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $secondReference);
        $firstDelivery = $this->requireDelivery($ctx['institution'], $firstReference);
        $secondDelivery = $this->requireDelivery($ctx['institution'], $secondReference);
        $student = $this->fresh($ctx['student']);
        $firstCode = $this->hasher()->studentAssignmentCode($firstDelivery->getId());
        $secondCode = $this->hasher()->studentAssignmentCode($secondDelivery->getId());
        $this->practice()->start($student, GradeLevel::Grade9, $firstCode);
        $clock->modify('+1 minute');
        $this->practice()->start($this->fresh($ctx['student']), GradeLevel::Grade9, $secondCode);
        $newer = $this->practice()->continueCard($this->fresh($ctx['student']), GradeLevel::Grade9);
        self::assertNotNull($newer);
        self::assertSame($secondCode, $newer->code);
        $this->deliveries()->cancel($secondDelivery, $ctx['owner'], 'cancel_newer', 'cancelled_by_owner');
        $this->em->clear();
        $chosen = $this->practice()->continueCard($this->fresh($ctx['student']), GradeLevel::Grade9);
        self::assertNotNull($chosen);
        self::assertSame($firstCode, $chosen->code);
        self::assertSame(AssessmentAttemptStatus::InProgress, $this->ownedAttempt($this->fresh($ctx['student']), $secondDelivery->getId())->getStatus());
        Clock::set(new NativeClock());
    }

    /**
     * @return array{
     *     ctx: array{
     *         owner: User,
     *         sa: User,
     *         institution: Institution,
     *         classroom: Classroom,
     *         teacher: User,
     *         student: User,
     *         assessment: Assessment,
     *         platform: Assessment,
     *         subject: Subject
     *     },
     *     delivery: AssessmentDelivery,
     *     code: string
     * }
     */
    private function startedClassroomAssignment(string $prefix, ?string $closesRaw = null): array
    {
        $ctx = $this->institutionClass($prefix);
        $refs = $this->references($ctx['assessment'], $ctx['classroom']);
        $deliveryReference = $this->assigner()->createDraft(
            $ctx['owner'],
            $ctx['institution'],
            $refs['assessment'],
            $refs['classroom'],
            null,
            $closesRaw,
            null,
        );
        $this->assigner()->activate($ctx['owner'], $ctx['institution'], $deliveryReference);
        $student = $this->fresh($ctx['student']);
        $cards = $this->catalog()->listFor($student);
        self::assertNotSame([], $cards);
        $code = $cards[0]['code'];
        $this->practice()->start($student, GradeLevel::Grade9, $code);

        return [
            'ctx' => $ctx,
            'delivery' => $this->requireDelivery($ctx['institution'], $deliveryReference),
            'code' => $code,
        ];
    }

    private function ownedAttempt(User $student, \Symfony\Component\Uid\Uuid $deliveryId): AssessmentAttempt
    {
        $attempts = static::getContainer()->get(AssessmentAttemptRepository::class);
        self::assertInstanceOf(AssessmentAttemptRepository::class, $attempts);
        $attempt = $attempts->findOwnedForDelivery($deliveryId, $student->getId());
        self::assertInstanceOf(AssessmentAttempt::class, $attempt);

        return $attempt;
    }

    private function studentMembership(Institution $institution, User $student): InstitutionMembership
    {
        $row = $this->em->createQueryBuilder()
            ->select('membership')
            ->from(InstitutionMembership::class, 'membership')
            ->andWhere('membership.user = :user')
            ->andWhere('membership.institution = :institution')
            ->setParameter('user', $student->getId(), 'uuid')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getSingleResult();
        self::assertInstanceOf(InstitutionMembership::class, $row);

        return $row;
    }

    private function recipientOn(AssessmentDelivery $delivery): AssessmentDeliveryRecipient
    {
        $row = $this->em->createQueryBuilder()
            ->select('recipient')
            ->from(AssessmentDeliveryRecipient::class, 'recipient')
            ->andWhere('recipient.delivery = :delivery')
            ->setParameter('delivery', $delivery->getId(), 'uuid')
            ->getQuery()
            ->getSingleResult();
        self::assertInstanceOf(AssessmentDeliveryRecipient::class, $row);

        return $row;
    }

    /**
     * @param array{
     *     ctx: array{student: User},
     *     delivery: AssessmentDelivery,
     *     code: string
     * } $started
     */
    private function assertDashboardContinueEmptyAndUnchanged(array $started, AssessmentAttempt $before): void
    {
        $profile = new StudentProfileRequest();
        $profile->gradeLevel = GradeLevel::Grade9;
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        self::assertInstanceOf(StudentProfileManager::class, $profiles);
        $profiles->completeOnboarding($this->fresh($started['ctx']['student']), $profile);
        $email = $started['ctx']['student']->getEmail();
        $deliveryId = $started['delivery']->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->login($client, $email);
        $client->request('GET', '/ogrenci');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section[aria-labelledby="continue-heading"]', 'Şu anda devam eden bir testin yok.');
        self::assertSelectorNotExists('section[aria-labelledby="continue-heading"] a[href*="/coz"]');
        $this->rebindDeliveryFixtures();
        $after = $this->ownedAttempt($this->fresh($started['ctx']['student']), $deliveryId);
        self::assertSame(AssessmentAttemptStatus::InProgress, $after->getStatus());
        self::assertEquals($before->getStartedAt(), $after->getStartedAt());
        self::assertEquals($before->getExpiresAt(), $after->getExpiresAt());
        self::assertEquals($before->getSubmittedAt(), $after->getSubmittedAt());
    }

    private function login(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    /**
     * @return array{
     *     owner: User,
     *     sa: User,
     *     institution: Institution,
     *     classroom: Classroom,
     *     teacher: User,
     *     student: User,
     *     assessment: Assessment,
     *     platform: Assessment,
     *     subject: Subject
     * }
     */
    private function institutionClass(string $prefix, ?int $duration = 3600): array
    {
        $base = $this->publishedDeliveryContext($prefix);
        $assessment = $this->publishInstitutionAssessment([
            'owner' => $base['owner'],
            'sa' => $base['sa'],
            'institution' => $base['institution'],
        ], $prefix.'_inst', GradeLevel::Grade9, '0.00', $duration);

        return [
            'owner' => $base['owner'],
            'sa' => $base['sa'],
            'institution' => $base['institution'],
            'classroom' => $base['classroom'],
            'teacher' => $base['teacher'],
            'student' => $base['student'],
            'assessment' => $assessment,
            'platform' => $base['assessment'],
            'subject' => $this->subjectFor($assessment),
        ];
    }

    /**
     * @param array{owner: User, sa: User, institution: Institution, subject?: Subject} $ctx
     */
    private function publishInstitutionAssessment(array $ctx, string $suffix, GradeLevel $grade, string $penalty, ?int $duration = 3600, QuestionType $questionType = QuestionType::SingleChoice): Assessment
    {
        $subject = $ctx['subject'] ?? $this->subjects()->create($ctx['sa'], 'math_'.$suffix, 'Math '.$suffix, 'create_subj_'.$suffix);
        $draftProgram = $this->programs()->createDraft($subject, $ctx['sa'], $grade, 'math_'.$suffix, 'Math', '1.0', 'prog_'.$suffix);
        $unit = $this->units()->create($draftProgram, $ctx['sa'], 'u1', 'Unit', 1, 'create_u_'.$suffix);
        $topic = $this->topics()->createRoot($unit, $ctx['sa'], 't1', 'Topic', 1, 'create_t_'.$suffix);
        $lo = $this->outcomes()->create($topic, $ctx['sa'], 'lo_'.$suffix, 'Outcome', 1, 'create_lo_'.$suffix);
        $this->programs()->publish($draftProgram, $ctx['sa'], 'pub_curr_'.$suffix);
        $reviewer = $this->activeUser($suffix.'-qrev@example.com', UserRole::HeadTeacher);
        $question = QuestionType::MultipleChoice === $questionType
            ? $this->publishChoiceQuestion($ctx['sa'], $reviewer, $subject, $lo, $suffix, $grade, $questionType)
            : $this->createPublishedPlatformQuestion($ctx['sa'], $reviewer, $subject, $lo, $suffix, $grade);
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        self::assertInstanceOf(QuestionRevisionRepository::class, $revisions);
        $revision = $revisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $revision);
        $section = $this->sectionWithPenalty($question, $revision, $penalty);
        $assessment = $this->assessments()->createDraftAssessment(
            $ctx['owner'],
            AssessmentScope::Institution,
            $ctx['institution'],
            AssessmentType::Quiz,
            $grade,
            'Kurum testi '.$suffix,
            null,
            null,
            $duration,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [$section],
            'create_inst_'.$suffix,
            $subject,
        );
        $this->assessments()->submitForReview($assessment, $ctx['owner'], 'submit_inst_'.$suffix);
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $publisher = $this->users->find($ctx['sa']->getId());
        self::assertInstanceOf(User::class, $publisher);
        $this->assessments()->publish($assessment, $publisher, 'publish_inst_'.$suffix);
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

        return $assessment;
    }

    private function publishChoiceQuestion(
        User $author,
        User $publisher,
        Subject $subject,
        \App\Entity\CurriculumLearningOutcome $lo,
        string $suffix,
        GradeLevel $grade,
        QuestionType $type,
    ): Question {
        $question = $this->questions()->createDraftQuestion(
            $author,
            \App\Enum\QuestionScope::Platform,
            null,
            $subject,
            $grade,
            $type,
            \App\Question\Content\QuestionContentDocument::paragraph('Q '.$suffix.'?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => \App\Question\Content\QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => \App\Question\Content\QuestionContentDocument::paragraph('B'), 'position' => 2],
                ['stableKey' => 'opt_c', 'content' => \App\Question\Content\QuestionContentDocument::paragraph('C'), 'position' => 3],
            ],
            ['correctStableKeys' => ['opt_a', 'opt_c']],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            \App\Enum\QuestionDifficulty::Easy,
            'create_q_'.$suffix,
        );
        $this->questions()->submitForReview($question, $author, 'submit_q');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);
        $publisher = $this->users->find($publisher->getId());
        self::assertInstanceOf(User::class, $publisher);
        $this->questions()->publish($question, $publisher, 'pub_q');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);

        return $question;
    }

    /**
     * @param array{owner: User, institution: Institution, assessment: Assessment, subject: Subject} $ctx
     */
    private function draftInstitutionAssessment(array $ctx, string $suffix): Assessment
    {
        $revision = $ctx['assessment']->getPublishedRevision();
        self::assertNotNull($revision);
        $item = $this->em->createQueryBuilder()
            ->select('item', 'questionRevision', 'question')
            ->from(\App\Entity\AssessmentItem::class, 'item')
            ->innerJoin('item.questionRevision', 'questionRevision')
            ->innerJoin('questionRevision.question', 'question')
            ->andWhere('item.assessmentRevision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(\App\Entity\AssessmentItem::class, $item);
        $section = $this->sectionWithPenalty($item->getQuestionRevision()->getQuestion(), $item->getQuestionRevision(), '0.00');

        return $this->assessments()->createDraftAssessment(
            $ctx['owner'],
            AssessmentScope::Institution,
            $ctx['institution'],
            AssessmentType::Quiz,
            GradeLevel::Grade9,
            'Taslak '.$suffix,
            null,
            null,
            null,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [$section],
            'create_draft_'.$suffix,
            $ctx['subject'],
        );
    }

    private function subjectFor(Assessment $assessment): Subject
    {
        $subject = $assessment->getSubject();
        self::assertInstanceOf(Subject::class, $subject);

        return $subject;
    }

    /**
     * @return array{assessment: string, classroom: string}
     */
    private function references(Assessment $assessment, Classroom $classroom): array
    {
        return [
            'assessment' => $this->hasher()->workspaceReference('assessment', $assessment->getId()),
            'classroom' => $this->hasher()->workspaceReference('classroom', $classroom->getId()),
        ];
    }

    private function requireDelivery(Institution $institution, string $reference): AssessmentDelivery
    {
        $delivery = $this->report()->deliveryForInstitution($institution, $reference);
        self::assertNotNull($delivery);

        return $delivery;
    }

    private function recipientCount(\Symfony\Component\Uid\Uuid $deliveryId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(recipient.id)')
            ->from(AssessmentDeliveryRecipient::class, 'recipient')
            ->andWhere('recipient.delivery = :delivery')
            ->setParameter('delivery', $deliveryId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function attemptCount(\Symfony\Component\Uid\Uuid $deliveryId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(attempt.id)')
            ->from(AssessmentAttempt::class, 'attempt')
            ->andWhere('attempt.delivery = :delivery')
            ->setParameter('delivery', $deliveryId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function latestAudit(SecurityAuditAction $action): SecurityAuditEvent
    {
        $event = $this->em->createQueryBuilder()
            ->select('event')
            ->from(SecurityAuditEvent::class, 'event')
            ->andWhere('event.action = :action')
            ->setParameter('action', $action)
            ->orderBy('event.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(SecurityAuditEvent::class, $event);

        return $event;
    }

    private function fresh(User $user): User
    {
        $fresh = $this->users->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }

    /**
     * @param callable(): mixed $action
     */
    private function expectReason(callable $action, string $reason, string $label = ''): void
    {
        try {
            $action();
            self::fail($label.' expected '.$reason);
        } catch (InstitutionTestAssignmentException $exception) {
            self::assertSame($reason, $exception->getReason(), $label);
        }
    }

    private function assigner(): InstitutionClassroomTestAssigner
    {
        $service = static::getContainer()->get(InstitutionClassroomTestAssigner::class);
        self::assertInstanceOf(InstitutionClassroomTestAssigner::class, $service);

        return $service;
    }

    private function report(): InstitutionDeliveryReport
    {
        $service = static::getContainer()->get(InstitutionDeliveryReport::class);
        self::assertInstanceOf(InstitutionDeliveryReport::class, $service);

        return $service;
    }

    private function catalog(): StudentAssignedTestCatalog
    {
        $service = static::getContainer()->get(StudentAssignedTestCatalog::class);
        self::assertInstanceOf(StudentAssignedTestCatalog::class, $service);

        return $service;
    }

    private function practice(): StudentAssessmentPractice
    {
        $service = static::getContainer()->get(StudentAssessmentPractice::class);
        self::assertInstanceOf(StudentAssessmentPractice::class, $service);

        return $service;
    }

    private function history(): StudentTestHistoryQuery
    {
        $service = static::getContainer()->get(StudentTestHistoryQuery::class);
        self::assertInstanceOf(StudentTestHistoryQuery::class, $service);

        return $service;
    }

    /**
     * @return array{
     *     title: string,
     *     position: int,
     *     questionOrderMode: QuestionOrderMode,
     *     items: list<array{
     *         questionId: \Symfony\Component\Uid\Uuid,
     *         questionRevisionId: \Symfony\Component\Uid\Uuid,
     *         position: int,
     *         points: string,
     *         penaltyPoints: string,
     *         required: bool
     *     }>
     * }
     */
    private function sectionWithPenalty(Question $question, QuestionRevision $revision, string $penalty): array
    {
        $section = $this->sectionWithItem($question, $revision);

        return [
            'title' => $section['title'],
            'position' => $section['position'],
            'questionOrderMode' => $section['questionOrderMode'],
            'items' => [[
                'questionId' => $section['items'][0]['questionId'],
                'questionRevisionId' => $section['items'][0]['questionRevisionId'],
                'position' => $section['items'][0]['position'],
                'points' => $section['items'][0]['points'],
                'penaltyPoints' => $penalty,
                'required' => $section['items'][0]['required'],
            ]],
        ];
    }

    private function namedActiveUser(string $email, string $firstName, string $lastName, UserRole $role): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', $firstName, $lastName, $role);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function publicationFor(Assessment $assessment): AssessmentPublication
    {
        $publications = static::getContainer()->get(AssessmentPublicationRepository::class);
        self::assertInstanceOf(AssessmentPublicationRepository::class, $publications);
        $publication = $publications->findOneBy(['assessment' => $assessment, 'publicationNumber' => 1]);
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        return $publication;
    }

    private function publishFreePracticeAssessment(User $author, string $suffix): Assessment
    {
        $reviewer = $this->activeUser($suffix.'-prev@example.com', UserRole::HeadTeacher);
        $subject = $this->subjects()->create($author, 'math_'.$suffix, 'Math '.$suffix, 'subj_'.$suffix);
        $draft = $this->programs()->createDraft($subject, $author, GradeLevel::Grade9, 'math_'.$suffix, 'Math', '1.0', 'prog_'.$suffix);
        $unit = $this->units()->create($draft, $author, 'u1', 'Unit', 1, 'unit_'.$suffix);
        $topic = $this->topics()->createRoot($unit, $author, 't1', 'Topic', 1, 'topic_'.$suffix);
        $outcome = $this->outcomes()->create($topic, $author, 'lo_'.$suffix, 'Outcome', 1, 'outcome_'.$suffix);
        $this->programs()->publish($draft, $author, 'pub_'.$suffix);
        $question = $this->createPublishedPlatformQuestion($author, $reviewer, $subject, $outcome, $suffix);
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        self::assertInstanceOf(QuestionRevisionRepository::class, $revisions);
        $revision = $revisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $revision);
        $assessment = $this->assessments()->createDraftAssessment(
            $author,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade9,
            'Bireysel Pratik Disi',
            null,
            null,
            3600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [$this->sectionWithPenalty($question, $revision, '0.00')],
            'create_'.$suffix,
            $subject,
        );
        $this->assessments()->submitForReview($assessment, $author, 'submit_'.$suffix);
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $publisher = $this->users->find($reviewer->getId());
        self::assertInstanceOf(User::class, $publisher);
        $this->assessments()->publish($assessment, $publisher, 'publish_'.$suffix);
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $actor = $this->users->find($author->getId());
        self::assertInstanceOf(User::class, $actor);
        $packages = static::getContainer()->get(AccessPackageManager::class);
        self::assertInstanceOf(AccessPackageManager::class, $packages);
        $packages->setAssessmentAccessPolicy($assessment, $actor, ResourceAccessClass::Free, 'free_'.$suffix);

        return $assessment;
    }

    private function scoringRunId(AssessmentAttempt $attempt): \Symfony\Component\Uid\Uuid
    {
        $run = $this->em->getRepository(AssessmentScoringRun::class)->findOneBy(['attempt' => $attempt]);
        self::assertInstanceOf(AssessmentScoringRun::class, $run);

        return $run->getId();
    }

    private function releaseManager(): AssessmentResultReleaseManager
    {
        $service = static::getContainer()->get(AssessmentResultReleaseManager::class);
        self::assertInstanceOf(AssessmentResultReleaseManager::class, $service);

        return $service;
    }

    private function countRows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function attemptScoreReleaseFingerprint(): string
    {
        $connection = $this->em->getConnection();
        $payload = [
            $connection->fetchAllAssociative('SELECT HEX(id) AS id, status, updated_at, submitted_at FROM assessment_attempts ORDER BY id'),
            $connection->fetchAllAssociative('SELECT HEX(id) AS id, status, updated_at, final_points, percentage FROM assessment_scoring_runs ORDER BY id'),
            $connection->fetchAllAssociative('SELECT HEX(id) AS id, status, updated_at FROM assessment_result_releases ORDER BY id'),
        ];

        return hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function hasher(): InvitationCodeDigestHasher
    {
        $service = static::getContainer()->get(InvitationCodeDigestHasher::class);
        self::assertInstanceOf(InvitationCodeDigestHasher::class, $service);

        return $service;
    }
}
