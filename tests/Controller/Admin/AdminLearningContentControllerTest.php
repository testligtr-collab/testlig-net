<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\ResourceAccessClass;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\UserRepository;
use App\Service\CatalogWriteService;
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
use Symfony\Component\Uid\Uuid;

final class AdminLearningContentControllerTest extends WebTestCase
{
    public function testRoleMatrixForLearningContentList(): void
    {
        $this->createPrivileged('lc-admin-list@example.com', UserRole::Admin);
        $this->createPrivileged('lc-teacher-list@example.com', UserRole::Teacher);
        $this->createPrivileged('lc-mod-list@example.com', UserRole::Moderator);
        $this->createPrivileged('lc-student-list@example.com', UserRole::Student);

        $client = static::createClient();
        $this->login($client, 'lc-admin-list@example.com');
        $client->request('GET', '/yonetim/icerikler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav', 'İçerikler');

        $client = static::createClient();
        $this->login($client, 'lc-teacher-list@example.com');
        $client->request('GET', '/yonetim/icerikler');
        self::assertResponseIsSuccessful();

        $client = static::createClient();
        $this->login($client, 'lc-mod-list@example.com');
        $client->request('GET', '/yonetim/icerikler');
        self::assertResponseIsSuccessful();

        $client = static::createClient();
        $this->login($client, 'lc-student-list@example.com');
        $client->request('GET', '/yonetim/icerikler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testModeratorCannotCreateAndTeacherCannotSeeOthersContent(): void
    {
        [$adminContent, $teacherContent] = $this->seedTwoContents();

        $this->createPrivileged('lc-mod-create@example.com', UserRole::Moderator);
        $client = static::createClient();
        $this->login($client, 'lc-mod-create@example.com');
        $client->request('GET', '/yonetim/icerikler/yeni');
        self::assertResponseStatusCodeSame(403);

        $teacherEmail = 'lc-teacher-idor@example.com';
        $this->createPrivileged($teacherEmail, UserRole::Teacher);
        $client = static::createClient();
        $this->login($client, $teacherEmail);
        $client->request('GET', '/yonetim/icerikler/'.$adminContent->toRfc4122());
        self::assertResponseStatusCodeSame(404);

        // Teacher can open own content after creating it as that actor — seed teacher content under teacher.
        $ownId = $this->createContentAs($teacherEmail, 'lc_teacher_own', 'Teacher Own');
        $client->request('GET', '/yonetim/icerikler/'.$ownId->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Teacher Own');

        unset($teacherContent);
    }

    public function testCreateRequiresCsrfAndCreatesDraftWithAudit(): void
    {
        [$subject, $lo] = $this->seedCurriculum('lc_create');
        $this->createPrivileged('lc-admin-create@example.com', UserRole::Admin);

        $client = static::createClient();
        $this->login($client, 'lc-admin-create@example.com');
        $client->request('POST', '/yonetim/icerikler/yeni', [
            'learning_content_create' => [
                'code' => 'lc_csrf_bad',
                'title' => 'Bad CSRF',
                'contentType' => LearningContentType::TopicExplanation->value,
                'gradeLevel' => (string) GradeLevel::Grade1->value,
                'subjectId' => $subject->toRfc4122(),
                'learningOutcomeId' => $lo->toRfc4122(),
                '_token' => 'invalid',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/yonetim/icerikler/yeni');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Taslak oluştur')->form([
            'learning_content_create[code]' => 'lc_ok_draft',
            'learning_content_create[title]' => 'Taslak Başlık',
            'learning_content_create[contentType]' => LearningContentType::TopicExplanation->value,
            'learning_content_create[gradeLevel]' => (string) GradeLevel::Grade1->value,
            'learning_content_create[subjectId]' => $subject->toRfc4122(),
            'learning_content_create[learningOutcomeId]' => $lo->toRfc4122(),
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Taslak Başlık');

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = (int) $em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\SecurityAuditEvent::class, 'e')
            ->andWhere('e.action = :action')
            ->setParameter('action', SecurityAuditAction::LearningContentCreated)
            ->getQuery()
            ->getSingleScalarResult();
        self::assertGreaterThan(0, $count);
    }

    public function testFreePolicyRequiresConfirmAndArchivedSubjectRejectedOnMap(): void
    {
        [$subjectId, $contentId, $catalogSubjectId] = $this->seedMappedCatalogAndContent('lc_pol');
        $this->createPrivileged('lc-admin-pol@example.com', UserRole::Admin);

        $client = static::createClient();
        $this->login($client, 'lc-admin-pol@example.com');
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/erisim', [
            '_token' => 'bad',
            'access_class' => ResourceAccessClass::Free->value,
            'confirm_free' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::Free->value,
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'onay kutusu');

        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::Free->value,
            'confirm_free' => '1',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', ResourceAccessClass::Free->value);

        // Archive subject and reject mapping to it.
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sa = $this->loadUser('lc-pol-sa@example.com');
        $subject = $em->find(Subject::class, $subjectId);
        self::assertInstanceOf(Subject::class, $subject);
        $subjects->archive($subject, $sa, 'archive_for_map');
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'lc-admin-pol@example.com');
        $client->request('GET', '/yonetim/mufredat/ders/'.$catalogSubjectId->toRfc4122());
        self::assertResponseIsSuccessful();
        // Archived subject must not appear in the active select options.
        self::assertSelectorNotExists('option[value="'.$subjectId->toRfc4122().'"]');
    }

    /**
     * @return array{0: Uuid, 1: Uuid}
     */
    private function seedTwoContents(): array
    {
        [$subject, $lo] = $this->seedCurriculum('lc_idor');
        $admin = $this->createPrivileged('lc-admin-idor@example.com', UserRole::Admin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var LearningContentManager $manager */
        $manager = static::getContainer()->get(LearningContentManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $subjectEntity = $em->find(Subject::class, $subject);
        $loEntity = $em->find(CurriculumLearningOutcome::class, $lo);
        self::assertInstanceOf(Subject::class, $subjectEntity);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $loEntity);
        $adminUser = $em->find(User::class, $admin->getId());
        self::assertInstanceOf(User::class, $adminUser);
        $a = $manager->createDraft(
            $adminUser,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'lc_admin_idor',
            'Admin Secret',
            null,
            LearningContentDocument::paragraph('[Taslak]'),
            [['learningOutcome' => $loEntity, 'isPrimary' => true]],
            'seed',
        );
        $idA = $a->getId();
        self::ensureKernelShutdown();

        return [$idA, $idA];
    }

    private function createContentAs(string $email, string $code, string $title): Uuid
    {
        [$subject, $lo] = $this->seedCurriculum($code.'_cur');
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
     * @return array{0: Uuid, 1: Uuid, 2: Uuid}
     */
    private function seedMappedCatalogAndContent(string $prefix): array
    {
        $sa = $this->createPrivileged($prefix.'-sa@example.com', UserRole::SuperAdmin);
        $admin = $this->createPrivileged($prefix.'-admin-seed@example.com', UserRole::Admin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var SubjectManager $subjects */
        $subjects = static::getContainer()->get(SubjectManager::class);
        /** @var CatalogWriteService $catalog */
        $catalog = static::getContainer()->get(CatalogWriteService::class);
        /** @var LearningContentManager $contents */
        $contents = static::getContainer()->get(LearningContentManager::class);
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
        $adminUser = $em->find(User::class, $admin->getId());
        self::assertInstanceOf(User::class, $saUser);
        self::assertInstanceOf(User::class, $adminUser);

        $subject = $subjects->create($saUser, $prefix.'_subj', 'Subject '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $saUser, GradeLevel::Grade1, $prefix.'_prog', 'Prog', '1.0', 'create_p');
        $cUnit = $units->create($program, $saUser, $prefix.'_u', 'U', 1, 'create_u');
        $cTopic = $topics->createRoot($cUnit, $saUser, $prefix.'_t', 'T', 1, 'create_t');
        $lo = $outcomes->create($cTopic, $saUser, $prefix.'_lo', 'Outcome', 1, 'create_lo');
        $programs->publish($program, $saUser, 'publish_p');

        $catalogSubject = $catalog->createSubject(GradeLevel::Grade1, 'Ders '.$prefix, null, 1);
        $catalog->assignCanonicalSubject($adminUser, $catalogSubject->getId(), $subject->getId());
        $content = $contents->createDraft(
            $adminUser,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            $prefix.'_lc',
            'Content '.$prefix,
            null,
            LearningContentDocument::paragraph('[Taslak]'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_lc',
        );

        $ids = [$subject->getId(), $content->getId(), $catalogSubject->getId()];
        self::ensureKernelShutdown();

        return $ids;
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

    private function loadUser(string $email): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail(mb_strtolower($email));
        self::assertInstanceOf(User::class, $user);

        return $user;
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
