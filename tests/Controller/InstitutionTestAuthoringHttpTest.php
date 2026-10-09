<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\Question;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\InvitationCodeDigestHasher;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class InstitutionTestAuthoringHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    protected function setUp(): void
    {
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testOwnerAndManagerPublishAQuizThatCanBeAssigned(): void
    {
        $this->bootPeople();
        $refs = $this->seedQuestions();
        $this->seedClassroom();
        $owner = $this->browser();
        $this->login($owner, 'quiz-owner@example.com');
        $before = $this->assessmentCount();
        $owner->request('GET', '/kurum/testler/yeni');
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->assessmentCount());
        self::assertSame(0, $this->auditCount('assessment_created'));
        $page = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Toplama sorusu', $page);
        self::assertStringNotContainsString('Platform sorusu', $page);
        self::assertStringNotContainsString('Baska kurum sorusu', $page);
        self::assertStringNotContainsString('Taslak sorusu', $page);
        self::assertStringNotContainsString('Coklu soru', $page);
        self::assertStringNotContainsString('Ikinci sinif sorusu', $page);
        self::assertStringNotContainsString('quiz-owner@example.com', $page);
        self::assertStringNotContainsString('correctStableKey', $page);
        self::assertStringNotContainsString('storageKey', $page);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $page);

        $form = $owner->getCrawler()->selectButton('Taslağı oluştur')->form()->getPhpValues();
        $form['title'] = 'Kurum Quizi';
        $form['duration_minutes'] = '10';
        $form['points'] = '2';
        $form['question_refs'] = [$refs['good']];
        $form['institution_id'] = $this->institutionId('Bora Koleji');
        $owner->request('POST', '/kurum/testler/yeni', $form);
        self::assertResponseRedirects();
        $owner->followRedirect();
        $detail = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Kurum Quizi', $detail);
        self::assertStringContainsString('Taslak', $detail);
        self::assertStringContainsString('İncelemeye gönder', $detail);
        self::assertStringNotContainsString('>Yayınla<', $detail);
        self::assertStringNotContainsString('correctStableKey', $detail);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $detail);
        self::assertSame('Ada Koleji', $this->assessmentInstitution('Kurum Quizi'));
        self::assertSame('institution', $this->assessmentScope('Kurum Quizi'));
        self::assertSame('draft', $this->assessmentStatus('Kurum Quizi'));
        self::assertSame('2.00', $this->itemValue('points'));
        self::assertSame('0.00', $this->itemValue('penalty_points'));
        $created = $this->latestAudit('assessment_created');
        self::assertSame('assessment_manager', $created['source'] ?? null);
        self::assertSame('panel_test_create', $created['reason_code'] ?? null);
        self::assertSame('quiz', $created['assessment_type'] ?? null);
        self::assertSame('draft', $created['new_status'] ?? null);
        self::assertArrayHasKey('institution_id', $created);
        self::assertStringNotContainsString('quiz-owner@example.com', (string) json_encode($created));

        $owner->submit($owner->getCrawler()->selectButton('İncelemeye gönder')->form());
        $owner->followRedirect();
        self::assertStringContainsString('İncelemede', (string) $owner->getResponse()->getContent());
        self::assertStringNotContainsString('>Yayınla<', (string) $owner->getResponse()->getContent());
        self::assertSame('in_review', $this->assessmentStatus('Kurum Quizi'));
        $submitted = $this->latestAudit('assessment_submitted_for_review');
        self::assertSame('assessment_manager', $submitted['source'] ?? null);
        self::assertSame('panel_test_submit', $submitted['reason_code'] ?? null);
        self::assertSame('draft', $submitted['old_status'] ?? null);
        self::assertSame('in_review', $submitted['new_status'] ?? null);

        $reference = $this->referenceFromPath((string) $owner->getCrawler()->getUri());
        $owner->request('POST', '/kurum/testler/'.$reference.'/yayinla', [
            '_token' => $this->csrf($owner, 'institution_test_publish'),
            'confirm' => '1',
        ]);
        self::assertResponseRedirects();
        $owner->followRedirect();
        $denied = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Kendi hazırladığınız testi yayımlayamazsınız.', $denied);
        self::assertStringNotContainsString('>Yayınla<', $denied);
        self::assertSame('in_review', $this->assessmentStatus('Kurum Quizi'));
        self::assertSame(0, $this->auditCount('assessment_published'));

        $manager = $this->browser();
        $this->login($manager, 'quiz-manager@example.com');
        $manager->request('GET', '/kurum/testler/'.$reference);
        self::assertStringContainsString('>Yayınla<', (string) $manager->getResponse()->getContent());
        $manager->submit($manager->getCrawler()->selectButton('Yayınla')->form());
        $manager->followRedirect();
        $published = (string) $manager->getResponse()->getContent();
        self::assertStringContainsString('Yayında', $published);
        self::assertStringContainsString('Sınıfa ata', $published);
        self::assertSame('published', $this->assessmentStatus('Kurum Quizi'));
        $audit = $this->latestAudit('assessment_published');
        self::assertSame('assessment_manager', $audit['source'] ?? null);
        self::assertSame('panel_test_publish', $audit['reason_code'] ?? null);
        self::assertSame('in_review', $audit['old_status'] ?? null);
        self::assertSame('published', $audit['new_status'] ?? null);
        self::assertSame('quiz', $audit['assessment_type'] ?? null);

        $manager->request('GET', '/kurum/testler');
        $list = (string) $manager->getResponse()->getContent();
        self::assertStringContainsString('Kurum Quizi', $list);
        self::assertStringContainsString('Yayında', $list);
        $manager->request('GET', '/kurum/testler/'.$reference.'/ata');
        self::assertStringContainsString('1 A', (string) $manager->getResponse()->getContent());
        $manager->submit($manager->getCrawler()->selectButton('Sınıfa ata')->form());
        self::assertResponseRedirects();
        $manager->followRedirect();
        self::assertStringContainsString('1 A', (string) $manager->getResponse()->getContent());
        self::assertSame(1, $this->deliveryCount());
    }

    public function testEmptyQuestionListBlocksCreate(): void
    {
        $this->bootPeople();
        $owner = $this->browser();
        $this->login($owner, 'quiz-owner@example.com');
        $owner->request('GET', '/kurum/testler/yeni');
        self::assertResponseIsSuccessful();
        $page = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Henüz kullanılabilir yayımlı kurum sorusu yok.', $page);
        self::assertStringNotContainsString('Taslağı oluştur', $page);
        $owner->request('POST', '/kurum/testler/yeni', [
            '_token' => $this->csrf($owner, 'institution_test_create'),
            'title' => 'Bos Test',
            'grade' => '1',
            'points' => '1',
            'question_refs' => ['aaaaaaaaaaaaaaaaaaaa'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->assessmentCount());
        self::assertSame(0, $this->auditCount('assessment_created'));
    }

    public function testRejectedQuestionsLeaveAssessmentsUnchanged(): void
    {
        $this->bootPeople();
        $refs = $this->seedQuestions();
        $owner = $this->browser();
        $this->login($owner, 'quiz-owner@example.com');
        $crawler = $owner->request('GET', '/kurum/testler/yeni');
        self::assertSame(0, $this->assessmentCount());
        $owner->request('GET', '/kurum/testler/yeni');
        self::assertSame(0, $this->assessmentCount());
        $owner->request('GET', '/kurum/testler/yeni?sinif=2');
        $gradeTwo = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Ikinci sinif sorusu', $gradeTwo);
        self::assertStringNotContainsString('Toplama sorusu', $gradeTwo);
        self::assertSame(0, $this->assessmentCount());

        $owner->request('POST', '/kurum/testler/yeni', [
            '_token' => 'bad',
            'title' => 'Sahte',
            'grade' => '1',
            'points' => '1',
            'question_refs' => [$refs['good']],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->assessmentCount());
        self::assertSame(0, $this->auditCount('assessment_created'));

        $crawler = $owner->request('GET', '/kurum/testler/yeni');
        $form = $crawler->selectButton('Taslağı oluştur')->form()->getPhpValues();
        $form['title'] = 'Puansiz';
        $form['points'] = '0';
        $form['question_refs'] = [$refs['good']];
        $owner->request('POST', '/kurum/testler/yeni', $form);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Süre veya puan geçerli olmalıdır.', (string) $owner->getResponse()->getContent());
        self::assertSame(0, $this->assessmentCount());
        self::assertSame(0, $this->auditCount('assessment_created'));

        $crawler = $owner->request('GET', '/kurum/testler/yeni');
        $form = $crawler->selectButton('Taslağı oluştur')->form()->getPhpValues();
        $form['title'] = 'Karisik ders';
        $form['points'] = '1';
        $form['question_refs'] = [$refs['good'], $refs['other']];
        $owner->request('POST', '/kurum/testler/yeni', $form);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Seçilen sorular aynı dersten olmalıdır.', (string) $owner->getResponse()->getContent());
        self::assertSame(0, $this->assessmentCount());
        self::assertSame(0, $this->auditCount('assessment_created'));

        foreach (['platform', 'foreign', 'draft', 'multi', 'grade2'] as $key) {
            $crawler = $owner->request('GET', '/kurum/testler/yeni');
            $form = $crawler->selectButton('Taslağı oluştur')->form()->getPhpValues();
            $form['title'] = 'Uygunsuz';
            $form['points'] = '1';
            $form['grade'] = '1';
            $form['question_refs'] = [$refs[$key]];
            $owner->request('POST', '/kurum/testler/yeni', $form);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(0, $this->assessmentCount());
            self::assertSame(0, $this->auditCount('assessment_created'));
        }

        $crawler = $owner->request('GET', '/kurum/testler/yeni');
        $form = $crawler->selectButton('Taslağı oluştur')->form()->getPhpValues();
        $form['title'] = 'Kurum Quizi';
        $form['points'] = '1';
        $form['question_refs'] = [$refs['good']];
        $owner->request('POST', '/kurum/testler/yeni', $form);
        $owner->followRedirect();
        $reference = $this->referenceFromPath((string) $owner->getCrawler()->getUri());
        $created = $this->auditCount('assessment_created');
        $owner->request('GET', '/kurum/testler/'.$reference.'/incelemeye-gonder');
        self::assertResponseStatusCodeSame(405);
        self::assertSame('draft', $this->assessmentStatus('Kurum Quizi'));
        self::assertSame(0, $this->auditCount('assessment_submitted_for_review'));
        self::assertSame($created, $this->auditCount('assessment_created'));
    }

    public function testUnauthorizedActorsCannotReadOrCreate(): void
    {
        $this->bootPeople();
        $refs = $this->seedQuestions();
        $owner = $this->browser();
        $this->login($owner, 'quiz-owner@example.com');
        $crawler = $owner->request('GET', '/kurum/testler/yeni');
        $form = $crawler->selectButton('Taslağı oluştur')->form()->getPhpValues();
        $form['title'] = 'Kurum Quizi';
        $form['points'] = '1';
        $form['question_refs'] = [$refs['good']];
        $owner->request('POST', '/kurum/testler/yeni', $form);
        $owner->followRedirect();
        $reference = $this->referenceFromPath((string) $owner->getCrawler()->getUri());
        $created = $this->auditCount('assessment_created');

        $anonymous = $this->browser();
        $anonymous->request('GET', '/kurum/testler/yeni');
        self::assertResponseRedirects('/giris');
        $anonymous->request('POST', '/kurum/testler/yeni', ['title' => 'Gizli']);
        self::assertResponseRedirects('/giris');

        foreach ([
            'quiz-teacher@example.com',
            'quiz-student@example.com',
            'quiz-staff@example.com',
            'quiz-global@example.com',
        ] as $email) {
            $client = $this->browser();
            $this->login($client, $email);
            $client->request('GET', '/kurum/testler/yeni');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/kurum/testler/yeni', [
                'title' => 'Gizli',
                'grade' => '1',
                'points' => '1',
                'question_refs' => [$refs['good']],
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/kurum/testler/'.$reference.'/yayinla', ['confirm' => '1']);
            self::assertResponseStatusCodeSame(403);
        }

        $other = $this->browser();
        $this->login($other, 'quiz-other@example.com');
        $other->request('GET', '/kurum/testler/yeni');
        $page = (string) $other->getResponse()->getContent();
        self::assertStringNotContainsString('Toplama sorusu', $page);
        self::assertStringNotContainsString($refs['good'], $page);
        self::assertStringContainsString('Baska kurum sorusu', $page);
        $other->request('POST', '/kurum/testler/'.$reference.'/yayinla', [
            '_token' => $this->csrf($other, 'institution_test_publish'),
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('draft', $this->assessmentStatus('Kurum Quizi'));
        self::assertSame($created, $this->auditCount('assessment_created'));
        self::assertSame(0, $this->auditCount('assessment_published'));
    }

    private function bootPeople(): void
    {
        $this->createActive('quiz-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('quiz-head@example.com', UserRole::HeadTeacher);
        $this->createActive('quiz-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('quiz-manager@example.com', UserRole::User, 'Mert', 'Kaya');
        $this->createActive('quiz-teacher@example.com', UserRole::Teacher, 'Ece', 'Öztürk');
        $this->createActive('quiz-student@example.com', UserRole::Student, 'Can', 'Aydın');
        $this->createActive('quiz-staff@example.com', UserRole::User, 'Selin', 'Arslan');
        $this->createActive('quiz-other@example.com', UserRole::User, 'Bora', 'Demir');
        $this->createActive('quiz-global@example.com', UserRole::Teacher);
        $this->openInstitution('quiz-owner@example.com', 'Ada Koleji');
        $this->openInstitution('quiz-other@example.com', 'Bora Koleji');
        $this->addMember('quiz-owner@example.com', 'quiz-manager@example.com', InstitutionMembershipRole::Manager, 'Ada Koleji');
        $this->addMember('quiz-owner@example.com', 'quiz-teacher@example.com', InstitutionMembershipRole::Teacher, 'Ada Koleji');
        $this->addMember('quiz-owner@example.com', 'quiz-student@example.com', InstitutionMembershipRole::Student, 'Ada Koleji');
        $this->addMember('quiz-owner@example.com', 'quiz-staff@example.com', InstitutionMembershipRole::Staff, 'Ada Koleji');
    }

    /**
     * @return array<string, string>
     */
    private function seedQuestions(): array
    {
        $refs = $this->withKernel(function (): array {
            $sa = $this->user('quiz-sa@example.com');
            $head = $this->user('quiz-head@example.com');
            $manager = $this->user('quiz-manager@example.com');
            $other = $this->user('quiz-other@example.com');
            $ada = $this->institution('Ada Koleji');
            $bora = $this->institution('Bora Koleji');
            $math = $this->subject($sa, 'math_quiz', 'Matematik');
            $science = $this->subject($sa, 'fen_quiz', 'Fen');
            $mathOutcome = $this->outcome($sa, $math, GradeLevel::Grade1, 'math');
            $mathGradeTwo = $this->outcome($sa, $math, GradeLevel::Grade2, 'math2');
            $scienceOutcome = $this->outcome($sa, $science, GradeLevel::Grade1, 'fen');
            $refs = [
                'good' => $this->question($sa, $manager, $ada, $math, $mathOutcome, GradeLevel::Grade1, QuestionType::SingleChoice, 'Toplama sorusu', true),
                'other' => $this->question($sa, $manager, $ada, $science, $scienceOutcome, GradeLevel::Grade1, QuestionType::SingleChoice, 'Fen sorusu', true),
                'grade2' => $this->question($sa, $manager, $ada, $math, $mathGradeTwo, GradeLevel::Grade2, QuestionType::SingleChoice, 'Ikinci sinif sorusu', true),
                'multi' => $this->question($sa, $manager, $ada, $math, $mathOutcome, GradeLevel::Grade1, QuestionType::MultipleChoice, 'Coklu soru', true),
                'draft' => $this->question($sa, $manager, $ada, $math, $mathOutcome, GradeLevel::Grade1, QuestionType::SingleChoice, 'Taslak sorusu', false),
                'foreign' => $this->question($sa, $other, $bora, $math, $mathOutcome, GradeLevel::Grade1, QuestionType::SingleChoice, 'Baska kurum sorusu', true),
                'platform' => $this->question($sa, $head, null, $math, $mathOutcome, GradeLevel::Grade1, QuestionType::SingleChoice, 'Platform sorusu', true),
            ];

            return $refs;
        });

        return \is_array($refs) ? $refs : [];
    }

    private function seedClassroom(): void
    {
        $this->withKernel(function (): void {
            $years = static::getContainer()->get(AcademicYearManager::class);
            $classrooms = static::getContainer()->get(ClassroomManager::class);
            $students = static::getContainer()->get(ClassroomStudentEnrollmentManager::class);
            $memberships = static::getContainer()->get(InstitutionMembershipRepository::class);
            self::assertInstanceOf(AcademicYearManager::class, $years);
            self::assertInstanceOf(ClassroomManager::class, $classrooms);
            self::assertInstanceOf(ClassroomStudentEnrollmentManager::class, $students);
            self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
            $owner = $this->user('quiz-owner@example.com');
            $institution = $this->institution('Ada Koleji');
            $year = $years->createPlanned($institution, $owner, 'Quiz Yili', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'seed_year');
            $years->activate($year, $owner, 'seed_activate');
            $classroom = $classrooms->create($year, $owner, '1 A', GradeLevel::Grade1, 'seed_cls', 'A', 20);
            $student = $memberships->findMembership($this->user('quiz-student@example.com'), $institution);
            self::assertInstanceOf(InstitutionMembership::class, $student);
            $students->enroll($classroom, $owner, $student, 'seed_student');
        });
    }

    private function subject(User $actor, string $code, string $name): Subject
    {
        $subjects = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjects);

        return $subjects->create($actor, $code, $name, 'create_subject');
    }

    private function outcome(User $actor, Subject $subject, GradeLevel $grade, string $suffix): CurriculumLearningOutcome
    {
        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programs);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        self::assertInstanceOf(CurriculumTopicManager::class, $topics);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);
        $program = $programs->createDraft($subject, $actor, $grade, 'prog_'.$suffix, 'Program', '1.0', 'prog_'.$suffix);
        $unit = $units->create($program, $actor, 'u_'.$suffix, 'Unite', 1, 'unit_'.$suffix);
        $topic = $topics->createRoot($unit, $actor, 't_'.$suffix, 'Konu', 1, 'topic_'.$suffix);
        $outcome = $outcomes->create($topic, $actor, 'lo_'.$suffix, 'Kazanim', 1, 'outcome_'.$suffix);
        $programs->publish($program, $actor, 'publish_'.$suffix);

        return $outcome;
    }

    private function question(
        User $author,
        User $publisher,
        ?Institution $institution,
        Subject $subject,
        CurriculumLearningOutcome $outcome,
        GradeLevel $grade,
        QuestionType $type,
        string $stem,
        bool $publish,
    ): string {
        $questions = static::getContainer()->get(QuestionManager::class);
        $hasher = static::getContainer()->get(InvitationCodeDigestHasher::class);
        self::assertInstanceOf(QuestionManager::class, $questions);
        self::assertInstanceOf(InvitationCodeDigestHasher::class, $hasher);
        $scope = $institution instanceof Institution ? QuestionScope::Institution : QuestionScope::Platform;
        $options = [
            ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Bir'), 'position' => 1],
            ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Iki'), 'position' => 2],
        ];
        $answer = ['correctStableKey' => 'opt_b'];
        if (QuestionType::MultipleChoice === $type) {
            $options[] = ['stableKey' => 'opt_c', 'content' => QuestionContentDocument::paragraph('Uc'), 'position' => 3];
            $answer = ['correctStableKeys' => ['opt_a', 'opt_c']];
        }
        $question = $questions->createDraftQuestion(
            $author,
            $scope,
            $institution,
            $subject,
            $grade,
            $type,
            QuestionContentDocument::paragraph($stem),
            null,
            $options,
            $answer,
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.substr(md5($stem), 0, 12),
        );
        if ($publish) {
            $questions->submitForReview($question, $author, 'submit_q_'.substr(md5($stem), 0, 12));
            $reloaded = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $reloaded);
            $question = $reloaded->find(Question::class, $question->getId());
            self::assertInstanceOf(Question::class, $question);
            $questions->publish($question, $publisher, 'pub_q_'.substr(md5($stem), 0, 12));
        }

        return $hasher->workspaceReference('question', $question->getId());
    }

    private function referenceFromPath(string $uri): string
    {
        if (1 === preg_match('#/kurum/testler/([0-9a-f]{20})$#', $uri, $matches)) {
            return $matches[1];
        }
        self::fail('Assessment reference was not rendered.');
    }

    private function csrf(KernelBrowser $client, string $intention): string
    {
        $session = $client->getRequest()->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($client->getRequest());
        try {
            $tokens = $client->getContainer()->get('security.csrf.token_manager');
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);
            $value = $tokens->getToken($intention)->getValue();
            $session->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }

    private function assessmentCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM assessments');
    }

    private function deliveryCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM assessment_deliveries');
    }

    private function auditCount(string $action): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', [$action]);
    }

    private function assessmentStatus(string $title): string
    {
        return (string) $this->scalar(
            'SELECT a.status FROM assessments a INNER JOIN assessment_revisions r ON r.id = a.current_revision_id WHERE r.title = ?',
            [$title],
        );
    }

    private function assessmentScope(string $title): string
    {
        return (string) $this->scalar(
            'SELECT a.scope FROM assessments a INNER JOIN assessment_revisions r ON r.id = a.current_revision_id WHERE r.title = ?',
            [$title],
        );
    }

    private function assessmentInstitution(string $title): string
    {
        return (string) $this->scalar(
            'SELECT i.name FROM assessments a INNER JOIN assessment_revisions r ON r.id = a.current_revision_id INNER JOIN institutions i ON i.id = a.institution_id WHERE r.title = ?',
            [$title],
        );
    }

    private function itemValue(string $column): string
    {
        return (string) $this->scalar('SELECT '.$column.' FROM assessment_items LIMIT 1');
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params = []): int|string
    {
        $value = $this->withKernel(static function () use ($sql, $params): mixed {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchOne($sql, $params);
        });

        return \is_int($value) || \is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function latestAudit(string $action): array
    {
        $raw = $this->scalar(
            'SELECT metadata FROM security_audit_events WHERE action = ? ORDER BY occurred_at DESC LIMIT 1',
            [$action],
        );
        $decoded = json_decode((string) $raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function institutionId(string $name): string
    {
        $id = $this->withKernel(fn (): string => $this->institution($name)->getId()->toRfc4122());

        return \is_string($id) ? $id : '';
    }

    private function openInstitution(string $ownerEmail, string $name): void
    {
        $this->withKernel(function () use ($ownerEmail, $name): void {
            $creator = static::getContainer()->get(InstitutionCreator::class);
            self::assertInstanceOf(InstitutionCreator::class, $creator);
            $creator->create($this->user('quiz-sa@example.com'), $this->user($ownerEmail), $name, InstitutionType::School, 'setup');
        });
        $this->withKernel(function () use ($name): void {
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->activate($this->institution($name), $this->user('quiz-sa@example.com'), 'activate');
        });
    }

    private function addMember(string $actorEmail, string $subjectEmail, InstitutionMembershipRole $role, string $institutionName): void
    {
        $this->withKernel(function () use ($actorEmail, $subjectEmail, $role, $institutionName): void {
            $manager = static::getContainer()->get(InstitutionMembershipManager::class);
            self::assertInstanceOf(InstitutionMembershipManager::class, $manager);
            $manager->addMember($this->institution($institutionName), $this->user($actorEmail), $this->user($subjectEmail), $role, 'add_member');
        });
    }

    private function institution(string $name): Institution
    {
        $repo = static::getContainer()->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $repo);
        $institution = $repo->findOneBy(['name' => $name]);
        self::assertInstanceOf(Institution::class, $institution);

        return $institution;
    }

    private function user(string $email): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function withKernel(callable $callback): mixed
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        try {
            return $callback();
        } finally {
            self::ensureKernelShutdown();
        }
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function createActive(string $email, UserRole $role, string $first = 'Ayşe', string $last = 'Yılmaz'): void
    {
        $this->withKernel(static function () use ($email, $role, $first, $last): void {
            $factory = static::getContainer()->get(UserFactory::class);
            $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
            $users = static::getContainer()->get(UserRepository::class);
            self::assertInstanceOf(UserFactory::class, $factory);
            self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
            self::assertInstanceOf(UserRepository::class, $users);
            $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
            $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
            if (UserStatus::PendingVerification === $user->getStatus()) {
                $lifecycle->markEmailVerifiedAndActivate($user);
            }
            if ($initial !== $role) {
                $user->addGlobalRole($role);
                $users->save($user);
            }
        });
    }

    private function purge(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
                $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
            }
            QuestionBankDbCleanup::deleteTables($connection, [
                'academic_year_student_enrollment_guards',
                'classroom_student_enrollments',
                'classroom_teacher_active_guards',
                'classroom_homeroom_guards',
                'classroom_teacher_assignments',
                'classrooms',
                'institution_active_academic_year_guards',
                'academic_years',
                'curriculum_learning_outcomes',
                'curriculum_topics',
                'curriculum_units',
                'curriculum_programs',
                'subjects',
                'institution_memberships',
                'institutions',
                'student_profiles',
                'security_audit_events',
                'users',
            ]);
            self::ensureKernelShutdown();
        } catch (\Throwable) {
        }
    }
}
