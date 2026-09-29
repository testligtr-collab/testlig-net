<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\WorkspaceDashboardAction;
use App\Dto\WorkspaceDashboardCard;
use App\Dto\WorkspaceDashboardItem;
use App\Dto\WorkspaceDashboardView;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\UserRepository;
use App\Service\Admin\ContentWorkspaceSummary;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\LearningContentDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WorkspaceDashboardControllerTest extends WebTestCase
{
    public function testAnonymousRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/calisma-alani');
        self::assertResponseRedirects('/giris');
    }

    public function testStudentParentAndInstitutionManagerAreDenied(): void
    {
        foreach ([
            'ws-student@example.com' => UserRole::Student,
            'ws-parent@example.com' => UserRole::Parent,
            'ws-owner@example.com' => UserRole::InstitutionManager,
        ] as $email => $role) {
            $client = static::createClient();
            $this->loginAs($client, $email, $role);
            $client->request('GET', '/calisma-alani');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/yonetim');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testTeacherHomeStaysOutOfTheAdminRoot(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'ws-teacher-home@example.com', UserRole::Teacher);
        $client->request('GET', '/calisma-alani');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        $csp = $client->getResponse()->headers->get('Content-Security-Policy') ?? '';
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertSelectorTextContains('h1', 'Çalışma alanı');
        self::assertSelectorNotExists('#review-queue');
        self::assertSelectorExists('a[href="/calisma-alani"]');
        self::assertSelectorNotExists('a[href="/yonetim"]');
        self::assertStringNotContainsString('Yayımla', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('ws-teacher-home@example.com', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('[data-metric="drafts"]');
        self::assertSelectorExists('[data-metric="classrooms"]');
        self::assertSelectorTextContains('[data-metric="drafts"]', '0');
        self::assertSelectorTextContains('[data-metric="classrooms"]', '0');

        $client->request('GET', '/yonetim');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/yonetim/icerikler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-breadcrumb', 'Çalışma alanı');
        $client->request('GET', '/yonetim/sorular');
        self::assertSelectorTextContains('.admin-breadcrumb', 'Çalışma alanı');
        self::assertSelectorTextContains('.admin-breadcrumb', 'Sorular');
        $client->request('GET', '/yonetim/testler');
        self::assertSelectorTextContains('.admin-breadcrumb', 'Testler');
    }

    public function testAdminRootRemainsYonetim(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'ws-admin-root@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/yonetim"]');
        self::assertSelectorTextContains('body', 'Özet');
        self::assertSelectorNotExists('a[href="/calisma-alani"]');
    }

    public function testOwnerScopeReviewQueueAndQueryBudget(): void
    {
        [$subjectId, $outcomeId] = $this->seedCurriculum('wsown');
        $teacher = $this->createPrivileged('ws-own-teacher@example.com', UserRole::Teacher);
        $other = $this->createPrivileged('ws-own-other@example.com', UserRole::Teacher);
        $this->createPrivileged('ws-own-mod@example.com', UserRole::Moderator);
        $this->draft($teacher, $subjectId, $outcomeId, 'ws_own_mine', 'Benim taslagim');
        $this->draft($other, $subjectId, $outcomeId, 'ws_own_secret', 'Gizli taslak');

        $client = $this->newClient();
        $this->login($client, 'ws-own-teacher@example.com');
        $client->request('GET', '/calisma-alani');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-metric="drafts"] .workspace-metric__value', '1');
        self::assertSelectorNotExists('#review-queue');
        self::assertStringNotContainsString('Gizli taslak', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Benim taslagim', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('[data-metric="questions"] .workspace-metric__value', '0');
        self::assertSelectorTextContains('[data-metric="tests"] .workspace-metric__value', '0');

        $client = $this->newClient();
        $this->login($client, 'ws-own-other@example.com');
        $client->request('GET', '/calisma-alani');
        self::assertSelectorTextContains('[data-metric="drafts"] .workspace-metric__value', '1');
        self::assertStringNotContainsString('Benim taslagim', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Gizli taslak', (string) $client->getResponse()->getContent());

        $actor = $this->reload('ws-own-teacher@example.com');
        $summary = static::getContainer()->get(ContentWorkspaceSummary::class);
        self::assertInstanceOf(ContentWorkspaceSummary::class, $summary);
        $summary->workspaceHome($actor);
        $before = $summary->statementCount();
        self::assertLessThanOrEqual(8, $before);
        $this->draft($actor, $subjectId, $outcomeId, 'ws_own_more', 'Ek taslak');
        $actor = $this->reload('ws-own-teacher@example.com');
        $summary = static::getContainer()->get(ContentWorkspaceSummary::class);
        self::assertInstanceOf(ContentWorkspaceSummary::class, $summary);
        $summary->workspaceHome($actor);
        self::assertSame($before, $summary->statementCount());

        $client = $this->newClient();
        $this->login($client, 'ws-own-mod@example.com');
        $client->request('GET', '/calisma-alani');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#review-queue');
        self::assertSelectorExists('[data-metric="review"]');
        self::assertStringNotContainsString('Gizli taslak', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Yeni içerik', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Yeni soru', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Yeni test', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Yayımla', (string) $client->getResponse()->getContent());
    }

    public function testDashboardDtoExposesNoSensitiveFields(): void
    {
        foreach ([WorkspaceDashboardView::class, WorkspaceDashboardCard::class, WorkspaceDashboardItem::class, WorkspaceDashboardAction::class] as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                self::assertDoesNotMatchRegularExpression('/email|uuid|storage|token|json|digest|password/i', $property->getName());
            }
        }
    }

    private function draft(User $actor, \Symfony\Component\Uid\Uuid $subjectId, \Symfony\Component\Uid\Uuid $outcomeId, string $code, string $title): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $subject = $em->find(Subject::class, $subjectId);
        $outcome = $em->find(CurriculumLearningOutcome::class, $outcomeId);
        $user = $em->find(User::class, $actor->getId());
        self::assertInstanceOf(Subject::class, $subject);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $outcome);
        self::assertInstanceOf(User::class, $user);
        /** @var LearningContentManager $manager */
        $manager = static::getContainer()->get(LearningContentManager::class);
        $manager->createDraft(
            $user,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            $code,
            $title,
            null,
            LearningContentDocument::paragraph('[Taslak]'),
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            'seed',
        );
        self::ensureKernelShutdown();
    }

    /**
     * @return array{0: \Symfony\Component\Uid\Uuid, 1: \Symfony\Component\Uid\Uuid}
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
        $unit = $units->create($program, $saUser, $prefix.'_u', 'U', 1, 'cu');
        $topic = $topics->createRoot($unit, $saUser, $prefix.'_t', 'T', 1, 'ct');
        $outcome = $outcomes->create($topic, $saUser, $prefix.'_lo', 'O', 1, 'clo');
        $programs->publish($program, $saUser, 'pp');
        $ids = [$subject->getId(), $outcome->getId()];
        self::ensureKernelShutdown();

        return $ids;
    }

    private function reload(string $email): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail(mb_strtolower($email));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): void
    {
        $this->createPrivileged($email, $role);
        $this->login($client, $email);
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

    private function newClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function createPrivileged(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ad', 'Min', $initial);
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
            $connection = $em->getConnection();
            if ($connection->createSchemaManager()->tablesExist(['learning_contents'])) {
                LearningContentDbCleanup::deleteLearningContents($connection);
            }
            if ($connection->createSchemaManager()->tablesExist(['users'])) {
                $connection->executeStatement("DELETE FROM users WHERE normalized_email LIKE 'ws%@example.com'");
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
