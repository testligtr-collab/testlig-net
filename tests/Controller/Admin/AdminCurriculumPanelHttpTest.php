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
use App\Service\CurriculumAccessGateManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Service\UserModulePermissionManager;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
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
        $grade1 = $client->getCrawler()->filter('form[action$="/sinif/1/ad"] input[name="admin_curriculum_rename_gate[title]"]');
        $grade12 = $client->getCrawler()->filter('form[action$="/sinif/12/ad"] input[name="admin_curriculum_rename_gate[title]"]');
        self::assertSame('1. sınıf', $grade1->attr('value'));
        self::assertSame('12. sınıf', $grade12->attr('value'));
        $chip12 = $client->getCrawler()->filter('a[href*="sinif=12"]');
        self::assertGreaterThan(0, $chip12->count());
        self::assertStringContainsString('Aktif', $chip12->text());

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

    public function testAvailabilityReopensWithoutChangingChildrenAndOtherGrantsCannot(): void
    {
        $super = $this->user('http-cur-av-sa@example.com', UserRole::SuperAdmin);
        $manage = $this->user('http-cur-av-manage@example.com', UserRole::Moderator);
        $archive = $this->user('http-cur-av-archive@example.com', UserRole::Moderator);
        $availability = $this->user('http-cur-av-open@example.com', UserRole::Moderator);
        $this->grants()->replace($super->getId(), $manage->getId(), 0, [ModeratorModuleGrant::CurriculumManage->value]);
        $this->grants()->replace($super->getId(), $archive->getId(), 0, [ModeratorModuleGrant::CurriculumArchive->value]);
        $this->grants()->replace($super->getId(), $availability->getId(), 0, [
            ModeratorModuleGrant::CurriculumView->value,
            ModeratorModuleGrant::CurriculumAvailability->value,
        ]);
        $subject = $this->subjects()->create($super, 'http_cur_av', 'Erisim dersi', 'curriculum_setup');
        $program = $this->programs()->createDraft($subject, $super, GradeLevel::Grade10, 'http_cur_av', 'Erisim programi', '1.0', 'curriculum_setup');
        $unit = $this->units()->create($program, $super, 'http_cur_av_u', 'Erisim unite', 1, 'curriculum_setup');
        $topic = $this->topics()->createRoot($unit, $super, 'http_cur_av_t', 'Erisim konu', 1, 'curriculum_setup');
        $outcome = $this->outcomes()->create($topic, $super, 'http_cur_av_o', 'Erisim kazanim', 1, 'curriculum_setup');
        $this->programs()->publish($program, $super, 'curriculum_publish');
        $programId = $program->getId()->toRfc4122();
        $unitId = $unit->getId();
        $path = '/yonetim/programlar/'.$programId.'/unite/'.$unitId->toRfc4122().'/erisim/pasif';
        $audits = $this->auditCount(SecurityAuditAction::CurriculumUnitUpdated);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $manage->getEmail());
        $client->request('POST', $path, [
            'admin_curriculum_passive_unite' => ['reasonCode' => 'curriculum_passive', 'confirm' => '1'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('active', $this->nodeStatus('curriculum_units', $unitId));
        self::assertSame($audits, $this->auditCount(SecurityAuditAction::CurriculumUnitUpdated));

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $archive->getEmail());
        $client->request('POST', $path, [
            'admin_curriculum_passive_unite' => ['reasonCode' => 'curriculum_passive', 'confirm' => '1'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('active', $this->nodeStatus('curriculum_units', $unitId));
        self::assertSame('active', $this->nodeStatus('curriculum_topics', $topic->getId()));
        self::assertSame('active', $this->nodeStatus('curriculum_learning_outcomes', $outcome->getId()));

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $availability->getEmail());
        $client->request('GET', '/yonetim/programlar/'.$programId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button:contains("Üniteyi pasif yap")');
        $client->request('POST', $path, [
            'admin_curriculum_passive_unite' => ['reasonCode' => 'curriculum_passive', 'confirm' => '1'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('active', $this->nodeStatus('curriculum_units', $unitId));
        self::assertSame($audits, $this->auditCount(SecurityAuditAction::CurriculumUnitUpdated));

        $client->request('GET', '/yonetim/programlar/'.$programId);
        $token = $this->csrf($client, 'admin_curriculum_pasif_'.$unitId->toRfc4122());
        $client->request('POST', $path, [
            'admin_curriculum_passive_unite' => [
                '_token' => $token,
                'reasonCode' => 'curriculum_passive',
                'confirm' => '1',
            ],
        ]);
        self::assertResponseRedirects('/yonetim/programlar/'.$programId);
        self::assertSame('inactive', $this->nodeStatus('curriculum_units', $unitId));
        self::assertSame('active', $this->nodeStatus('curriculum_topics', $topic->getId()));
        self::assertSame('active', $this->nodeStatus('curriculum_learning_outcomes', $outcome->getId()));
        self::assertSame($audits + 1, $this->auditCount(SecurityAuditAction::CurriculumUnitUpdated));

        $client->request('GET', '/yonetim/programlar/'.$programId);
        self::assertSelectorTextContains('body', 'Bu ünitenin kendi durumu pasif.');
        self::assertSelectorTextContains('body', 'Üst kayıt nedeniyle öğrenciye kapalı. Konunun kendi durumu değişmedi.');
        $activeToken = $this->csrf($client, 'admin_curriculum_aktif_'.$unitId->toRfc4122());
        $client->request('POST', '/yonetim/programlar/'.$programId.'/unite/'.$unitId->toRfc4122().'/erisim/aktif', [
            'admin_curriculum_active_unite' => [
                '_token' => $activeToken,
                'reasonCode' => 'curriculum_activate',
                'confirm' => '1',
            ],
        ]);
        self::assertResponseRedirects('/yonetim/programlar/'.$programId);
        self::assertSame('active', $this->nodeStatus('curriculum_units', $unitId));
        self::assertSame('active', $this->nodeStatus('curriculum_topics', $topic->getId()));
        self::assertSame('active', $this->nodeStatus('curriculum_learning_outcomes', $outcome->getId()));
        self::assertSame('http_cur_av', $this->reload($program->getId())->getCode());
        self::assertSame('1.0', $this->reload($program->getId())->getVersion());
    }

    public function testParentAccessGrantsStaySeparateAndDoNotRewriteChildren(): void
    {
        $super = $this->user('http-cur-parent-sa@example.com', UserRole::SuperAdmin);
        $manage = $this->user('http-cur-parent-manage@example.com', UserRole::Moderator);
        $archive = $this->user('http-cur-parent-archive@example.com', UserRole::Moderator);
        $availability = $this->user('http-cur-parent-open@example.com', UserRole::Moderator);
        $this->grants()->replace($super->getId(), $manage->getId(), 0, [ModeratorModuleGrant::CurriculumManage->value]);
        $this->grants()->replace($super->getId(), $archive->getId(), 0, [ModeratorModuleGrant::CurriculumArchive->value]);
        $this->grants()->replace($super->getId(), $availability->getId(), 0, [
            ModeratorModuleGrant::CurriculumView->value,
            ModeratorModuleGrant::CurriculumAvailability->value,
        ]);
        $subject = $this->subjects()->create($super, 'http_cur_parent', 'Ust ders', 'curriculum_setup');
        $program = $this->programs()->createDraft($subject, $super, GradeLevel::Grade12, 'http_cur_parent', 'Ust program', '1.0', 'curriculum_setup');
        $unit = $this->units()->create($program, $super, 'http_cur_parent_u', 'Ust unite', 1, 'curriculum_setup');
        $this->programs()->publish($program, $super, 'curriculum_publish');
        $programId = $program->getId()->toRfc4122();
        $subjectId = $subject->getId()->toRfc4122();
        $programPath = '/yonetim/programlar/'.$programId.'/erisim/pasif';
        $subjectPath = '/yonetim/programlar/ders/'.$subjectId.'/erisim/pasif';
        $gradePath = '/yonetim/programlar/sinif/12/erisim/pasif';

        try {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->authenticate($client, $manage->getEmail());
            $client->request('POST', $programPath, [
                'admin_curriculum_passive_program' => ['reasonCode' => 'curriculum_passive', 'confirm' => '1'],
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $subjectPath, [
                'admin_curriculum_passive_subject' => ['reasonCode' => 'curriculum_passive', 'confirm' => '1'],
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $gradePath, [
                'admin_curriculum_passive_gate' => ['reasonCode' => 'curriculum_passive', 'confirm' => '1'],
            ]);
            self::assertResponseStatusCodeSame(403);

            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->authenticate($client, $archive->getEmail());
            $client->request('POST', $programPath, [
                'admin_curriculum_passive_program' => ['reasonCode' => 'curriculum_passive', 'confirm' => '1'],
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('published', $this->programColumn($program->getId(), 'status'));
            self::assertSame('active', $this->programColumn($program->getId(), 'student_access'));
            self::assertSame('active', $this->nodeStatus('subjects', $subject->getId()));
            self::assertSame('active', $this->nodeStatus('curriculum_units', $unit->getId()));

            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->authenticate($client, $availability->getEmail());
            $client->request('POST', $programPath, [
                'admin_curriculum_passive_program' => ['reasonCode' => 'curriculum_passive', 'confirm' => '1'],
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/yonetim/programlar/'.$programId);
            $token = $this->csrf($client, 'admin_curriculum_pasif_'.$programId);
            $client->request('POST', $programPath, [
                'admin_curriculum_passive_program' => [
                    '_token' => $token,
                    'reasonCode' => 'curriculum_passive',
                    'confirm' => '1',
                ],
            ]);
            self::assertResponseRedirects('/yonetim/programlar/'.$programId);
            self::assertSame('published', $this->programColumn($program->getId(), 'status'));
            self::assertSame('inactive', $this->programColumn($program->getId(), 'student_access'));
            self::assertSame('http_cur_parent', $this->reload($program->getId())->getCode());
            self::assertSame('1.0', $this->reload($program->getId())->getVersion());
            self::assertSame('active', $this->nodeStatus('curriculum_units', $unit->getId()));

            $client->request('GET', '/yonetim/programlar/'.$programId);
            $subjectToken = $this->csrf($client, 'admin_curriculum_pasif_'.$subjectId);
            $client->request('POST', $subjectPath, [
                'admin_curriculum_passive_subject' => [
                    '_token' => $subjectToken,
                    'reasonCode' => 'curriculum_passive',
                    'confirm' => '1',
                ],
            ]);
            self::assertResponseRedirects('/yonetim/programlar/yeni');
            self::assertSame('inactive', $this->nodeStatus('subjects', $subject->getId()));
            self::assertSame('published', $this->programColumn($program->getId(), 'status'));
            self::assertSame('active', $this->nodeStatus('curriculum_units', $unit->getId()));

            $client->request('GET', '/yonetim/programlar');
            $gradeToken = $this->csrf($client, 'admin_curriculum_pasif_gate_grade_12');
            $client->request('POST', $gradePath, [
                'admin_curriculum_passive_gate' => [
                    '_token' => $gradeToken,
                    'reasonCode' => 'curriculum_passive',
                    'confirm' => '1',
                ],
            ]);
            self::assertResponseRedirects('/yonetim/programlar');
            self::assertSame('inactive', $this->gateStatus('grade', '12'));
            self::assertSame(12, $this->reload($program->getId())->getGradeLevel()->value);
            self::assertSame('active', $this->nodeStatus('curriculum_units', $unit->getId()));
        } finally {
            self::ensureKernelShutdown();
            self::bootKernel();
            $gates = static::getContainer()->get(CurriculumAccessGateManager::class);
            self::assertInstanceOf(CurriculumAccessGateManager::class, $gates);
            if ('inactive' === $this->gateStatus('grade', '12')) {
                $gates->reactivate('grade', '12', $super, 'curriculum_activate');
            }
        }
    }

    private function csrf(KernelBrowser $client, string $id): string
    {
        $request = $client->getRequest();
        $session = $request->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        try {
            $manager = $client->getContainer()->get(CsrfTokenManagerInterface::class);
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);
            $token = $manager->getToken($id)->getValue();
        } finally {
            $stack->pop();
        }
        $session->save();

        return $token;
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

    private function topics(): CurriculumTopicManager
    {
        $service = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $service);

        return $service;
    }

    private function outcomes(): CurriculumLearningOutcomeManager
    {
        $service = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $service);

        return $service;
    }

    private function nodeStatus(string $table, Uuid $id): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        return (string) $em->getConnection()->fetchOne(
            'SELECT status FROM '.$table.' WHERE id = :id',
            ['id' => $id->toBinary()],
            ['id' => \Doctrine\DBAL\ParameterType::BINARY],
        );
    }

    private function programColumn(Uuid $id, string $column): string
    {
        if (!\in_array($column, ['status', 'student_access'], true)) {
            self::fail('Unexpected column.');
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        return (string) $em->getConnection()->fetchOne(
            \sprintf('SELECT %s FROM curriculum_programs WHERE id = :id', $column),
            ['id' => $id->toBinary()],
            ['id' => \Doctrine\DBAL\ParameterType::BINARY],
        );
    }

    private function gateStatus(string $kind, string $key): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        return (string) $em->getConnection()->fetchOne(
            'SELECT status FROM curriculum_access_gates WHERE kind = :kind AND gate_key = :key',
            ['kind' => $kind, 'key' => $key],
        );
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
