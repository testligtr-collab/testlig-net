<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Assessment;
use App\Entity\AssessmentAccessPolicy;
use App\Entity\Institution;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\SecurityAuditEvent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionType;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResourceAccessClass;
use App\Enum\ResultReleasePolicy;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentAccessPolicyRepository;
use App\Repository\AssessmentRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\AssessmentManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\AssessmentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminTestAccessPolicyControllerTest extends WebTestCase
{
    public function testUndefinedPolicyShownAndAuthorizedAdminCanSetRequiredAndFree(): void
    {
        $seed = $this->seedPlatformDraft('tapdef');
        $this->createPrivileged('tapdef-admin@example.com', UserRole::Admin);
        $client = $this->newClient();
        $this->login($client, 'tapdef-admin@example.com');

        $crawler = $client->request('GET', $seed['path']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#access-policy-heading', 'Erişim politikası');
        self::assertSelectorTextContains('body', 'Politika tanımlanmamış');
        self::assertSelectorTextContains('body', 'platform öğrenci keşfi ve yeni deneme başlangıcına uygulanır');
        self::assertSelectorTextContains('body', 'Ücretsiz seçimi açık onay gerektirir.');
        self::assertSelectorTextNotContains('body', 'Varsayılan kapalı kalır');
        self::assertSelectorExists('form[action$="/erisim"]');

        $token = $crawler->filter('form[action$="/erisim"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $before = $this->auditCount();
        $client->request('POST', $seed['path'].'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::EntitlementRequired->value,
        ]);
        self::assertResponseRedirects($seed['path']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Erişim politikası güncellendi.');
        self::assertSelectorTextContains('body', 'Lisans gerekli');
        self::assertSelectorTextNotContains('body', 'Politika tanımlanmamış');
        self::assertSame(ResourceAccessClass::EntitlementRequired, $this->policyClass($seed['id']));
        self::assertSame($before + 1, $this->auditCount());
        $this->assertLatestPolicyAudit(
            $seed['id'],
            ResourceAccessClass::EntitlementRequired->value,
            'admin_set_entitlement',
        );

        $crawler = $client->request('GET', $seed['path']);
        $token = $crawler->filter('form[action$="/erisim"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', $seed['path'].'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::Free->value,
            'confirm_free' => '1',
        ]);
        self::assertResponseRedirects($seed['path']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Ücretsiz');
        self::assertSame(ResourceAccessClass::Free, $this->policyClass($seed['id']));
        self::assertSame($before + 2, $this->auditCount());
        $this->assertLatestPolicyAudit(
            $seed['id'],
            ResourceAccessClass::Free->value,
            'admin_set_free',
        );
    }

    public function testFreeWithoutConfirmIsRejectedAndPolicyUnchanged(): void
    {
        $seed = $this->seedPlatformDraft('tapfree');
        $this->createPrivileged('tapfree-admin@example.com', UserRole::Admin);
        $client = $this->newClient();
        $this->login($client, 'tapfree-admin@example.com');
        $crawler = $client->request('GET', $seed['path']);
        $token = $crawler->filter('form[action$="/erisim"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $before = $this->auditCount();

        $client->request('POST', $seed['path'].'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::Free->value,
        ]);
        self::assertResponseRedirects($seed['path']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'onay kutusu');
        self::assertSelectorTextContains('body', 'Politika tanımlanmamış');
        self::assertNull($this->policyClass($seed['id']));
        self::assertSame($before, $this->auditCount());

        $this->setPolicyDirectly($seed['id'], 'tapfree-admin@example.com', ResourceAccessClass::EntitlementRequired, 'seed_required');
        self::assertSame(ResourceAccessClass::EntitlementRequired, $this->policyClass($seed['id']));
        $afterRequired = $this->auditCount();
        self::assertSame($before + 1, $afterRequired);

        $client = $this->newClient();
        $this->login($client, 'tapfree-admin@example.com');
        $crawler = $client->request('GET', $seed['path']);
        $token = $crawler->filter('form[action$="/erisim"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', $seed['path'].'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::Free->value,
        ]);
        self::assertResponseRedirects($seed['path']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'onay kutusu');
        self::assertSelectorTextContains('body', 'Lisans gerekli');
        self::assertSame(ResourceAccessClass::EntitlementRequired, $this->policyClass($seed['id']));
        self::assertSame($afterRequired, $this->auditCount());
    }

    public function testUnauthorizedModeratorCannotSeeFormOrPost(): void
    {
        $seed = $this->seedPlatformDraft('tapmod');
        $this->createPrivileged('tapmod-mod@example.com', UserRole::Moderator);
        $before = $this->auditCount();
        $client = $this->newClient();
        $this->login($client, 'tapmod-mod@example.com');
        $client->request('GET', $seed['path']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Politika tanımlanmamış');
        self::assertSelectorNotExists('form[action$="/erisim"]');
        $token = $this->csrfToken($client, $seed['id']);

        $client->request('POST', $seed['path'].'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::Free->value,
            'confirm_free' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->policyClass($seed['id']));
        self::assertSame($before, $this->auditCount());
    }

    public function testInvalidCsrfIsDenied(): void
    {
        $seed = $this->seedPlatformDraft('tapcsrf');
        $this->createPrivileged('tapcsrf-admin@example.com', UserRole::Admin);
        $before = $this->auditCount();
        $client = $this->newClient();
        $this->login($client, 'tapcsrf-admin@example.com');
        $client->request('POST', $seed['path'].'/erisim', [
            '_token' => 'invalid',
            'access_class' => ResourceAccessClass::EntitlementRequired->value,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->policyClass($seed['id']));
        self::assertSame($before, $this->auditCount());
    }

    public function testInstitutionAssessmentPolicyPostIsDenied(): void
    {
        $seed = $this->seedInstitutionDraft('tapinst');
        $before = $this->auditCount();
        $client = $this->newClient();
        $this->login($client, 'tapinst-sa@example.com');
        $client->request('GET', $seed['path']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Politika tanımlanmamış');
        self::assertSelectorNotExists('form[action$="/erisim"]');
        $token = $this->csrfToken($client, $seed['id']);

        $client->request('POST', $seed['path'].'/erisim', [
            '_token' => $token,
            'access_class' => ResourceAccessClass::EntitlementRequired->value,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->policyClass($seed['id']));
        self::assertSame($before, $this->auditCount());
    }

    /**
     * @return array{id: string, path: string}
     */
    private function seedPlatformDraft(string $prefix): array
    {
        $bundle = $this->seedCurriculumAndQuestion($prefix);
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var AssessmentManager $assessments */
        $assessments = $container->get(AssessmentManager::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $author = $users->findOneByNormalizedEmail(mb_strtolower($prefix.'-author@example.com'));
        self::assertInstanceOf(User::class, $author);
        $subject = $em->find(Subject::class, Uuid::fromString($bundle['subject']));
        $question = $em->find(Question::class, Uuid::fromString($bundle['question']));
        self::assertInstanceOf(Subject::class, $subject);
        self::assertInstanceOf(Question::class, $question);
        /** @var QuestionRevisionRepository $revisions */
        $revisions = $container->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        self::assertInstanceOf(QuestionRevision::class, $revision);

        $assessment = $assessments->createDraftAssessment(
            $author,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade1,
            'Politika testi '.$prefix,
            null,
            null,
            null,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [[
                'title' => 'Bölüm 1',
                'position' => 1,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $question->getId(),
                    'questionRevisionId' => $revision->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'draft_saved',
            $subject,
        );
        $id = $assessment->getId()->toRfc4122();
        self::ensureKernelShutdown();

        return ['id' => $id, 'path' => '/yonetim/testler/'.$id];
    }

    /**
     * @return array{id: string, path: string}
     */
    private function seedInstitutionDraft(string $prefix): array
    {
        $bundle = $this->seedCurriculumAndQuestion($prefix);
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var AssessmentManager $assessments */
        $assessments = $container->get(AssessmentManager::class);
        /** @var InstitutionCreator $institutions */
        $institutions = $container->get(InstitutionCreator::class);
        /** @var InstitutionStatusManager $institutionStatus */
        $institutionStatus = $container->get(InstitutionStatusManager::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $sa = $users->findOneByNormalizedEmail(mb_strtolower($prefix.'-sa@example.com'));
        $owner = $users->findOneByNormalizedEmail(mb_strtolower($prefix.'-owner@example.com'));
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(User::class, $owner);
        $institution = $institutions->create($sa, $owner, $prefix.' Okul', InstitutionType::School, 'platform_setup');
        self::assertInstanceOf(Institution::class, $institution);
        $institutionStatus->activate($institution, $sa, 'activate_ok');
        $subject = $em->find(Subject::class, Uuid::fromString($bundle['subject']));
        $question = $em->find(Question::class, Uuid::fromString($bundle['question']));
        self::assertInstanceOf(Subject::class, $subject);
        self::assertInstanceOf(Question::class, $question);
        /** @var QuestionRevisionRepository $revisions */
        $revisions = $container->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        self::assertInstanceOf(QuestionRevision::class, $revision);

        $assessment = $assessments->createDraftAssessment(
            $owner,
            AssessmentScope::Institution,
            $institution,
            AssessmentType::Quiz,
            GradeLevel::Grade1,
            'Kurum politika '.$prefix,
            null,
            null,
            null,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [[
                'title' => 'Bölüm 1',
                'position' => 1,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $question->getId(),
                    'questionRevisionId' => $revision->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'draft_saved',
            $subject,
        );
        $id = $assessment->getId()->toRfc4122();
        self::ensureKernelShutdown();

        return ['id' => $id, 'path' => '/yonetim/testler/'.$id];
    }

    /**
     * @return array{subject: string, question: string}
     */
    private function seedCurriculumAndQuestion(string $prefix): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        AssessmentDbCleanup::deleteAssessments($em->getConnection());
        QuestionBankDbCleanup::deleteTables($em->getConnection(), [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'users',
        ]);

        /** @var UserFactory $factory */
        $factory = $container->get(UserFactory::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $sa = $factory->createAndPersist($prefix.'-sa@example.com', 'Guclu-Parola-123!', 'S', 'A', UserRole::Student);
        $sa->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $sa->transitionTo(UserStatus::Active);
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $users->save($sa);
        $owner = $factory->createAndPersist($prefix.'-owner@example.com', 'Guclu-Parola-123!', 'O', 'W', UserRole::Teacher);
        $owner->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $owner->transitionTo(UserStatus::Active);
        $users->save($owner);
        $author = $factory->createAndPersist($prefix.'-author@example.com', 'Guclu-Parola-123!', 'A', 'U', UserRole::Teacher);
        $author->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $author->transitionTo(UserStatus::Active);
        $author->addGlobalRole(UserRole::Admin);
        $users->save($author);
        $publisher = $factory->createAndPersist($prefix.'-pub@example.com', 'Guclu-Parola-123!', 'P', 'U', UserRole::Teacher);
        $publisher->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $publisher->transitionTo(UserStatus::Active);
        $publisher->addGlobalRole(UserRole::Admin);
        $users->save($publisher);

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
        /** @var QuestionManager $questions */
        $questions = $container->get(QuestionManager::class);

        $subject = $subjects->create($sa, $prefix.'_s', 'Ders '.$prefix, 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade1, $prefix.'_p', 'P', '1.0', 'create_p');
        $unit = $units->create($program, $sa, $prefix.'_u', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, $prefix.'_t', 'T', 1, 'create_t');
        $outcome = $outcomes->create($topic, $sa, $prefix.'_lo', 'Kazanim', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');

        $question = $questions->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Soru '.$prefix.'?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.$prefix,
        );
        $questions->submitForReview($question, $sa, 'ready_for_review');
        $questions->publish($question, $publisher, 'publish_approved');

        $ids = [
            'subject' => $subject->getId()->toRfc4122(),
            'question' => $question->getId()->toRfc4122(),
        ];
        self::ensureKernelShutdown();

        return $ids;
    }

    private function csrfToken(KernelBrowser $client, string $assessmentId): string
    {
        $request = $client->getRequest();
        $session = $request->getSession();
        /** @var RequestStack $stack */
        $stack = $client->getContainer()->get('request_stack');
        $stack->push($request);
        try {
            /** @var CsrfTokenManagerInterface $tokens */
            $tokens = $client->getContainer()->get('security.csrf.token_manager');
            $value = $tokens->getToken('assessment_access_policy_'.$assessmentId)->getValue();
            $session->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }

    private function setPolicyDirectly(
        string $assessmentId,
        string $actorEmail,
        ResourceAccessClass $accessClass,
        string $reasonCode,
    ): void {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        /** @var AccessPackageManager $packages */
        $packages = $container->get(AccessPackageManager::class);
        /** @var AssessmentRepository $assessments */
        $assessments = $container->get(AssessmentRepository::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $assessment = $assessments->findOneById(Uuid::fromString($assessmentId));
        $actor = $users->findOneByNormalizedEmail(mb_strtolower($actorEmail));
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertInstanceOf(User::class, $actor);
        $packages->setAssessmentAccessPolicy($assessment, $actor, $accessClass, $reasonCode);
        self::ensureKernelShutdown();
    }

    private function assertLatestPolicyAudit(string $assessmentId, string $accessClass, string $reasonCode): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $event = $em->createQueryBuilder()
            ->select('e')
            ->from(SecurityAuditEvent::class, 'e')
            ->where('e.action = :action')
            ->setParameter('action', SecurityAuditAction::AssessmentAccessPolicySet)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(SecurityAuditEvent::class, $event);
        $metadata = $event->getMetadata();
        self::assertSame($assessmentId, $metadata['assessment_id'] ?? null);
        self::assertSame($assessmentId, $metadata['resource_id'] ?? null);
        self::assertSame('assessment', $metadata['resource_type'] ?? null);
        self::assertSame($accessClass, $metadata['access_class'] ?? null);
        self::assertSame($reasonCode, $metadata['reason_code'] ?? null);
        self::ensureKernelShutdown();
    }

    private function policyClass(string $assessmentId): ?ResourceAccessClass
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var AssessmentAccessPolicyRepository $policies */
        $policies = static::getContainer()->get(AssessmentAccessPolicyRepository::class);
        $policy = $policies->findForAssessment(Uuid::fromString($assessmentId));
        $class = $policy instanceof AssessmentAccessPolicy ? $policy->getAccessClass() : null;
        self::ensureKernelShutdown();

        return $class;
    }

    private function auditCount(): int
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var SecurityAuditEventRepository $events */
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        $count = $events->countByAction(SecurityAuditAction::AssessmentAccessPolicySet->value);
        self::ensureKernelShutdown();

        return $count;
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

    private function createPrivileged(string $email, UserRole $role): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Test', 'Kullanici', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        self::ensureKernelShutdown();
    }
}
