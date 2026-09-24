<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\LearningContentDbCleanup;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminLearningContentRevisionEditorTest extends WebTestCase
{
    public function testXssEscapedInPreviewAndEdit(): void
    {
        $contentId = $this->createDraftContent('lc_xss', 'XSS Title', 'lc-admin-xss@example.com');
        $payload = '<script>alert(1)</script>';
        $this->forceStructuredContent($contentId, [
            'schemaVersion' => 1,
            'blocks' => [['type' => 'paragraph', 'text' => $payload]],
        ]);

        $client = $this->newClient();
        $this->login($client, 'lc-admin-xss@example.com');

        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision');
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertSame(0, $crawler->filter('script')->reduce(static fn ($node) => str_contains($node->text(), 'alert(1)'))->count());

        $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/taslak-gorunum');
        self::assertResponseIsSuccessful();
        $preview = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString('<script>alert(1)</script>', $preview);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $preview);
        self::assertStringNotContainsString('storageKey', $preview);
        self::assertStringNotContainsString('content_json', $preview);
        self::assertStringNotContainsString('structured_content', $preview);
    }

    public function testUnknownBlockTypeRejected(): void
    {
        $contentId = $this->createDraftContent('lc_unk', 'Unknown Type', 'lc-admin-unk@example.com');
        $client = $this->newClient();
        $this->login($client, 'lc-admin-unk@example.com');
        $token = $this->csrfFromEdit($client, $contentId);
        $revisionId = $this->currentRevisionId($contentId);

        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/save', [
            '_token' => $token,
            'expected_revision_id' => $revisionId,
            'blocks' => [
                ['type' => 'iframe', 'text' => 'evil'],
            ],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Desteklenmeyen blok türü');
    }

    public function testTooManyBlocksRejected(): void
    {
        $contentId = $this->createDraftContent('lc_lim', 'Limit Blocks', 'lc-admin-lim@example.com');
        $client = $this->newClient();
        $this->login($client, 'lc-admin-lim@example.com');
        $token = $this->csrfFromEdit($client, $contentId);
        $revisionId = $this->currentRevisionId($contentId);

        $blocks = [];
        for ($i = 0; $i < LearningContentDocument::MAX_BLOCKS + 1; ++$i) {
            $blocks[] = ['type' => 'paragraph', 'text' => 'Blok '.$i];
        }

        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/save', [
            '_token' => $token,
            'expected_revision_id' => $revisionId,
            'blocks' => $blocks,
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'max block');
    }

    public function testInvalidMathListAndCalloutRejected(): void
    {
        $contentId = $this->createDraftContent('lc_inv', 'Invalid Blocks', 'lc-admin-inv@example.com');
        $client = $this->newClient();
        $this->login($client, 'lc-admin-inv@example.com');
        $base = '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/save';

        $token = $this->csrfFromEdit($client, $contentId);
        $revisionId = $this->currentRevisionId($contentId);
        $client->request('POST', $base, [
            '_token' => $token,
            'expected_revision_id' => $revisionId,
            'blocks' => [['type' => 'math', 'latex' => '']],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Matematik');

        $token = $this->csrfFromEdit($client, $contentId);
        $revisionId = $this->currentRevisionId($contentId);
        $client->request('POST', $base, [
            '_token' => $token,
            'expected_revision_id' => $revisionId,
            'blocks' => [['type' => 'list', 'items' => '']],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Liste');

        $token = $this->csrfFromEdit($client, $contentId);
        $revisionId = $this->currentRevisionId($contentId);
        $client->request('POST', $base, [
            '_token' => $token,
            'expected_revision_id' => $revisionId,
            'blocks' => [['type' => 'callout', 'variant' => 'danger', 'callout_text' => 'x']],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'variant');
    }

    public function testStaleExpectedRevisionIdConflicts(): void
    {
        $contentId = $this->createDraftContent('lc_stale', 'Stale Rev', 'lc-admin-stale@example.com');
        $client = $this->newClient();
        $this->login($client, 'lc-admin-stale@example.com');
        $token = $this->csrfFromEdit($client, $contentId);

        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/save', [
            '_token' => $token,
            'expected_revision_id' => '00000000-0000-7000-8000-000000000099',
            'blocks' => [['type' => 'paragraph', 'text' => 'Should not save']],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Çakışma');

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $content = $em->find(LearningContent::class, $contentId);
        self::assertInstanceOf(LearningContent::class, $content);
        $revision = $content->getCurrentRevision();
        self::assertInstanceOf(LearningContentRevision::class, $revision);
        $text = $revision->getStructuredContent()['blocks'][0]['text'] ?? null;
        self::assertNotSame('Should not save', $text);
    }

    public function testSealedCannotUpdateRequiresClone(): void
    {
        $contentId = $this->createDraftContent('lc_seal', 'Sealed', 'lc-admin-seal@example.com');
        $this->sealCurrent($contentId, 'lc-admin-seal@example.com');

        $client = $this->newClient();
        $this->login($client, 'lc-admin-seal@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Mühürlü sürüm');
        $token = $crawler->filter('form input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $revisionId = $this->currentRevisionId($contentId);

        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/save', [
            '_token' => $token,
            'expected_revision_id' => $revisionId,
            'blocks' => [['type' => 'paragraph', 'text' => 'No overwrite']],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Mühürlü');

        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision');
        $token = $crawler->filter('form[action*="clone"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/clone', [
            '_token' => $token,
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'taslak');

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $created = (int) $em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\SecurityAuditEvent::class, 'e')
            ->andWhere('e.action = :action')
            ->setParameter('action', SecurityAuditAction::LearningContentRevisionCreated)
            ->getQuery()
            ->getSingleScalarResult();
        self::assertGreaterThan(0, $created);
    }

    public function testSaveCreatesAuditAndPreviewAcl(): void
    {
        $contentId = $this->createDraftContent('lc_aud', 'Audit Save', 'lc-admin-aud@example.com');
        $otherTeacherContent = $this->createDraftContent('lc_oth', 'Other Teacher', 'lc-teacher-own@example.com', UserRole::Teacher);

        $client = $this->newClient();
        $this->login($client, 'lc-admin-aud@example.com');
        $token = $this->csrfFromEdit($client, $contentId);
        $revisionId = $this->currentRevisionId($contentId);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/save', [
            '_token' => $token,
            'expected_revision_id' => $revisionId,
            'blocks' => [
                ['type' => 'heading', 'level' => '2', 'text' => 'Baslik'],
                ['type' => 'paragraph', 'text' => 'Guvenli metin'],
            ],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Sürüm kaydedildi');

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $updated = (int) $em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\SecurityAuditEvent::class, 'e')
            ->andWhere('e.action = :action')
            ->setParameter('action', SecurityAuditAction::LearningContentRevisionUpdated)
            ->getQuery()
            ->getSingleScalarResult();
        self::assertGreaterThan(0, $updated);

        $this->createPrivileged('lc-student-prev@example.com', UserRole::Student);
        $client = $this->newClient();
        $this->login($client, 'lc-student-prev@example.com');
        $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/taslak-gorunum');
        self::assertResponseStatusCodeSame(403);

        $this->createPrivileged('lc-teacher-peek@example.com', UserRole::Teacher);
        $client = $this->newClient();
        $this->login($client, 'lc-teacher-peek@example.com');
        $client->request('GET', '/yonetim/icerikler/'.$otherTeacherContent->toRfc4122().'/revision/taslak-gorunum');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCsrfRejected(): void
    {
        $contentId = $this->createDraftContent('lc_csrf', 'CSRF', 'lc-admin-csrf@example.com');
        $client = $this->newClient();
        $this->login($client, 'lc-admin-csrf@example.com');
        $revisionId = $this->currentRevisionId($contentId);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision/save', [
            '_token' => 'invalid',
            'expected_revision_id' => $revisionId,
            'blocks' => [['type' => 'paragraph', 'text' => 'x']],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testHtmlDoesNotLeakStorageKeyOrRawJsonKeys(): void
    {
        $contentId = $this->createDraftContent('lc_leak', 'No Leak', 'lc-admin-leak@example.com');
        $client = $this->newClient();
        $this->login($client, 'lc-admin-leak@example.com');
        foreach (['/revision', '/revision/taslak-gorunum'] as $suffix) {
            $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122().$suffix);
            self::assertResponseIsSuccessful();
            $html = $client->getResponse()->getContent() ?: '';
            self::assertStringNotContainsString('storageKey', $html);
            self::assertStringNotContainsString('content_json', $html);
            self::assertStringNotContainsString('"schemaVersion"', $html);
            self::assertStringNotContainsString('structured_content', $html);
        }
    }

    private function createDraftContent(string $code, string $title, string $email, UserRole $role = UserRole::Admin): Uuid
    {
        [$subject, $lo] = $this->seedCurriculum($code.'_cur');
        $this->createPrivileged($email, $role);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var LearningContentManager $manager */
        $manager = static::getContainer()->get(LearningContentManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $actor = $users->findOneByNormalizedEmail(mb_strtolower($email));
        self::assertInstanceOf(User::class, $actor);
        $subjectEntity = $em->find(Subject::class, $subject);
        $loEntity = $em->find(CurriculumLearningOutcome::class, $lo);
        self::assertInstanceOf(Subject::class, $subjectEntity);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $loEntity);
        $content = $manager->createDraft(
            $actor,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            $code,
            $title,
            null,
            LearningContentDocument::paragraph('[Taslak]'),
            [['learningOutcome' => $loEntity, 'isPrimary' => true]],
            'seed',
        );
        $id = $content->getId();
        self::ensureKernelShutdown();

        return $id;
    }

    /**
     * @param array{schemaVersion: int, blocks: list<array<string, mixed>>} $structured
     */
    private function forceStructuredContent(Uuid $contentId, array $structured): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $content = $em->find(LearningContent::class, $contentId);
        self::assertInstanceOf(LearningContent::class, $content);
        $revision = $content->getCurrentRevision();
        self::assertInstanceOf(LearningContentRevision::class, $revision);
        $em->getConnection()->executeStatement(
            'UPDATE learning_content_revisions SET structured_content = ? WHERE id = ?',
            [json_encode($structured, \JSON_THROW_ON_ERROR), $revision->getId()->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );
        self::ensureKernelShutdown();
    }

    private function sealCurrent(Uuid $contentId, string $actorEmail): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var LearningContentManager $manager */
        $manager = static::getContainer()->get(LearningContentManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $actor = $users->findOneByNormalizedEmail(mb_strtolower($actorEmail));
        self::assertInstanceOf(User::class, $actor);
        $content = $em->find(LearningContent::class, $contentId);
        self::assertInstanceOf(LearningContent::class, $content);
        $manager->submitForReview($content, $actor, 'seal_for_test');
        self::ensureKernelShutdown();
    }

    private function csrfFromEdit(KernelBrowser $client, Uuid $contentId): string
    {
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122().'/revision');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#revision-save-form input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    private function currentRevisionId(Uuid $contentId): string
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $content = $em->find(LearningContent::class, $contentId);
        self::assertInstanceOf(LearningContent::class, $content);
        $revision = $content->getCurrentRevision();
        self::assertInstanceOf(LearningContentRevision::class, $revision);
        $id = $revision->getId()->toRfc4122();
        self::ensureKernelShutdown();

        return $id;
    }

    /**
     * @return array{0: Uuid, 1: Uuid}
     */
    private function seedCurriculum(string $prefix): array
    {
        $sa = $this->createPrivileged($prefix.'-sa@example.com', UserRole::SuperAdmin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var CurriculumProgramManager $programs */
        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        /** @var CurriculumUnitManager $units */
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        /** @var CurriculumTopicManager $topics */
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        /** @var CurriculumLearningOutcomeManager $outcomes */
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $saUser = $em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $saUser);
        $subject = $subjects->create($saUser, $prefix.'_s', 'Subj '.$prefix, 'cs');
        $program = $programs->createDraft($subject, $saUser, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'cp');
        $cUnit = $units->create($program, $saUser, $prefix.'_u', 'U', 1, 'cu');
        $cTopic = $topics->createRoot($cUnit, $saUser, $prefix.'_t', 'T', 1, 'ct');
        $lo = $outcomes->create($cTopic, $saUser, $prefix.'_lo', 'O', 1, 'clo');
        $programs->publish($program, $saUser, 'pp');
        $ids = [$subject->getId(), $lo->getId()];
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
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Admin', 'User', $initial);
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
            $conn = $em->getConnection();
            $sm = $conn->createSchemaManager();
            if ($sm->tablesExist(['catalog_topic_lessons'])) {
                $conn->executeStatement('DELETE FROM catalog_topic_lessons');
            }
            if ($sm->tablesExist(['learning_content_access_policies'])) {
                $conn->executeStatement('DELETE FROM learning_content_access_policies');
            }
            LearningContentDbCleanup::deleteLearningContents($conn);
            foreach ([
                'curriculum_learning_outcomes',
                'curriculum_topics',
                'curriculum_units',
                'curriculum_programs',
                'catalog_topics',
                'catalog_units',
                'catalog_subjects',
                'subjects',
                'security_audit_events',
                'users',
            ] as $table) {
                if ($sm->tablesExist([$table])) {
                    $conn->executeStatement('DELETE FROM '.$table);
                }
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
