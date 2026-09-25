<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\ResourceAccessClass;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
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

final class AdminLearningContentLifecycleTest extends WebTestCase
{
    public function testTeacherSubmitsModeratorReturnsWithoutPublishAndAdminPublishesWithSeparation(): void
    {
        $contentId = $this->seedDraftAsTeacher('lc-life');
        $this->createPrivileged('lc-life-mod@example.com', UserRole::Moderator);
        $this->createPrivileged('lc-life-admin@example.com', UserRole::Admin);
        $this->setPolicy($contentId, 'lc-life-admin@example.com');

        $client = $this->newClient();
        $this->login($client, 'lc-life-teacher@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/incelemeye-gonder"]');
        self::assertSelectorNotExists('form[action$="/yayimla"]');
        self::assertSelectorTextContains('body', 'Konu anlatımı');
        self::assertSelectorTextContains('body', 'Taslak');
        self::assertSelectorTextContains('body', 'İşlem notu');
        self::assertSelectorNotExists('input[name="note"][required]');

        $token = $crawler->filter('form[action$="/incelemeye-gonder"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/incelemeye-gonder', [
            '_token' => $token,
            'note' => 'ready_for_review',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'İncelemede');

        $client = $this->newClient();
        $this->login($client, 'lc-life-mod@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/taslaga-dondur"]');
        self::assertSelectorNotExists('form[action$="/yayimla"]');

        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/yayimla', [
            '_token' => 'ignored',
            'note' => 'mod_publish_attempt',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/taslaga-dondur"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/taslaga-dondur', [
            '_token' => $token,
            'note' => 'needs_revision',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Taslak');

        $client = $this->newClient();
        $this->login($client, 'lc-life-teacher@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/incelemeye-gonder"] input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/incelemeye-gonder', [
            '_token' => $token,
            'note' => 'ready_again',
        ]);
        self::assertResponseRedirects();

        $client = $this->newClient();
        $this->login($client, 'lc-life-admin@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/yayimla"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/yayimla', [
            '_token' => $token,
            'note' => 'publish_approved',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Yayında');
    }

    public function testLifecycleRequiresCsrfAndNote(): void
    {
        $contentId = $this->seedDraftAsTeacher('lc-csrf');
        $client = $this->newClient();
        $this->login($client, 'lc-csrf-teacher@example.com');

        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/incelemeye-gonder', [
            '_token' => 'bad',
            'note' => 'ready_for_review',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/incelemeye-gonder"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/incelemeye-gonder', [
            '_token' => $token,
            'note' => '',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'zorunlu');
    }

    public function testPublishRejectsMissingPolicyUnsealedAndAuthorSoloPublish(): void
    {
        [$authorId, $contentId] = $this->seedInReviewAsAdmin('lc-pub');
        $this->createPrivileged('lc-pub-other@example.com', UserRole::Admin);

        $client = $this->newClient();
        $this->login($client, 'lc-pub-other@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/yayimla"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/yayimla', [
            '_token' => $token,
            'note' => 'publish_no_policy',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'erişim politikası');

        $this->setPolicy($contentId, 'lc-pub-other@example.com');

        // Author solo-publish must fail (revision author == actor).
        $client = $this->newClient();
        $this->login($client, 'lc-pub-admin@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/yayimla"] input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/yayimla', [
            '_token' => $token,
            'note' => 'solo_publish',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'görev ayrımı');

        // Clone while in_review → unsealed current → publish reject.
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var LearningContentManager $manager */
        $manager = static::getContainer()->get(LearningContentManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $content = $em->find(LearningContent::class, $contentId);
        $author = $em->find(User::class, $authorId);
        self::assertInstanceOf(LearningContent::class, $content);
        self::assertInstanceOf(User::class, $author);
        $manager->cloneAsNewRevision($content, $author, 'clone_unsealed');
        self::ensureKernelShutdown();

        $client = $this->newClient();
        $this->login($client, 'lc-pub-other@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/yayimla"] input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/yayimla', [
            '_token' => $token,
            'note' => 'publish_unsealed',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'mühürlü');
    }

    public function testFreePolicyConfirmStillRequired(): void
    {
        $contentId = $this->seedDraftAsTeacher('lc-free');
        $this->createPrivileged('lc-free-admin@example.com', UserRole::Admin);

        $client = $this->newClient();
        $this->login($client, 'lc-free-admin@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/erisim"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::Free->value,
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'onay kutusu');

        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/erisim"] input[name="_token"]')->attr('value');
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::Free->value,
            'confirm_free' => '1',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', ResourceAccessClass::Free->value);
        self::assertSelectorTextContains('body', 'Bu içerik ücretsiz erişime açılacak');
    }

    public function testArchiveIsIrreversibleFromUi(): void
    {
        $contentId = $this->seedDraftAsTeacher('lc-arch');
        $this->createPrivileged('lc-arch-admin@example.com', UserRole::Admin);

        $client = $this->newClient();
        $this->login($client, 'lc-arch-admin@example.com');
        $crawler = $client->request('GET', '/yonetim/icerikler/'.$contentId->toRfc4122());
        $token = $crawler->filter('form[action$="/arsivle"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/yonetim/icerikler/'.$contentId->toRfc4122().'/arsivle', [
            '_token' => $token,
            'note' => 'archive_obsolete',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Arşivlenmiş');
        self::assertSelectorNotExists('form[action$="/incelemeye-gonder"]');
        self::assertSelectorNotExists('form[action$="/yayimla"]');
    }

    private function seedDraftAsTeacher(string $prefix): Uuid
    {
        [$subject, $lo] = $this->seedCurriculum($prefix);
        $this->createPrivileged($prefix.'-teacher@example.com', UserRole::Teacher);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var LearningContentManager $manager */
        $manager = static::getContainer()->get(LearningContentManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $teacher = $users->findOneByNormalizedEmail(mb_strtolower($prefix.'-teacher@example.com'));
        self::assertInstanceOf(User::class, $teacher);
        $subjectEntity = $em->find(Subject::class, $subject);
        $loEntity = $em->find(CurriculumLearningOutcome::class, $lo);
        self::assertInstanceOf(Subject::class, $subjectEntity);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $loEntity);
        $content = $manager->createDraft(
            $teacher,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            str_replace('-', '_', $prefix).'_draft',
            'Draft '.$prefix,
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
     * @return array{0: Uuid, 1: Uuid}
     */
    private function seedInReviewAsAdmin(string $prefix): array
    {
        [$subject, $lo] = $this->seedCurriculum($prefix);
        $admin = $this->createPrivileged($prefix.'-admin@example.com', UserRole::Admin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var LearningContentManager $manager */
        $manager = static::getContainer()->get(LearningContentManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $adminUser = $em->find(User::class, $admin->getId());
        $subjectEntity = $em->find(Subject::class, $subject);
        $loEntity = $em->find(CurriculumLearningOutcome::class, $lo);
        self::assertInstanceOf(User::class, $adminUser);
        self::assertInstanceOf(Subject::class, $subjectEntity);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $loEntity);
        $content = $manager->createDraft(
            $adminUser,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            str_replace('-', '_', $prefix).'_rev',
            'Review '.$prefix,
            null,
            LearningContentDocument::paragraph('[Taslak]'),
            [['learningOutcome' => $loEntity, 'isPrimary' => true]],
            'seed',
        );
        $manager->submitForReview($content, $adminUser, 'submit_seed');
        $ids = [$adminUser->getId(), $content->getId()];
        self::ensureKernelShutdown();

        return $ids;
    }

    private function setPolicy(Uuid $contentId, string $adminEmail): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var AccessPackageManager $packages */
        $packages = static::getContainer()->get(AccessPackageManager::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $content = $em->find(LearningContent::class, $contentId);
        $admin = $users->findOneByNormalizedEmail(mb_strtolower($adminEmail));
        self::assertInstanceOf(LearningContent::class, $content);
        self::assertInstanceOf(User::class, $admin);
        $packages->setLearningContentAccessPolicy(
            $content,
            $admin,
            ResourceAccessClass::EntitlementRequired,
            'admin_set_entitlement',
        );
        self::ensureKernelShutdown();
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
        $subject = $subjects->create($saUser, str_replace('-', '_', $prefix).'_s', 'Subj '.$prefix, 'cs');
        $program = $programs->createDraft($subject, $saUser, GradeLevel::Grade1, str_replace('-', '_', $prefix).'_p', 'P', '1.0', 'cp');
        $cUnit = $units->create($program, $saUser, str_replace('-', '_', $prefix).'_u', 'U', 1, 'cu');
        $cTopic = $topics->createRoot($cUnit, $saUser, str_replace('-', '_', $prefix).'_t', 'T', 1, 'ct');
        $lo = $outcomes->create($cTopic, $saUser, str_replace('-', '_', $prefix).'_lo', 'O', 1, 'clo');
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
