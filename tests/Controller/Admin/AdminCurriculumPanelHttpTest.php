<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\ModeratorModuleGrant;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumUnitManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Service\UserModulePermissionManager;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminCurriculumPanelHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        QuestionBankDbCleanup::deleteTables($em->getConnection(), [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
        ]);
        self::ensureKernelShutdown();
    }

    public function testAnonymousClosedAndUnverifiedActorsCannotOpenOrPost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yonetim/programlar');
        self::assertResponseRedirects('/giris');

        foreach ([
            ['http-cur-student@example.com', UserRole::Student],
            ['http-cur-teacher@example.com', UserRole::Teacher],
            ['http-cur-manager@example.com', UserRole::InstitutionManager],
        ] as [$email, $role]) {
            self::assertInstanceOf(UserRole::class, $role);
            self::ensureKernelShutdown();
            $client = static::createClient();
            $user = $this->user($email, $role);
            $this->authenticate($client, $user->getEmail());
            $client->request('GET', '/yonetim/programlar');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/yonetim/programlar/yeni', ['admin_curriculum_program_create' => ['name' => 'Gizli', 'confirm' => '1']]);
            self::assertResponseStatusCodeSame(403);
        }

        $content = $this->user('http-cur-content@example.com', UserRole::Moderator);
        $super = $this->user('http-cur-gate-sa@example.com', UserRole::SuperAdmin);
        $this->grants()->replace($super->getId(), $content->getId(), 0, [ModeratorModuleGrant::LearningContentManage->value]);
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $content->getEmail());
        $client->request('GET', '/yonetim/programlar');
        self::assertResponseStatusCodeSame(403);

        $inactive = $this->user('http-cur-inactive@example.com', UserRole::SuperAdmin);
        $inactive->transitionTo(UserStatus::Suspended);
        $this->save($inactive);
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($inactive);
        $client->request('GET', '/yonetim/programlar');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/yonetim/programlar/yeni', ['admin_curriculum_program_create' => ['confirm' => '1']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testModeratorGrantsStaySeparateAndRemovalClosesTheNextWrite(): void
    {
        $super = $this->user('http-cur-perm-sa@example.com', UserRole::SuperAdmin);
        $viewer = $this->user('http-cur-view@example.com', UserRole::Moderator);
        $editor = $this->user('http-cur-edit@example.com', UserRole::Moderator);
        $admin = $this->user('http-cur-admin@example.com', UserRole::Admin);
        $this->grants()->replace($super->getId(), $viewer->getId(), 0, [ModeratorModuleGrant::CurriculumView->value]);
        $this->grants()->replace($super->getId(), $editor->getId(), 0, [ModeratorModuleGrant::CurriculumManage->value]);
        $subject = $this->subjects()->create($super, 'http_cur_perm', 'Izin dersi', 'curriculum_setup');
        $program = $this->programs()->createDraft($subject, $super, GradeLevel::Grade7, 'http_cur_perm', 'Izin programi', '1.0', 'curriculum_setup');
        $publishedBefore = $this->auditCount(SecurityAuditAction::CurriculumPublished);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $admin->getEmail());
        $client->request('GET', '/yonetim/programlar');
        self::assertResponseIsSuccessful();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $viewer->getEmail());
        $client->request('GET', '/yonetim/programlar');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/yonetim/programlar/yeni"]');
        $client->request('GET', '/yonetim/programlar/'.$program->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $client->request('POST', '/yonetim/programlar/'.$program->getId()->toRfc4122().'/yayinla', [
            'admin_curriculum_publish' => ['reasonCode' => 'curriculum_publish', 'confirm' => '1'],
        ]);
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $editor->getEmail());
        $client->request('POST', '/yonetim/programlar/'.$program->getId()->toRfc4122().'/yayinla', [
            'admin_curriculum_publish' => ['reasonCode' => 'curriculum_publish', 'confirm' => '1'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('draft', $this->reload($program->getId())->getStatus()->value);
        self::assertSame($publishedBefore, $this->auditCount(SecurityAuditAction::CurriculumPublished));

        $this->grants()->replace($super->getId(), $editor->getId(), 1, []);
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $editor->getEmail());
        $client->request('GET', '/yonetim/programlar');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/yonetim/programlar/'.$program->getId()->toRfc4122().'/ad', [
            'admin_curriculum_rename_program' => ['title' => 'Sessiz', 'reasonCode' => 'curriculum_correction', 'confirm' => '1'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Izin programi', $this->reload($program->getId())->getName());
    }

    public function testCsrfWrongParentAndPublishedRenameDoNotWrite(): void
    {
        $super = $this->user('http-cur-form-sa@example.com', UserRole::SuperAdmin);
        $subject = $this->subjects()->create($super, 'http_cur_form', 'Form dersi', 'curriculum_setup');
        $home = $this->programs()->createDraft($subject, $super, GradeLevel::Grade8, 'http_cur_home', 'Ev programi', '1.0', 'curriculum_setup');
        $other = $this->programs()->createDraft($subject, $super, GradeLevel::Grade8, 'http_cur_other', 'Diger program', '1.0', 'curriculum_setup');
        $this->units()->create($home, $super, 'http_cur_u', 'Ev unite', 1, 'curriculum_setup');
        $foreign = $this->units()->create($other, $super, 'http_cur_f', 'Yabanci unite', 1, 'curriculum_setup');
        $this->programs()->publish($other, $super, 'curriculum_publish');
        $renames = $this->auditCount(SecurityAuditAction::CurriculumProgramUpdated);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $super->getEmail());
        $client->request('POST', '/yonetim/programlar/'.$home->getId()->toRfc4122().'/ad', [
            'admin_curriculum_rename_program' => ['title' => 'Csrf yok', 'reasonCode' => 'curriculum_correction', 'confirm' => '1'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Ev programi', $this->reload($home->getId())->getName());
        self::assertSame($renames, $this->auditCount(SecurityAuditAction::CurriculumProgramUpdated));

        $crawler = $client->request('GET', '/yonetim/programlar/yeni');
        $form = $crawler->selectButton('Taslak programı oluştur')->form();
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Kod gerekli.');

        $client->request('GET', '/yonetim/programlar/'.$other->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $token = $this->csrf($client, 'admin_curriculum_rename_'.$other->getId()->toRfc4122());
        $client->request('POST', '/yonetim/programlar/'.$other->getId()->toRfc4122().'/ad', [
            'admin_curriculum_rename_program' => [
                '_token' => $token,
                'title' => 'Yayin adi',
                'reasonCode' => 'curriculum_correction',
                'confirm' => '1',
            ],
        ]);
        self::assertResponseRedirects();
        self::assertSame('Diger program', $this->reload($other->getId())->getName());
        self::assertSame($renames, $this->auditCount(SecurityAuditAction::CurriculumProgramUpdated));

        $client->request('GET', '/yonetim/programlar/'.$home->getId()->toRfc4122());
        $topicToken = $this->csrf($client, 'admin_curriculum_topic_'.$home->getId()->toRfc4122());
        $beforeTopics = $this->countTopics($home->getId());
        $client->request('POST', '/yonetim/programlar/'.$home->getId()->toRfc4122().'/konu', [
            'curriculum_topic' => [
                '_token' => $topicToken,
                'parentId' => $foreign->getId()->toRfc4122(),
                'code' => 'http_cur_cross',
                'title' => 'Capraz konu',
                'position' => '1',
                'reasonCode' => 'curriculum_setup',
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Üst kayıt bu programda yok.');
        self::assertSame($beforeTopics, $this->countTopics($home->getId()));
        self::assertNull($this->topicByCode('http_cur_cross'));
    }

    private function csrf(KernelBrowser $client, string $id): string
    {
        $manager = $client->getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);

        return $manager->getToken($id)->getValue();
    }

    private function authenticate(KernelBrowser $client, string $email): void
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        $client->loginUser($user);
    }

    private function user(string $email, UserRole $role, bool $verified = true): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', 'Soyad', $initial);
        if ($verified) {
            $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
            $user->transitionTo(UserStatus::Active);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $this->save($user);

        return $user;
    }

    private function save(User $user): void
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);
    }

    private function subjects(): SubjectManager
    {
        $service = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $service);

        return $service;
    }

    private function programs(): CurriculumProgramManager
    {
        $service = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $service);

        return $service;
    }

    private function units(): CurriculumUnitManager
    {
        $service = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $service);

        return $service;
    }

    private function grants(): UserModulePermissionManager
    {
        $service = static::getContainer()->get(UserModulePermissionManager::class);
        self::assertInstanceOf(UserModulePermissionManager::class, $service);

        return $service;
    }

    private function reload(Uuid $id): CurriculumProgram
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $program = $em->find(CurriculumProgram::class, $id);
        self::assertInstanceOf(CurriculumProgram::class, $program);

        return $program;
    }

    private function auditCount(SecurityAuditAction $action): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = :action',
            ['action' => $action->value],
        );
    }

    private function countTopics(Uuid $programId): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM curriculum_topics t INNER JOIN curriculum_units u ON u.id = t.unit_id WHERE u.curriculum_program_id = :id',
            ['id' => $programId->toBinary()],
            ['id' => \Doctrine\DBAL\ParameterType::BINARY],
        );
    }

    private function topicByCode(string $code): ?CurriculumTopic
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em->getRepository(CurriculumTopic::class)->findOneBy(['code' => $code]);
    }
}
