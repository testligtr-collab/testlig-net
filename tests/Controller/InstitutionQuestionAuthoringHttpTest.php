<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
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
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\InstitutionRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\InstitutionWorkspaceQuery;
use App\Service\InvitationCodeDigestHasher;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class InstitutionQuestionAuthoringHttpTest extends WebTestCase
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

    public function testOwnerCreatesAndDifferentManagerPublishes(): void
    {
        $this->bootPeople();
        $refs = $this->seedCurriculum();
        $owner = $this->browser();
        $this->login($owner, 'q-owner@example.com');
        $before = $this->countTable('questions');
        $created = $this->auditCount('question_created');
        $published = $this->auditCount('question_published');
        $crawler = $owner->request('GET', '/kurum/sorular/yeni?ders='.$refs['subject'].'&sinif=1');
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->countTable('questions'));
        $page = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Toplama kazanimi', $page);
        self::assertStringNotContainsString('Taslak kazanimi', $page);
        $values = $crawler->selectButton('Taslağı oluştur')->form()->getPhpValues();
        $owner->request('POST', '/kurum/sorular/yeni', array_merge($values, [
            'stem' => 'Kurum toplama',
            'option_1' => 'Bir',
            'option_2' => 'Iki',
            'correct' => '2',
            'outcome_ref' => $refs['outcome'],
            'institution_id' => $this->institutionId('Bora Koleji'),
        ]));
        self::assertResponseRedirects();
        $location = (string) $owner->getResponse()->headers->get('Location');
        self::assertSame($before + 1, $this->countTable('questions'));
        self::assertSame($created + 1, $this->auditCount('question_created'));
        self::assertSame('institution', $this->questionColumn('scope'));
        self::assertSame('Ada Koleji', $this->questionInstitution());
        $owner->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Doğru seçenek', (string) $owner->getResponse()->getContent());
        $owner->submit($owner->getCrawler()->selectButton('İncelemeye gönder')->form());
        self::assertResponseRedirects();
        $owner->request('POST', $location.'/yayinla', ['_token' => $this->csrf($owner, 'institution_question_publish')]);
        self::assertResponseRedirects();
        self::assertSame('in_review', $this->questionColumn('status'));
        self::assertSame($published, $this->auditCount('question_published'));

        $manager = $this->browser();
        $this->login($manager, 'q-manager@example.com');
        $manager->request('GET', $location);
        self::assertStringContainsString('Doğru seçenek', (string) $manager->getResponse()->getContent());
        $manager->submit($manager->getCrawler()->selectButton('Yayınla')->form());
        self::assertResponseRedirects();
        self::assertSame('published', $this->questionColumn('status'));
        self::assertSame($published + 1, $this->auditCount('question_published'));

        $picker = $this->browser();
        $this->login($picker, 'q-owner@example.com');
        $picker->request('GET', '/kurum/testler/yeni?sinif=1');
        $list = (string) $picker->getResponse()->getContent();
        self::assertStringContainsString('Kurum toplama', $list);
        self::assertStringNotContainsString('correctStableKey', $list);
        self::assertStringNotContainsString('q-owner@example.com', $list);
        self::assertStringNotContainsString($this->adaQuestionId(), $list);
    }

    public function testRejectedWritesLeaveRecordsUnchanged(): void
    {
        $this->bootPeople();
        $refs = $this->seedCurriculum();
        $foreign = $this->seedForeignQuestion($refs['outcome_id']);
        $before = $this->countTable('questions');
        $keys = $this->countTable('question_answer_keys');
        $created = $this->auditCount('question_created');
        $published = $this->auditCount('question_published');

        foreach ([
            ['q-teacher@example.com', 403],
            ['q-student@example.com', 403],
            ['q-staff@example.com', 403],
            ['q-global-teacher@example.com', 403],
        ] as [$email, $status]) {
            $client = $this->browser();
            $this->login($client, $email);
            $client->request('GET', '/kurum/sorular/yeni');
            self::assertResponseStatusCodeSame($status);
        }
        foreach (['q-teacher@example.com', 'q-student@example.com', 'q-staff@example.com'] as $email) {
            $client = $this->browser();
            $this->login($client, $email);
            $client->request('GET', '/kurum/sorular/yeni');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/kurum/sorular/yeni', $this->validCreatePayload($client, $refs));
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/kurum/sorular/'.$foreign['platform'].'/incelemeye-gonder', [
                '_token' => $this->csrf($client, 'institution_question_submit'),
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/kurum/sorular/'.$foreign['platform'].'/yayinla', [
                '_token' => $this->csrf($client, 'institution_question_publish'),
            ]);
            self::assertResponseStatusCodeSame(403);
        }

        $owner = $this->browser();
        $this->login($owner, 'q-owner@example.com');
        foreach ([$foreign['platform'], $foreign['foreign'], 'not-a-reference'] as $reference) {
            $owner->request('GET', '/kurum/sorular/'.$reference);
            self::assertResponseStatusCodeSame(404);
        }
        foreach ([$foreign['platform'], $foreign['foreign']] as $reference) {
            $owner->request('POST', '/kurum/sorular/'.$reference.'/incelemeye-gonder', [
                '_token' => $this->csrf($owner, 'institution_question_submit'),
            ]);
            self::assertResponseStatusCodeSame(404);
            $owner->request('POST', '/kurum/sorular/'.$reference.'/yayinla', [
                '_token' => $this->csrf($owner, 'institution_question_publish'),
            ]);
            self::assertResponseStatusCodeSame(404);
        }
        $owner->request('POST', '/kurum/sorular/yeni', ['_token' => 'bad']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $owner->request('GET', '/kurum/sorular/yeni?ders='.$refs['subject'].'&sinif=1');
        $values = $crawler->selectButton('Taslağı oluştur')->form()->getPhpValues();
        $owner->request('POST', '/kurum/sorular/yeni', array_merge($values, [
            'stem' => 'Eksik',
            'option_1' => 'Bir',
            'correct' => '1',
            'outcome_ref' => $refs['outcome'],
        ]));
        self::assertResponseIsSuccessful();
        $owner->request('POST', '/kurum/sorular/yeni', array_merge($values, [
            'stem' => 'Isaretli <b>soru</b>',
            'option_1' => 'Bir',
            'option_2' => 'Iki',
            'correct' => '1',
            'outcome_ref' => $refs['outcome'],
        ]));
        self::assertResponseIsSuccessful();
        $owner->request('POST', '/kurum/sorular/yeni', array_merge($values, [
            'stem' => 'Yanlis kazanım',
            'option_1' => 'Bir',
            'option_2' => 'Iki',
            'correct' => '1',
            'outcome_ref' => $refs['science_outcome'],
        ]));
        self::assertResponseStatusCodeSame(404);
        $owner->request('GET', '/kurum/sorular/yeni?ders='.$refs['subject'].'&sinif=2');
        self::assertStringContainsString('Bu sınıf ve ders için kullanılabilir kazanım yok.', (string) $owner->getResponse()->getContent());

        self::assertSame($before, $this->countTable('questions'));
        self::assertSame($keys, $this->countTable('question_answer_keys'));
        self::assertSame($created, $this->auditCount('question_created'));
        self::assertSame($published, $this->auditCount('question_published'));
    }

    public function testInvalidSubjectAndGradeDoNotUseDefaults(): void
    {
        $this->bootPeople();
        $refs = $this->seedCurriculum();
        $owner = $this->browser();
        $this->login($owner, 'q-owner@example.com');
        $before = $this->recordCounts();
        $owner->request('GET', '/kurum/sorular/yeni');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Fen kazanimi', (string) $owner->getResponse()->getContent());
        self::assertGreaterThan(0, $owner->getCrawler()->selectButton('Taslağı oluştur')->count());
        $payload = $this->validCreatePayload($owner, $refs);
        $payload['subject_ref'] = $refs['science_subject'];
        $payload['outcome_ref'] = $refs['science_outcome'];
        foreach ([
            ['subject_ref' => ''],
            ['subject_ref' => 'not-a-subject'],
            ['subject_ref' => str_repeat('a', 20)],
            ['grade' => ''],
            ['grade' => '1abc'],
            ['grade' => '13'],
        ] as $override) {
            $owner->request('POST', '/kurum/sorular/yeni', array_merge($payload, $override));
            self::assertResponseIsSuccessful();
        }

        self::assertSame($before, $this->recordCounts());
    }

    public function testOutcomeListQueryCountDoesNotGrow(): void
    {
        $this->bootPeople();
        $this->seedMeasuredOutcomes(2, GradeLevel::Grade1);
        $small = $this->outcomeQueries(GradeLevel::Grade1);
        $this->seedMeasuredOutcomes(6, GradeLevel::Grade2);
        $large = $this->outcomeQueries(GradeLevel::Grade2);

        self::assertGreaterThan(0, $small);
        self::assertSame($small, $large);
    }

    private function outcomeQueries(GradeLevel $grade): int
    {
        return $this->withKernel(static function () use ($grade): int {
            $holder = static::getContainer()->get('doctrine.debug_data_holder');
            $query = static::getContainer()->get(InstitutionWorkspaceQuery::class);
            $subjects = static::getContainer()->get(SubjectRepository::class);
            self::assertInstanceOf(DebugDataHolder::class, $holder);
            self::assertInstanceOf(InstitutionWorkspaceQuery::class, $query);
            self::assertInstanceOf(SubjectRepository::class, $subjects);
            $subject = $subjects->findOneByCode('math');
            self::assertInstanceOf(Subject::class, $subject);
            $holder->reset();
            $query->publishedOutcomeChoices($subject, $grade);
            $count = 0;
            foreach ($holder->getData() as $queries) {
                if (!\is_array($queries)) {
                    continue;
                }
                foreach ($queries as $item) {
                    $statement = \is_array($item) ? ($item['sql'] ?? null) : null;
                    if (\is_string($statement) && '' !== trim($statement)) {
                        ++$count;
                    }
                }
            }

            return $count;
        });
    }

    /**
     * @return array{subject: string, science_subject: string, outcome: string, outcome_id: string, science_outcome: string}
     */
    private function seedCurriculum(): array
    {
        /** @var array{subject: string, science_subject: string, outcome: string, outcome_id: string, science_outcome: string} $refs */
        $refs = $this->withKernel(function (): array {
            $actor = $this->user('q-sa@example.com');
            $math = $this->subject($actor, 'math', 'Matematik');
            $science = $this->subject($actor, 'science', 'Fen');
            $outcome = $this->publishedOutcome($actor, $math, GradeLevel::Grade1, 'math', 'Toplama kazanimi');
            $this->publishedOutcome($actor, $math, GradeLevel::Grade1, 'math_draft', 'Taslak kazanimi', false);
            $other = $this->publishedOutcome($actor, $science, GradeLevel::Grade1, 'science', 'Fen kazanimi');
            $hasher = static::getContainer()->get(InvitationCodeDigestHasher::class);
            self::assertInstanceOf(InvitationCodeDigestHasher::class, $hasher);

            return [
                'subject' => $hasher->workspaceReference('subject', $math->getId()),
                'science_subject' => $hasher->workspaceReference('subject', $science->getId()),
                'outcome' => $hasher->workspaceReference('learning_outcome', $outcome->getId()),
                'outcome_id' => $outcome->getId()->toRfc4122(),
                'science_outcome' => $hasher->workspaceReference('learning_outcome', $other->getId()),
            ];
        });

        return $refs;
    }

    private function seedMeasuredOutcomes(int $count, GradeLevel $grade): void
    {
        $this->withKernel(function () use ($count, $grade): void {
            $actor = $this->user('q-sa@example.com');
            $math = $this->subject($actor, 'math', 'Matematik');
            $programs = static::getContainer()->get(CurriculumProgramManager::class);
            $units = static::getContainer()->get(CurriculumUnitManager::class);
            $topics = static::getContainer()->get(CurriculumTopicManager::class);
            $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
            self::assertInstanceOf(CurriculumProgramManager::class, $programs);
            self::assertInstanceOf(CurriculumUnitManager::class, $units);
            self::assertInstanceOf(CurriculumTopicManager::class, $topics);
            self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);
            $program = $programs->createDraft($math, $actor, $grade, 'prog_'.$count, 'Program '.$count, '1.0', 'prog_'.$count);
            $unit = $units->create($program, $actor, 'u_'.$count, 'Unite', 1, 'unit_'.$count);
            $topic = $topics->createRoot($unit, $actor, 't_'.$count, 'Konu', 1, 'topic_'.$count);
            for ($index = 1; $index <= $count; ++$index) {
                $outcomes->create($topic, $actor, 'lo_'.$count.'_'.$index, 'Kazanim '.$index, $index, 'outcome_'.$count.'_'.$index);
            }
            $programs->publish($program, $actor, 'publish_'.$count);
        });
    }

    /**
     * @return array{platform: string, foreign: string}
     */
    private function seedForeignQuestion(string $outcomeId): array
    {
        /** @var array{platform: string, foreign: string} $refs */
        $refs = $this->withKernel(function () use ($outcomeId): array {
            $questions = static::getContainer()->get(QuestionManager::class);
            $outcomes = static::getContainer()->get(CurriculumLearningOutcomeRepository::class);
            $hasher = static::getContainer()->get(InvitationCodeDigestHasher::class);
            self::assertInstanceOf(QuestionManager::class, $questions);
            self::assertInstanceOf(CurriculumLearningOutcomeRepository::class, $outcomes);
            self::assertInstanceOf(InvitationCodeDigestHasher::class, $hasher);
            $outcome = $outcomes->findOneById(\Symfony\Component\Uid\Uuid::fromString($outcomeId));
            self::assertInstanceOf(CurriculumLearningOutcome::class, $outcome);
            $options = [
                ['stableKey' => 'opt_1', 'content' => QuestionContentDocument::paragraph('Bir'), 'position' => 1],
                ['stableKey' => 'opt_2', 'content' => QuestionContentDocument::paragraph('Iki'), 'position' => 2],
            ];
            $answer = ['correctStableKey' => 'opt_1'];
            $subject = $outcome->getCurriculumProgram()->getSubject();
            $platform = $questions->createDraftQuestion($this->user('q-head@example.com'), QuestionScope::Platform, null, $subject, GradeLevel::Grade1, QuestionType::SingleChoice, QuestionContentDocument::paragraph('Platform sorusu'), null, $options, $answer, [['learningOutcome' => $outcome, 'isPrimary' => true]], QuestionDifficulty::Medium, 'seed_platform');
            $foreign = $questions->createDraftQuestion($this->user('q-bora@example.com'), QuestionScope::Institution, $this->institution('Bora Koleji'), $subject, GradeLevel::Grade1, QuestionType::SingleChoice, QuestionContentDocument::paragraph('Yabanci soru'), null, $options, $answer, [['learningOutcome' => $outcome, 'isPrimary' => true]], QuestionDifficulty::Medium, 'seed_foreign');

            return [
                'platform' => $hasher->workspaceReference('question', $platform->getId()),
                'foreign' => $hasher->workspaceReference('question', $foreign->getId()),
            ];
        });

        return $refs;
    }

    private function bootPeople(): void
    {
        $this->createActive('q-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('q-head@example.com', UserRole::HeadTeacher);
        $this->createActive('q-owner@example.com', UserRole::User, 'Ada', 'Yilmaz');
        $this->createActive('q-manager@example.com', UserRole::User, 'Mert', 'Kaya');
        $this->createActive('q-teacher@example.com', UserRole::Teacher, 'Ece', 'Ozturk');
        $this->createActive('q-staff@example.com', UserRole::User, 'Selin', 'Arslan');
        $this->createActive('q-student@example.com', UserRole::Student, 'Can', 'Aydin');
        $this->createActive('q-global-teacher@example.com', UserRole::Teacher);
        $this->createActive('q-bora@example.com', UserRole::User, 'Bora', 'Demir');
        $this->openInstitution('q-owner@example.com', 'Ada Koleji');
        $this->openInstitution('q-bora@example.com', 'Bora Koleji');
        $this->addMember('q-owner@example.com', 'q-manager@example.com', InstitutionMembershipRole::Manager, 'Ada Koleji');
        $this->addMember('q-owner@example.com', 'q-teacher@example.com', InstitutionMembershipRole::Teacher, 'Ada Koleji');
        $this->addMember('q-owner@example.com', 'q-staff@example.com', InstitutionMembershipRole::Staff, 'Ada Koleji');
        $this->addMember('q-owner@example.com', 'q-student@example.com', InstitutionMembershipRole::Student, 'Ada Koleji');
    }

    private function publishedOutcome(User $actor, Subject $subject, GradeLevel $grade, string $suffix, string $description, bool $publish = true): CurriculumLearningOutcome
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
        $outcome = $outcomes->create($topic, $actor, 'lo_'.$suffix, $description, 1, 'outcome_'.$suffix);
        if ($publish) {
            $programs->publish($program, $actor, 'publish_'.$suffix);
        }

        return $outcome;
    }

    private function subject(User $actor, string $code, string $name): Subject
    {
        $subjects = static::getContainer()->get(SubjectManager::class);
        $repo = static::getContainer()->get(SubjectRepository::class);
        self::assertInstanceOf(SubjectManager::class, $subjects);
        self::assertInstanceOf(SubjectRepository::class, $repo);
        if (!$repo->findOneByCode($code) instanceof Subject) {
            $subjects->create($actor, $code, $name, 'subject');
        }
        $subject = $repo->findOneByCode($code);
        self::assertInstanceOf(Subject::class, $subject);

        return $subject;
    }

    private function createActive(string $email, UserRole $role, string $first = 'Ayse', string $last = 'Yilmaz'): void
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

    /**
     * @param array{subject: string, outcome: string, science_outcome: string} $refs
     *
     * @return array<string, string>
     */
    private function validCreatePayload(KernelBrowser $client, array $refs): array
    {
        return [
            '_token' => $this->csrf($client, 'institution_question_create'),
            'subject_ref' => $refs['subject'],
            'grade' => '1',
            'stem' => 'Gecerli soru',
            'option_1' => 'Bir',
            'option_2' => 'Iki',
            'correct' => '1',
            'outcome_ref' => $refs['outcome'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function recordCounts(): array
    {
        return [
            'questions' => $this->countTable('questions'),
            'revisions' => $this->countTable('question_revisions'),
            'options' => $this->countTable('question_revision_options'),
            'keys' => $this->countTable('question_answer_keys'),
            'created' => $this->auditCount('question_created'),
            'submitted' => $this->auditCount('question_submitted_for_review'),
            'published' => $this->auditCount('question_published'),
        ];
    }

    private function countTable(string $table): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM '.$table);
    }

    private function auditCount(string $action): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', [$action]);
    }

    private function adaQuestionId(): string
    {
        $id = $this->withKernel(static function (): string {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $question = $em->createQuery('SELECT q FROM '.Question::class.' q JOIN q.institution i WHERE i.name = :name')
                ->setParameter('name', 'Ada Koleji')
                ->getOneOrNullResult();
            self::assertInstanceOf(Question::class, $question);

            return $question->getId()->toRfc4122();
        });

        return \is_string($id) ? $id : '';
    }

    private function questionColumn(string $column): string
    {
        return (string) $this->scalar(
            'SELECT q.'.$column.' FROM questions q INNER JOIN institutions i ON i.id = q.institution_id WHERE i.name = ?',
            ['Ada Koleji'],
        );
    }

    private function questionInstitution(): string
    {
        return (string) $this->scalar(
            'SELECT i.name FROM questions q INNER JOIN institutions i ON i.id = q.institution_id WHERE q.scope = ?',
            ['institution'],
        );
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
            $creator->create($this->user('q-sa@example.com'), $this->user($ownerEmail), $name, InstitutionType::School, 'setup');
        });
        $this->withKernel(function () use ($name): void {
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->activate($this->institution($name), $this->user('q-sa@example.com'), 'activate');
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
        $institution = $repo->findOneByName($name);
        self::assertInstanceOf(Institution::class, $institution);

        return $institution;
    }

    private function user(string $email): User
    {
        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function findUser(string $email): ?User
    {
        $repo = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repo);

        return $repo->findOneByEmail($email);
    }

    private function withKernel(\Closure $callback): mixed
    {
        $kernel = static::createKernel();
        $kernel->boot();
        try {
            return $callback();
        } finally {
            $kernel->shutdown();
            static::ensureKernelShutdown();
        }
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
