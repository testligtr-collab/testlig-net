<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\SecurityAuditAction;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\CatalogWriteService;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCatalogControllerTest extends WebTestCase
{
    public function testAnonymousAndStudentDenied(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yonetim/mufredat');
        self::assertResponseRedirects('/giris');

        $this->createPrivileged('cat-admin-student@example.com', UserRole::Student);
        $client = static::createClient();
        $this->login($client, 'cat-admin-student@example.com');
        $client->request('GET', '/yonetim/mufredat');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanCreateSubject(): void
    {
        $this->createPrivileged('cat-admin@example.com', UserRole::Admin);
        $client = static::createClient();
        $this->login($client, 'cat-admin@example.com');

        $crawler = $client->request('GET', '/yonetim/mufredat/ders/yeni');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Kaydet')->form([
            'catalog_subject[gradeLevel]' => (string) GradeLevel::Grade9->value,
            'catalog_subject[name]' => 'Fizik',
            'catalog_subject[position]' => '1',
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Fizik');
        self::assertSelectorTextContains('body', 'Taslak');
    }

    public function testPublishRequiresValidCsrf(): void
    {
        $this->createPrivileged('cat-admin-csrf@example.com', UserRole::Admin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogWriteService $writer */
        $writer = static::getContainer()->get(CatalogWriteService::class);
        $subject = $writer->createSubject(GradeLevel::Grade10, 'Kimya', null, 1);
        $id = $subject->getId()->toRfc4122();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'cat-admin-csrf@example.com');
        $client->request('POST', '/yonetim/mufredat/ders/'.$id.'/yayimla', [
            '_token' => 'bad',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCanonicalMapRequiresCsrfUsesUuidOnlyAndAudits(): void
    {
        $sa = $this->createPrivileged('cat-map-sa@example.com', UserRole::SuperAdmin);
        $this->createPrivileged('cat-map-admin@example.com', UserRole::Admin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogWriteService $writer */
        $writer = static::getContainer()->get(CatalogWriteService::class);
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $saUser = $em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $saUser);
        $canonical = $subjects->create($saUser, 'cat_map_math', 'Matematik Canonical', 'create');
        $catalogSubject = $writer->createSubject(GradeLevel::Grade1, 'Matematik Katalog', null, 1);
        // Same display name must not auto-map.
        self::assertNull($catalogSubject->getCanonicalSubject());
        $catalogId = $catalogSubject->getId()->toRfc4122();
        $canonicalId = $canonical->getId()->toRfc4122();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'cat-map-admin@example.com');
        $client->request('POST', '/yonetim/mufredat/ders/'.$catalogId.'/canonical', [
            '_token' => 'bad',
            'canonical_subject_id' => $canonicalId,
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/yonetim/mufredat/ders/'.$catalogId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Eşleme yok');
        $form = $crawler->selectButton('Eşlemeyi kaydet')->form([
            'canonical_subject_id' => $canonicalId,
        ]);
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Matematik Canonical');
        self::assertSelectorTextContains('body', $canonicalId);

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $auditCount = (int) $em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\SecurityAuditEvent::class, 'e')
            ->andWhere('e.action = :action')
            ->setParameter('action', SecurityAuditAction::CatalogSubjectCanonicalMapped)
            ->getQuery()
            ->getSingleScalarResult();
        self::assertGreaterThan(0, $auditCount);

        // Name/slug are never posted as mapping keys — only UUID select values exist.
        $client = static::createClient();
        $this->login($client, 'cat-map-admin@example.com');
        $crawler = $client->request('GET', '/yonetim/mufredat/ders/'.$catalogId);
        $options = $crawler->filter('select[name="canonical_subject_id"] option');
        self::assertGreaterThan(1, $options->count());
        $options->each(static function (\Symfony\Component\DomCrawler\Crawler $node): void {
            $value = $node->attr('value') ?? '';
            if ('' === $value) {
                return;
            }
            self::assertMatchesRegularExpression('/^[0-9a-fA-F-]{36}$/', $value);
        });
    }

    public function testUnmapRejectedWhenLearningContentBoundAndArchivedSubjectNotSelectable(): void
    {
        $sa = $this->createPrivileged('cat-unmap-sa@example.com', UserRole::SuperAdmin);
        $admin = $this->createPrivileged('cat-unmap-admin@example.com', UserRole::Admin);
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var CatalogWriteService $writer */
        $writer = static::getContainer()->get(CatalogWriteService::class);
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var \App\Service\CurriculumProgramManager $programs */
        $programs = static::getContainer()->get(\App\Service\CurriculumProgramManager::class);
        /** @var \App\Service\CurriculumUnitManager $units */
        $units = static::getContainer()->get(\App\Service\CurriculumUnitManager::class);
        /** @var \App\Service\CurriculumTopicManager $topics */
        $topics = static::getContainer()->get(\App\Service\CurriculumTopicManager::class);
        /** @var \App\Service\CurriculumLearningOutcomeManager $outcomes */
        $outcomes = static::getContainer()->get(\App\Service\CurriculumLearningOutcomeManager::class);
        /** @var \App\Service\LearningContentManager $contents */
        $contents = static::getContainer()->get(\App\Service\LearningContentManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $saUser = $em->find(User::class, $sa->getId());
        $adminUser = $em->find(User::class, $admin->getId());
        self::assertInstanceOf(User::class, $saUser);
        self::assertInstanceOf(User::class, $adminUser);

        $canonical = $subjects->create($saUser, 'cat_unmap_s', 'Unmap Subj', 'create');
        $program = $programs->createDraft($canonical, $saUser, GradeLevel::Grade1, 'cat_unmap_p', 'P', '1.0', 'cp');
        $cUnit = $units->create($program, $saUser, 'cat_unmap_u', 'U', 1, 'cu');
        $cTopic = $topics->createRoot($cUnit, $saUser, 'cat_unmap_t', 'T', 1, 'ct');
        $lo = $outcomes->create($cTopic, $saUser, 'cat_unmap_lo', 'O', 1, 'clo');
        $programs->publish($program, $saUser, 'pp');

        $catalogSubject = $writer->createSubject(GradeLevel::Grade1, 'Unmap Catalog', null, 1);
        $writer->assignCanonicalSubject($adminUser, $catalogSubject->getId(), $canonical->getId());
        $contents->createDraft(
            $adminUser,
            \App\Enum\LearningContentScope::Platform,
            null,
            $canonical,
            GradeLevel::Grade1,
            \App\Enum\LearningContentType::TopicExplanation,
            'cat_unmap_lc',
            'Bound Content',
            null,
            \App\LearningContent\Content\LearningContentDocument::paragraph('[Taslak]'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create',
        );
        $catalogId = $catalogSubject->getId()->toRfc4122();
        $canonicalId = $canonical->getId()->toRfc4122();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'cat-unmap-admin@example.com');
        $crawler = $client->request('GET', '/yonetim/mufredat/ders/'.$catalogId);
        $client->submit($crawler->selectButton('Eşlemeyi kaydet')->form([
            'canonical_subject_id' => '',
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'öğrenme içeriği');

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $saUser = $em->find(User::class, $sa->getId());
        $canonicalEntity = $em->find(\App\Entity\Subject::class, $canonical->getId());
        self::assertInstanceOf(User::class, $saUser);
        self::assertInstanceOf(\App\Entity\Subject::class, $canonicalEntity);
        $subjects->archive($canonicalEntity, $saUser, 'archive');
        self::assertSame(SubjectStatus::Archived, $canonicalEntity->getStatus());
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'cat-unmap-admin@example.com');
        $client->request('GET', '/yonetim/mufredat/ders/'.$catalogId);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('option[value="'.$canonicalId.'"]');
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
            \App\Tests\Support\LearningContentDbCleanup::deleteLearningContents($conn);
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
