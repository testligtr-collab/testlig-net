<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Enum\QuestionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Question\Import\QuestionCsvParser;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class AdminQuestionImportControllerTest extends WebTestCase
{
    public function testAnonymousAndDeniedRolesCannotImport(): void
    {
        $client = $this->newClient();
        $client->request('GET', '/yonetim/sorular/ice-aktar');
        self::assertResponseRedirects('/giris');

        foreach ([UserRole::Student, UserRole::Parent, UserRole::InstitutionManager, UserRole::Moderator] as $role) {
            $email = 'qcsv-deny-'.$role->value.'@example.com';
            $this->createPrivileged($email, $role);
            $client = $this->newClient();
            $this->login($client, $email);
            $client->request('GET', '/yonetim/sorular/ice-aktar');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/yonetim/sorular');
            if (UserRole::Moderator === $role) {
                self::assertResponseIsSuccessful();
                self::assertStringNotContainsString('CSV ile içe aktar', (string) $client->getResponse()->getContent());
            }
        }
    }

    public function testTeacherImportsDraftsAndRepeatingTheFileSkips(): void
    {
        $ids = $this->seedCurriculum('qcsvok');
        $teacher = $this->createPrivileged('qcsv-teacher@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'qcsv-teacher@example.com');
        $client->request('GET', '/yonetim/sorular');
        self::assertStringContainsString('CSV ile içe aktar', (string) $client->getResponse()->getContent());

        $before = $this->questionCount();
        $client->request('GET', '/yonetim/sorular/ice-aktar');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('cache-control'));
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('cache-control'));
        self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('x-robots-tag'));
        self::assertResponseHeaderSame('referrer-policy', 'no-referrer');
        self::assertSame($before, $this->questionCount());
        $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');

        $client->request('GET', '/yonetim/sorular/ice-aktar/sablon');
        $template = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $template);
        self::assertStringContainsString('ÖRNEK SATIR', $template);

        $code = $this->code();
        $stem = 'Kare dort kenar midir?';
        $client->request('POST', '/yonetim/sorular/ice-aktar', ['_token' => 'invalid'], [
            'csv' => $this->upload($this->csv([[$code, '1', $ids['subject_code'], $ids['outcome_code'], $stem, 'Evet', 'Hayir', '', '', 'A', '']])),
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->questionCount());

        $client->request('POST', '/yonetim/sorular/ice-aktar', ['_token' => $token], [
            'csv' => $this->upload($this->csv([[$code, '1', $ids['subject_code'], $ids['outcome_code'], $stem, 'Evet', 'Hayir', '', '', 'A', '']])),
        ]);
        self::assertResponseRedirects();
        self::assertSame($before, $this->questionCount());
        $client->followRedirect();
        self::assertStringContainsString('Oluşturulacak', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Doğru seçenek', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('A', (string) $client->getResponse()->getContent());

        $client->request('POST', $client->getRequest()->getPathInfo().'/uygula', [
            '_token' => (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value'),
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Onay kutusunu işaretleyin.', (string) $client->getResponse()->getContent());
        self::assertSame($before, $this->questionCount());

        $client->submit($client->getCrawler()->selectButton('Taslak olarak oluştur')->form([
            'confirm' => '1',
        ]));
        self::assertResponseRedirects('/yonetim/sorular');
        $client->followRedirect();
        self::assertStringContainsString('1 soru taslak olarak oluşturuldu.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Taslak', (string) $client->getResponse()->getContent());
        self::assertSame($before + 1, $this->questionCount());

        $connection = $this->connection();
        $questionId = Uuid::fromString($this->uuid($code))->toRfc4122();
        $owner = $connection->fetchOne('SELECT created_by_id FROM questions WHERE code = ?', [$code]);
        self::assertSame($teacher->getId()->toRfc4122(), $this->uuidString($owner));
        $status = $connection->fetchOne('SELECT status FROM questions WHERE code = ?', [$code]);
        self::assertSame(QuestionStatus::Draft->value, $status);
        $payload = (string) $connection->fetchOne('SELECT answer_payload FROM question_answer_keys');
        self::assertStringContainsString('correctStableKey', $payload);
        $audit = (string) $connection->fetchOne("SELECT metadata FROM security_audit_events WHERE action = 'questions_bulk_imported' ORDER BY occurred_at DESC LIMIT 1");
        self::assertStringNotContainsString($stem, $audit);
        self::assertStringNotContainsString('Evet', $audit);
        self::assertStringContainsString('created_count', $audit);

        $client->request('GET', '/yonetim/sorular/'.$questionId);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('>Yayınla<', (string) $client->getResponse()->getContent());
        $client->request('POST', '/yonetim/sorular/'.$questionId.'/yayinla', [
            '_token' => 'invalid',
            'expected_revision' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(QuestionStatus::Draft->value, $connection->fetchOne('SELECT status FROM questions WHERE code = ?', [$code]));

        $client->request('GET', '/yonetim/sorular/ice-aktar');
        $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/sorular/ice-aktar', ['_token' => $token], [
            'csv' => $this->upload($this->csv([[$code, '1', $ids['subject_code'], $ids['outcome_code'], 'Baska metin', 'A', 'B', '', '', 'A', '']])),
        ]);
        $client->followRedirect();
        self::assertStringContainsString('Atlanacak', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Mevcut kayıt, güncellenmedi.', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Taslak olarak oluştur', (string) $client->getResponse()->getContent());
        self::assertSame($before + 1, $this->questionCount());

        $this->createPrivileged('qcsv-student@example.com', UserRole::Student);
        $student = $this->newClient();
        $this->login($student, 'qcsv-student@example.com');
        $student->request('GET', '/ogrenci/dersler');
        $student->followRedirect();
        self::assertResponseIsSuccessful();
        $studentHtml = (string) $student->getResponse()->getContent();
        self::assertStringNotContainsString($stem, $studentHtml);
        self::assertStringNotContainsString('correctStableKey', $studentHtml);
    }

    public function testInvalidRowBlocksApplyAndAConcurrentCodeRollsBack(): void
    {
        $ids = $this->seedCurriculum('qcsvbad');
        $this->createPrivileged('qcsv-bad@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'qcsv-bad@example.com');
        $client->request('GET', '/yonetim/sorular/ice-aktar');
        $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/sorular/ice-aktar', ['_token' => $token], [
            'csv' => $this->upload($this->csv([
                [$this->code(), '1', $ids['subject_code'], $ids['outcome_code'], 'Gecerli', 'A', 'B', '', '', 'A', ''],
                [$this->code(), '99', $ids['subject_code'], $ids['outcome_code'], 'Hatali', 'A', 'B', '', '', 'A', ''],
            ])),
        ]);
        $client->followRedirect();
        self::assertStringContainsString('Hatalı', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Sınıf 1 ile 12 arasında bir tam sayı olmalıdır.', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Taslak olarak oluştur', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->questionCount());

        $first = $this->code();
        $second = $this->code();
        $client->request('GET', '/yonetim/sorular/ice-aktar');
        $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/sorular/ice-aktar', ['_token' => $token], [
            'csv' => $this->upload($this->csv([
                [$first, '1', $ids['subject_code'], $ids['outcome_code'], 'Birinci', 'A', 'B', '', '', 'A', ''],
                [$second, '1', $ids['subject_code'], $ids['outcome_code'], 'Ikinci', 'A', 'B', '', '', 'A', ''],
            ])),
        ]);
        $client->followRedirect();
        $this->insertQuestion($client, $ids, $first, 'qcsv-bad@example.com');
        $client->submit($client->getCrawler()->selectButton('Taslak olarak oluştur')->form(['confirm' => '1']));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('geri alındı', (string) $client->getResponse()->getContent());
        $connection = $this->connection();
        self::assertFalse($connection->fetchOne('SELECT code FROM questions WHERE code = ?', [$second]));
        self::assertSame(1, $this->questionCount());
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM question_answer_keys'));
    }

    public function testAdminCanOpenImport(): void
    {
        $this->createPrivileged('qcsv-admin@example.com', UserRole::Admin);
        $client = $this->newClient();
        $this->login($client, 'qcsv-admin@example.com');
        $client->request('GET', '/yonetim/sorular/ice-aktar');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'CSV ile içe aktar');
    }

    /**
     * @param array{subject: string, outcome: string, subject_code: string, outcome_code: string} $ids
     */
    private function insertQuestion(KernelBrowser $client, array $ids, string $code, string $email): void
    {
        $container = $client->getContainer();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $actor = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $actor);
        /** @var QuestionManager $questions */
        $questions = $container->get(QuestionManager::class);
        $subjects = $container->get(SubjectRepository::class);
        $outcomes = $container->get(CurriculumLearningOutcomeRepository::class);
        self::assertInstanceOf(SubjectRepository::class, $subjects);
        self::assertInstanceOf(CurriculumLearningOutcomeRepository::class, $outcomes);
        $subject = $subjects->findOneByCode($ids['subject_code']);
        $outcome = $outcomes->findOneById(Uuid::fromString($ids['outcome']));
        self::assertNotNull($subject);
        self::assertNotNull($outcome);
        $questions->createDraftQuestion(
            $actor,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Onceden var'),
            null,
            [
                ['stableKey' => 'opt_1', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_2', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_1'],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            \App\Enum\QuestionDifficulty::Medium,
            'question_draft_saved',
            null,
            \App\Enum\QuestionSourceType::Original,
            null,
            Uuid::fromString($this->uuid($code)),
        );
    }

    /**
     * @return array{subject: string, outcome: string, subject_code: string, outcome_code: string}
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
        $subject = $subjects->create($sa, $prefix.'_s', 'Ders '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'create_p');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'create_t');
        $active = $outcomes->create($topic, $sa, $prefix.'_active', 'Aktif kazanım', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');
        $ids = [
            'subject' => $subject->getId()->toRfc4122(),
            'outcome' => $active->getId()->toRfc4122(),
            'subject_code' => $prefix.'_s',
            'outcome_code' => $prefix.'_active',
        ];
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

    private function questionCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM questions');
    }

    private function connection(): Connection
    {
        self::bootKernel();
        $connection = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $connection);

        return $connection->getConnection();
    }

    private function code(): string
    {
        return str_replace('-', '', Uuid::v7()->toRfc4122());
    }

    private function uuid(string $code): string
    {
        return substr($code, 0, 8).'-'.substr($code, 8, 4).'-'.substr($code, 12, 4).'-'.substr($code, 16, 4).'-'.substr($code, 20);
    }

    private function uuidString(mixed $value): string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        if (\is_string($value) && 16 === \strlen($value)) {
            return Uuid::fromBinary($value)->toRfc4122();
        }

        return (string) $value;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function csv(array $rows): string
    {
        $lines = [implode(',', QuestionCsvParser::HEADERS)];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines)."\n";
    }

    private function upload(string $csv): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        self::assertNotFalse($path);
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'sorular.csv', 'text/csv', null, true);
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
