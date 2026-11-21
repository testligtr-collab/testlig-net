<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\LearningContentType;
use App\Enum\ResourceAccessClass;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\LearningContentAccessPolicyRepository;
use App\Repository\LearningContentRepository;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminSimulationsDraftTest extends WebTestCase
{
    public function testListShowsOnlyPlatformSimulationDraftsAndGetDoesNotWrite(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $sa = $this->persistUser('sim-inv-sa@example.com', UserRole::SuperAdmin);
        $teacher = $this->persistUser('sim-inv-teacher@example.com', UserRole::Teacher);
        $owner = $this->persistUser('sim-inv-owner@example.com', UserRole::InstitutionManager);
        [$subject, $outcome] = $this->seedCurriculum($sa, 'sim_inv');

        $institutions = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $institutions);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $memberships = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $memberships);
        $institution = $institutions->create($sa, $owner, 'Sim Kurum Gizli', InstitutionType::School, 'sim_inv_inst');
        $status->activate($institution, $sa, 'sim_inv_activate');
        $memberships->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'sim_inv_member');

        $contents = static::getContainer()->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $contents);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $subjectEntity = $em->find(Subject::class, $subject->getId());
        $outcomeEntity = $em->find(CurriculumLearningOutcome::class, $outcome->getId());
        $institutionEntity = $em->find(Institution::class, $institution->getId());
        $teacherEntity = $em->find(User::class, $teacher->getId());
        self::assertInstanceOf(Subject::class, $subjectEntity);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $outcomeEntity);
        self::assertInstanceOf(Institution::class, $institutionEntity);
        self::assertInstanceOf(User::class, $teacherEntity);

        $alignment = [['learningOutcome' => $outcomeEntity, 'isPrimary' => true]];
        $platformTitle = 'Platform Simülasyon Taslak Uzun Baslik Sarilsin';
        $platformDraft = $contents->createDraft(
            $teacherEntity,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::Simulation,
            'sim_inv_platform',
            $platformTitle,
            null,
            LearningContentDocument::paragraph('Simülasyon taslağı.'),
            $alignment,
            'sim_inv_create',
        );

        $contents->createDraft(
            $teacherEntity,
            LearningContentScope::Platform,
            null,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'sim_inv_topic',
            'Konu Anlatim Taslak Gizli',
            null,
            LearningContentDocument::paragraph('Konu'),
            $alignment,
            'sim_inv_topic_create',
        );

        $institutionTitle = 'Kurum Simülasyon Gizli';
        $contents->createDraft(
            $teacherEntity,
            LearningContentScope::Institution,
            $institutionEntity,
            $subjectEntity,
            GradeLevel::Grade1,
            LearningContentType::Simulation,
            'sim_inv_institution',
            $institutionTitle,
            null,
            LearningContentDocument::paragraph('Kurum sim'),
            $alignment,
            'sim_inv_inst_create',
        );

        $before = $this->contentCount();
        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim/bolum/simulasyonlar');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSelectorTextContains('h1', 'Simülasyonlar');
        self::assertSelectorNotExists('.admin-stage');
        self::assertSelectorExists('a[href="/yonetim/bolum/simulasyonlar/yeni"]');
        self::assertSame($before, $this->contentCount(), 'GET must not write learning contents');

        $html = $crawler->html();
        self::assertStringContainsString($platformTitle, $html);
        self::assertStringNotContainsString($institutionTitle, $html);
        self::assertStringNotContainsString('Konu Anlatim Taslak Gizli', $html);
        self::assertStringNotContainsString($institution->getName(), $html);
        self::assertStringNotContainsString($teacher->getEmail(), $html);
        self::assertSelectorExists('a.admin-simulations-drafts-page__open[href="/yonetim/icerikler/'.$platformDraft->getId()->toRfc4122().'"]');
    }

    public function testCreateDraftIsPlatformSimulationAndBlocksReviewPublish(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $sa = $this->persistUser('sim-create-sa@example.com', UserRole::SuperAdmin);
        [$subject, $outcome] = $this->seedCurriculum($sa, 'sim_cr');
        $before = $this->contentCount();

        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim/bolum/simulasyonlar/yeni');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Taslak oluştur')->form([
            'admin_simulation_draft_create[code]' => 'sim_cr_code',
            'admin_simulation_draft_create[title]' => 'Olusturulan Simülasyon Taslagi',
            'admin_simulation_draft_create[summary]' => '',
            'admin_simulation_draft_create[gradeLevel]' => (string) GradeLevel::Grade1->value,
            'admin_simulation_draft_create[subjectId]' => $subject->getId()->toRfc4122(),
            'admin_simulation_draft_create[learningOutcomeId]' => $outcome->getId()->toRfc4122(),
        ]);
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertSame($before + 1, $this->contentCount());

        $repo = static::getContainer()->get(LearningContentRepository::class);
        self::assertInstanceOf(LearningContentRepository::class, $repo);
        $created = $repo->findPlatformByCode('sim_cr_code');
        self::assertCount(1, $created);
        $content = $created[0];
        self::assertSame(LearningContentType::Simulation, $content->getContentType());
        self::assertSame(LearningContentScope::Platform, $content->getScope());
        self::assertSame(LearningContentStatus::Draft, $content->getStatus());
        $id = $content->getId()->toRfc4122();

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Olusturulan Simülasyon Taslagi');
        self::assertSelectorNotExists('form[action$="/incelemeye-gonder"]');
        self::assertSelectorNotExists('form[action$="/yayimla"]');

        // Forms are hidden for Simulation; mint CSRF in the client session like other HTTP tests.
        $lifecycle = $this->csrf($client, 'learning_content_lifecycle_'.$id);
        $client->request('POST', '/yonetim/icerikler/'.$id.'/incelemeye-gonder', [
            '_token' => $lifecycle,
            'note' => 'sim_submit_blocked',
        ]);
        self::assertResponseRedirects('/yonetim/icerikler/'.$id);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Simülasyon taslakları henüz incelemeye veya yayına alınamaz.');
        self::assertSelectorTextContains('body', 'Taslak');

        // publish() checks access policy before the Simulation domain gate; seed policy only.
        $packages = static::getContainer()->get(AccessPackageManager::class);
        self::assertInstanceOf(AccessPackageManager::class, $packages);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $contentEntity = $em->find(LearningContent::class, $content->getId());
        $actor = $em->find(User::class, $sa->getId());
        self::assertInstanceOf(LearningContent::class, $contentEntity);
        self::assertInstanceOf(User::class, $actor);
        $packages->setLearningContentAccessPolicy(
            $contentEntity,
            $actor,
            ResourceAccessClass::EntitlementRequired,
            'sim_publish_policy',
        );
        $policies = static::getContainer()->get(LearningContentAccessPolicyRepository::class);
        self::assertInstanceOf(LearningContentAccessPolicyRepository::class, $policies);
        self::assertNotNull($policies->findForContent($content->getId()));

        $client->request('GET', '/yonetim/icerikler/'.$id);
        self::assertResponseIsSuccessful();
        $lifecycle2 = $this->csrf($client, 'learning_content_lifecycle_'.$id);
        $client->request('POST', '/yonetim/icerikler/'.$id.'/yayimla', [
            '_token' => $lifecycle2,
            'note' => 'sim_publish_blocked',
        ]);
        self::assertResponseRedirects('/yonetim/icerikler/'.$id);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Simülasyon taslakları henüz incelemeye veya yayına alınamaz.');
        self::assertSelectorTextContains('body', 'Taslak');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $fresh = $em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $fresh);
        self::assertSame(LearningContentStatus::Draft, $fresh->getStatus());
    }

    public function testInvalidCsrfDoesNotCreateAndAccessGates(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $sa = $this->persistUser('sim-csrf-sa@example.com', UserRole::SuperAdmin);
        [$subject, $outcome] = $this->seedCurriculum($sa, 'sim_csrf');
        $before = $this->contentCount();

        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim/bolum/simulasyonlar/yeni');
        $form = $crawler->selectButton('Taslak oluştur')->form([
            'admin_simulation_draft_create[code]' => 'sim_csrf_code',
            'admin_simulation_draft_create[title]' => 'CSRF Red',
            'admin_simulation_draft_create[gradeLevel]' => (string) GradeLevel::Grade1->value,
            'admin_simulation_draft_create[subjectId]' => $subject->getId()->toRfc4122(),
            'admin_simulation_draft_create[learningOutcomeId]' => $outcome->getId()->toRfc4122(),
        ]);
        $values = $form->getPhpValues();
        $values['admin_simulation_draft_create']['_token'] = 'invalid-csrf-token';
        $client->request('POST', '/yonetim/bolum/simulasyonlar/yeni', $values);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->contentCount());

        $inventory = static::getContainer()->get(LearningContentRepository::class);
        self::assertInstanceOf(LearningContentRepository::class, $inventory);
        $emptySa = $this->activeUser('sim-empty-sa@example.com', UserRole::SuperAdmin);
        $emptyClient = $this->browser();
        $emptyClient->loginUser($emptySa);
        $emptyClient->request('GET', '/yonetim/bolum/simulasyonlar');
        self::assertResponseIsSuccessful();
        if (0 === $inventory->countPlatformSimulationDraftsForAdmin()) {
            self::assertSelectorTextContains('body', 'Gösterilecek simülasyon taslağı yok');
            self::assertSelectorTextContains('body', '0 kayıt');
        }

        foreach ([
            ['sim-deny-admin@example.com', UserRole::Admin, 403],
            ['sim-deny-teacher@example.com', UserRole::Teacher, 403],
        ] as [$email, $role, $status]) {
            $user = $this->activeUser($email, $role);
            $denied = $this->browser();
            $denied->loginUser($user);
            $denied->request('GET', '/yonetim/bolum/simulasyonlar');
            self::assertResponseStatusCodeSame($status);
            $denied->request('GET', '/yonetim/bolum/simulasyonlar/yeni');
            self::assertResponseStatusCodeSame($status);
        }

        $anon = $this->browser();
        $anon->request('GET', '/yonetim/bolum/simulasyonlar');
        self::assertResponseRedirects('/giris');
    }

    public function testNavMarksSimulationsReadyAndCurrentOnce(): void
    {
        $sa = $this->activeUser('sim-nav-sa@example.com', UserRole::SuperAdmin);
        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('a.admin-mini-link[href="/yonetim/bolum/simulasyonlar"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Çalışıyor', $card->text());
        self::assertStringNotContainsString('Geliştirme aşamasında', $card->text());

        $crawler = $client->request('GET', '/yonetim/bolum/simulasyonlar');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#panel-sidebar a[href="/yonetim/bolum/simulasyonlar"][aria-current="page"]'));
        self::assertNotNull($crawler->filter('#nav-group-interactive')->attr('open'));
    }

    /**
     * @return array{0: Subject, 1: CurriculumLearningOutcome}
     */
    private function seedCurriculum(User $sa, string $prefix): array
    {
        $subjects = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjects);
        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programs);
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $topics);
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);

        $subject = $subjects->create($sa, $prefix.'_s', 'Subj '.$prefix, 'sim_inv_cs');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'sim_inv_cp');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'sim_inv_cu');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'sim_inv_ct');
        $outcome = $outcomes->create($topic, $sa, $prefix.'_lo', 'O', 1, 'sim_inv_clo');
        $programs->publish($program, $sa, 'sim_inv_pp');

        return [$subject, $outcome];
    }

    private function contentCount(): int
    {
        $repo = static::getContainer()->get(LearningContentRepository::class);
        self::assertInstanceOf(LearningContentRepository::class, $repo);

        return $repo->count([]);
    }

    private function activeUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return $this->persistUser($email, $role);
    }

    private function persistUser(string $email, UserRole $role): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Sim', 'Eo', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function csrf(KernelBrowser $client, string $intention): string
    {
        $request = $client->getRequest();
        $session = $request->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
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
}
