<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionTeacherInviteFailureReason;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InstitutionTeacherInviteException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\InstitutionTeacherInvitationManager;
use App\Service\InstitutionTeacherInviteSender;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class InstitutionTeacherInviteWriteTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const PASSWORD = 'Guclu-Parola-123!';

    protected function setUp(): void
    {
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnonymousTeacherPagesDoNotOpenThePanel(): void
    {
        $client = static::createClient();
        $client->request('GET', '/kurum/ogretmenler');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/kurum/ogretmenler/davet');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/davet/ogretmen');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('Davet kullanılamıyor', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->countTable('institution_memberships'));
    }

    public function testGlobalRolesCannotInviteATeacher(): void
    {
        foreach ([
            UserRole::Student,
            UserRole::Parent,
            UserRole::Teacher,
            UserRole::Moderator,
            UserRole::InstitutionManager,
            UserRole::Admin,
            UserRole::SuperAdmin,
        ] as $role) {
            $email = strtolower($role->name).'-invite@example.com';
            $this->createActive($email, $role);
            $client = static::createClient();
            $this->login($client, $email);
            $client->request('POST', '/kurum/ogretmenler/davet', ['email' => 'hedef@example.com']);
            self::assertResponseStatusCodeSame(403);
            self::ensureKernelShutdown();
        }
    }

    public function testOwnerInvitesAcceptsAssignsAndEndsWithoutDeletingHistory(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('bora-owner@example.com', UserRole::User, 'Bora', 'Demir');
        $this->createActive('yanlis@example.com', UserRole::User, 'Yanlis', 'Kişi');
        $this->openInstitution('ada-owner@example.com', 'Ada Okulu');
        $this->openInstitution('bora-owner@example.com', 'Bora Okulu');
        $this->openYear('ada-owner@example.com', 'Ada Okulu', 'Ada Donemi');
        $this->seedClassroom('ada-owner@example.com', 'Ada Okulu', 'Ada Donemi', 'Bes A');

        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $crawler = $client->request('GET', '/kurum/ogretmenler');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Henüz öğretmen yok.', $html);
        self::assertStringContainsString('Bekleyen davet yok.', $html);
        self::assertStringContainsString('nav-toggle', $html);
        self::assertGreaterThan(0, $crawler->filter('[aria-current="page"]')->count());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

        $client->request('POST', '/kurum/ogretmenler/davet', [
            'email' => 'gizli.ogretmen@example.com',
            'note' => 'gizli not buraya yazildi',
            '_token' => 'bad',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/kurum/ogretmenler/davet');
        $client->submit($crawler->selectButton('Davet gönder')->form([
            'email' => 'gizli.ogretmen@example.com',
            'note' => 'gizli not buraya yazildi',
        ]));
        self::assertResponseRedirects('/kurum/ogretmenler');
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        $body = (string) $message->getHtmlBody();
        self::assertStringContainsString('Ada Okulu', $body);
        self::assertStringContainsString('öğretmen olarak davet etti', $body);
        self::assertStringNotContainsString('gizli not buraya yazildi', $body);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $body);
        self::assertStringNotContainsString('ROLE_', $body);
        $firstToken = $this->captureToken($body);
        self::assertGreaterThanOrEqual(22, \strlen($firstToken));
        $this->assertDigestOnly($firstToken);

        $client->followRedirect();
        $list = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Davet gönderildi veya mevcut bekleyen davet güncellendi.', $list);
        self::assertStringContainsString('g***@example.com', $list);
        self::assertStringNotContainsString('gizli.ogretmen@example.com', $list);
        self::assertStringNotContainsString($firstToken, $list);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $list);
        self::assertStringNotContainsString('ROLE_', $list);

        $anon = static::createClient();
        $anon->request('GET', '/davet/ogretmen/'.$firstToken);
        self::assertResponseRedirects('/davet/ogretmen');
        $anon->followRedirect();
        self::assertStringContainsString('Hesap oluşturun', (string) $anon->getResponse()->getContent());
        self::assertSame(0, $this->countTable('institution_memberships') - 2);
        self::ensureKernelShutdown();

        $crawler = $client->request('GET', '/kurum/ogretmenler/davet');
        $client->submit($crawler->selectButton('Davet gönder')->form([
            'email' => 'gizli.ogretmen@example.com',
            'note' => 'gizli not buraya yazildi',
        ]));
        self::assertEmailCount(1);
        $second = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $second);
        $token = $this->captureToken((string) $second->getHtmlBody());
        self::assertNotSame($firstToken, $token);
        self::assertSame(1, $this->countTable('institution_teacher_invitations'));

        $client->request('GET', '/davet/ogretmen/'.$firstToken);
        self::assertResponseStatusCodeSame(404);

        $this->createActive('gizli.ogretmen@example.com', UserRole::Student, 'Deniz', 'Kaya');
        $wrong = static::createClient();
        $this->login($wrong, 'yanlis@example.com');
        $wrong->request('GET', '/davet/ogretmen/'.$token);
        $wrong->followRedirect();
        $wrong->submit($wrong->getCrawler()->selectButton('Daveti kabul et')->form());
        $wrong->followRedirect();
        self::assertStringContainsString('Davet kabul edilemedi.', (string) $wrong->getResponse()->getContent());
        self::ensureKernelShutdown();

        $teacher = static::createClient();
        $this->login($teacher, 'gizli.ogretmen@example.com');
        $teacher->request('GET', '/davet/ogretmen/'.$token);
        $teacher->followRedirect();
        $acceptHtml = (string) $teacher->getResponse()->getContent();
        self::assertStringNotContainsString($token, $acceptHtml);
        self::assertStringContainsString('Ada Okulu', $acceptHtml);
        $teacher->request('GET', '/davet/ogretmen/kabul');
        self::assertSame(2, $this->countTable('institution_memberships'));
        $teacher->request('GET', '/davet/ogretmen');
        $teacher->submit($teacher->getCrawler()->selectButton('Daveti kabul et')->form());
        self::assertResponseRedirects('/hesabim');
        $teacher->followRedirect();
        $account = (string) $teacher->getResponse()->getContent();
        self::assertStringContainsString('Ada Okulu', $account);
        self::assertStringContainsString('Öğretmen', $account);
        self::assertStringNotContainsString('ROLE_', $account);
        $teacher->request('POST', '/kurum/ogretmenler/davet', ['email' => 'baska@example.com']);
        self::assertResponseStatusCodeSame(403);
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $client->request('GET', '/kurum/siniflar');
        $crawler = $client->clickLink('Bes A');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Deniz Kaya', $html);
        $membershipRef = (string) $crawler->filter('#assign-teacher option')->attr('value');
        $assignForm = $crawler->selectButton('Öğretmen ata')->form([
            'assignment_role' => 'assistant_teacher',
        ]);
        $assignTokenField = $assignForm->get('_token');
        self::assertInstanceOf(FormField::class, $assignTokenField);
        $assignToken = $assignTokenField->getValue();
        self::assertIsString($assignToken);
        $assignUri = $assignForm->getUri();
        $client->submit($assignForm);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Deniz Kaya', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Yardımcı öğretmen', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('gizli.ogretmen@example.com', (string) $client->getResponse()->getContent());
        $client->request('POST', $assignUri, [
            'membership_reference' => $membershipRef,
            'assignment_role' => 'assistant_teacher',
            '_token' => $assignToken,
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Bu atama yapılamadı', (string) $client->getResponse()->getContent());
        $client->submit($client->getCrawler()->selectButton('Atamayı sonlandır')->form());
        $client->followRedirect();
        self::assertStringContainsString('Öğretmen ataması sonlandırıldı', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->countTable('classroom_teacher_assignments'));
        self::assertSame(0, $this->countTable('classroom_teacher_active_guards'));
        self::assertSame(0, $this->countTable('assessment_deliveries'));
        $client->request('DELETE', '/kurum/siniflar/aaaaaaaaaaaaaaaaaaaa/ogretmen/bbbbbbbbbbbbbbbbbbbb/sonlandir');
        self::assertResponseStatusCodeSame(405);

        self::ensureKernelShutdown();
        $other = static::createClient();
        $this->login($other, 'bora-owner@example.com');
        $crawler = $other->request('GET', '/kurum/ogretmenler/davet');
        $other->submit($crawler->selectButton('Davet gönder')->form([
            'email' => 'gizli.ogretmen@example.com',
        ]));
        self::assertResponseRedirects();
        self::assertSame(2, $this->countTable('institution_teacher_invitations'));
    }

    public function testManagerCanInviteAndStaffCannot(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('ada-manager@example.com', UserRole::User, 'Mete', 'Yılmaz');
        $this->createActive('ada-staff@example.com', UserRole::User, 'Seda', 'Yılmaz');
        $this->openInstitution('ada-owner@example.com', 'Ada Okulu');
        $this->addMember('ada-owner@example.com', 'ada-manager@example.com', InstitutionMembershipRole::Manager, 'Ada Okulu');
        $this->addMember('ada-owner@example.com', 'ada-staff@example.com', InstitutionMembershipRole::Staff, 'Ada Okulu');

        $client = static::createClient();
        $this->login($client, 'ada-manager@example.com');
        $crawler = $client->request('GET', '/kurum/ogretmenler/davet');
        $client->submit($crawler->selectButton('Davet gönder')->form(['email' => 'yeni.ogretmen@example.com']));
        self::assertResponseRedirects('/kurum/ogretmenler');
        self::ensureKernelShutdown();

        $staff = static::createClient();
        $this->login($staff, 'ada-staff@example.com');
        $staff->request('POST', '/kurum/ogretmenler/davet', ['email' => 'baska@example.com']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEndedMembershipAndSuspendedInstitutionCannotInvite(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('ada-manager@example.com', UserRole::User, 'Mete', 'Yılmaz');
        $this->openInstitution('ada-owner@example.com', 'Ada Okulu');
        $this->addMember('ada-owner@example.com', 'ada-manager@example.com', InstitutionMembershipRole::Manager, 'Ada Okulu');
        $this->endMembership('ada-manager@example.com', 'Ada Okulu', 'ada-owner@example.com');
        $client = static::createClient();
        $this->login($client, 'ada-manager@example.com');
        $client->request('GET', '/kurum/ogretmenler/davet');
        self::assertResponseStatusCodeSame(403);
        self::ensureKernelShutdown();

        $this->createActive('bora-owner@example.com', UserRole::User, 'Bora', 'Demir');
        $this->openInstitution('bora-owner@example.com', 'Bora Okulu');
        $this->suspend('Bora Okulu');
        $client = static::createClient();
        $this->login($client, 'bora-owner@example.com');
        $client->request('GET', '/kurum/ogretmenler/davet');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRegistrationCanAcceptOnlyAfterVerification(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->openInstitution('ada-owner@example.com', 'Ada Okulu');
        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $crawler = $client->request('GET', '/kurum/ogretmenler/davet');
        $client->submit($crawler->selectButton('Davet gönder')->form(['email' => 'yeni.kayit@example.com']));
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        $token = $this->captureToken((string) $message->getHtmlBody());
        self::ensureKernelShutdown();

        $guest = static::createClient();
        $crawler = $guest->request('GET', '/kayit/ogretmen');
        $guest->submit($crawler->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'Ece',
            'registration_form[lastName]' => 'Ak',
            'registration_form[email]' => 'yeni.kayit@example.com',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]));
        self::assertResponseRedirects('/kayit/eposta-kontrol');
        $guest->request('GET', '/davet/ogretmen/'.$token);
        $guest->followRedirect();
        $guest->request('POST', '/davet/ogretmen/kabul');
        self::assertSame(1, $this->countTable('institution_memberships'));

        $this->withKernel(static function () use ($token): void {
            $users = static::getContainer()->get(UserRepository::class);
            $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
            self::assertInstanceOf(UserRepository::class, $users);
            self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
            $user = $users->findOneByNormalizedEmail('yeni.kayit@example.com');
            self::assertInstanceOf(User::class, $user);
            self::assertSame(UserStatus::PendingVerification, $user->getStatus());
            self::assertNotContains(UserRole::Teacher->value, $user->getRoles());
            $manager = static::getContainer()->get(InstitutionTeacherInvitationManager::class);
            self::assertInstanceOf(InstitutionTeacherInvitationManager::class, $manager);
            try {
                $manager->accept($user, $token);
                self::fail('Unverified account must not accept.');
            } catch (InstitutionTeacherInviteException $exception) {
                self::assertSame(InstitutionTeacherInviteFailureReason::AccountNotReady, $exception->getReason());
            }
            $lifecycle->markEmailVerifiedAndActivate($user);
        });

        $guest = static::createClient();
        $this->login($guest, 'yeni.kayit@example.com');
        $guest->request('GET', '/davet/ogretmen/'.$token);
        $guest->followRedirect();
        $guest->submit($guest->getCrawler()->selectButton('Daveti kabul et')->form());
        self::assertResponseRedirects('/hesabim');
        $this->withKernel(static function (): void {
            $users = static::getContainer()->get(UserRepository::class);
            self::assertInstanceOf(UserRepository::class, $users);
            $user = $users->findOneByNormalizedEmail('yeni.kayit@example.com');
            self::assertInstanceOf(User::class, $user);
            self::assertNotContains(UserRole::Admin->value, $user->getRoles());
            self::assertNotContains(UserRole::Teacher->value, $user->getRoles());
            self::assertContains(UserRole::User->value, $user->getRoles());
        });
    }

    public function testInviteRateLimitAndExistingTeacher(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('aktif.ogretmen@example.com', UserRole::User, 'Aktif', 'Öğretmen');
        $this->openInstitution('ada-owner@example.com', 'Ada Okulu');
        $this->addMember('ada-owner@example.com', 'aktif.ogretmen@example.com', InstitutionMembershipRole::Teacher, 'Ada Okulu');
        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $crawler = $client->request('GET', '/kurum/ogretmenler/davet');
        $client->submit($crawler->selectButton('Davet gönder')->form(['email' => 'aktif.ogretmen@example.com']));
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('aktif bir öğretmen üyeliği var', (string) $client->getResponse()->getContent());

        for ($i = 1; $i <= 5; ++$i) {
            $crawler = $client->request('GET', '/kurum/ogretmenler/davet');
            $client->submit($crawler->selectButton('Davet gönder')->form(['email' => 'adet'.$i.'@example.com']));
        }
        $crawler = $client->request('GET', '/kurum/ogretmenler/davet');
        $client->submit($crawler->selectButton('Davet gönder')->form(['email' => 'adet6@example.com']));
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Çok fazla davet denemesi', (string) $client->getResponse()->getContent());
    }

    public function testClosedYearRejectsAssignmentAndMailFailureKeepsTheInvite(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('gizli.ogretmen@example.com', UserRole::User, 'Deniz', 'Kaya');
        $this->openInstitution('ada-owner@example.com', 'Ada Okulu');
        $this->addMember('ada-owner@example.com', 'gizli.ogretmen@example.com', InstitutionMembershipRole::Teacher, 'Ada Okulu');
        $this->openYear('ada-owner@example.com', 'Ada Okulu', 'Ada Donemi');
        $this->seedClassroom('ada-owner@example.com', 'Ada Okulu', 'Ada Donemi', 'Bes A');
        $this->closeYear('ada-owner@example.com', 'Ada Okulu');

        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $crawler = $client->request('GET', '/kurum/siniflar');
        $client->clickLink('Bes A');
        self::assertStringNotContainsString('Öğretmen ata', (string) $client->getResponse()->getContent());
        self::ensureKernelShutdown();

        $this->withKernel(function (): void {
            $em = $this->need(EntityManagerInterface::class);
            $manager = new InstitutionTeacherInvitationManager(
                $em,
                $this->need(\App\Repository\InstitutionTeacherInvitationRepository::class),
                $this->need(\App\Repository\InstitutionTeacherInvitePendingGuardRepository::class),
                $this->need(InstitutionMembershipRepository::class),
                $this->need(UserRepository::class),
                $this->need(\App\Service\InvitationCodeDigestHasher::class),
                $this->need(\App\Service\EmailNormalizer::class),
                $this->need(\App\Service\ActiveVerifiedUserPolicy::class),
                $this->need(\App\Service\InstitutionalFreshEntityLoader::class),
                $this->need(\App\Service\SecurityAuditRecorder::class),
                $this->need(\App\Security\RequestScopedInstitutionAuthLookup::class),
                new class implements InstitutionTeacherInviteSender {
                    public function send(string $recipientEmail, string $institutionName, \DateTimeImmutable $expiresAt, string $plainToken): void
                    {
                        throw new TransportException('mail down');
                    }
                },
                $this->need(\App\Service\RateLimitKeyHasher::class),
                $this->need(\Psr\Clock\ClockInterface::class),
                $this->limiter('limiter.institution_teacher_invite'),
                $this->limiter('limiter.institution_teacher_invite_resend'),
                $this->limiter('limiter.institution_teacher_invite_accept'),
            );
            $dispatch = $manager->issue($this->user('ada-owner@example.com'), $this->institution('Ada Okulu'), 'posta.yok@example.com', '');
            try {
                $manager->deliver($dispatch);
                self::fail('Mail failure should surface.');
            } catch (InstitutionTeacherInviteException $exception) {
                self::assertSame(InstitutionTeacherInviteFailureReason::MailFailed, $exception->getReason());
            }
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM institution_teacher_invitations'));
        });
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function need(string $class)
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function limiter(string $id): RateLimiterFactory
    {
        $limiter = static::getContainer()->get($id);
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);

        return $limiter;
    }

    private function captureToken(string $html): string
    {
        $found = preg_match('#/davet/ogretmen/([A-Za-z0-9_-]{43})#', $html, $matches);
        self::assertSame(1, $found);

        return $matches[1];
    }

    private function assertDigestOnly(string $plainToken): void
    {
        $this->withKernel(static function () use ($plainToken): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $digest = (string) $em->getConnection()->fetchOne('SELECT token_digest FROM institution_teacher_invitations');
            $hours = (int) $em->getConnection()->fetchOne('SELECT TIMESTAMPDIFF(HOUR, created_at, expires_at) FROM institution_teacher_invitations');
            self::assertSame(72, $hours);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
            self::assertStringNotContainsString($plainToken, $digest);
        });
    }

    private function countTable(string $table): int
    {
        $count = 0;
        $this->withKernel(static function () use (&$count, $table): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $count = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        });

        return $count;
    }

    private function seedClassroom(string $ownerEmail, string $institutionName, string $yearName, string $classroomName): void
    {
        $this->withKernel(function () use ($ownerEmail, $yearName, $classroomName): void {
            $years = static::getContainer()->get(\App\Repository\AcademicYearRepository::class);
            $classrooms = static::getContainer()->get(ClassroomManager::class);
            self::assertInstanceOf(\App\Repository\AcademicYearRepository::class, $years);
            self::assertInstanceOf(ClassroomManager::class, $classrooms);
            $year = $years->findOneBy(['name' => $yearName]);
            self::assertNotNull($year);
            $classrooms->create($year, $this->user($ownerEmail), $classroomName, GradeLevel::Grade5, 'panel_create', 'A', 24);
        });
    }

    private function closeYear(string $ownerEmail, string $institutionName): void
    {
        $this->withKernel(function () use ($ownerEmail): void {
            $years = static::getContainer()->get(\App\Repository\AcademicYearRepository::class);
            $manager = static::getContainer()->get(AcademicYearManager::class);
            self::assertInstanceOf(\App\Repository\AcademicYearRepository::class, $years);
            self::assertInstanceOf(AcademicYearManager::class, $manager);
            $year = $years->findOneBy(['name' => 'Ada Donemi']);
            self::assertNotNull($year);
            $manager->close($year, $this->user($ownerEmail), 'close_year');
        });
    }

    private function openYear(string $ownerEmail, string $institutionName, string $yearName): void
    {
        $this->withKernel(function () use ($ownerEmail, $institutionName, $yearName): void {
            $years = static::getContainer()->get(AcademicYearManager::class);
            self::assertInstanceOf(AcademicYearManager::class, $years);
            $years->createPlanned(
                $this->institution($institutionName),
                $this->user($ownerEmail),
                $yearName,
                new \DateTimeImmutable('2025-09-01'),
                new \DateTimeImmutable('2026-06-15'),
                'create_year',
            );
        });
    }

    private function openInstitution(string $ownerEmail, string $name): void
    {
        $this->withKernel(function () use ($ownerEmail, $name): void {
            $creator = static::getContainer()->get(InstitutionCreator::class);
            self::assertInstanceOf(InstitutionCreator::class, $creator);
            $creator->create($this->user('workspace-sa@example.com'), $this->user($ownerEmail), $name, InstitutionType::School, 'setup');
        });
        $this->withKernel(function () use ($name): void {
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->activate($this->institution($name), $this->user('workspace-sa@example.com'), 'activate');
        });
    }

    private function addMember(string $actorEmail, string $subjectEmail, InstitutionMembershipRole $role, string $institutionName): void
    {
        $this->withKernel(function () use ($actorEmail, $subjectEmail, $role, $institutionName): void {
            $manager = static::getContainer()->get(InstitutionMembershipManager::class);
            self::assertInstanceOf(InstitutionMembershipManager::class, $manager);
            $manager->addMember($this->institution($institutionName), $this->user($actorEmail), $this->user($subjectEmail), $role, 'add_member');
        });
    }

    private function endMembership(string $subjectEmail, string $institutionName, string $actorEmail): void
    {
        $this->withKernel(function () use ($subjectEmail, $institutionName, $actorEmail): void {
            $memberships = static::getContainer()->get(InstitutionMembershipRepository::class);
            $manager = static::getContainer()->get(InstitutionMembershipManager::class);
            self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
            self::assertInstanceOf(InstitutionMembershipManager::class, $manager);
            $membership = $memberships->findMembership($this->user($subjectEmail), $this->institution($institutionName));
            self::assertInstanceOf(InstitutionMembership::class, $membership);
            $manager->endMembership($membership, $this->user($actorEmail), 'end_member');
        });
    }

    private function suspend(string $name): void
    {
        $this->withKernel(function () use ($name): void {
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->suspend($this->institution($name), $this->user('workspace-sa@example.com'), 'suspend');
        });
    }

    private function institution(string $name): Institution
    {
        $repo = static::getContainer()->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $repo);
        $institution = $repo->findOneBy(['name' => $name]);
        self::assertInstanceOf(Institution::class, $institution);

        return $institution;
    }

    private function user(string $email): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function withKernel(callable $callback): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $callback();
        self::ensureKernelShutdown();
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function createActive(string $email, UserRole $role, string $first = 'Ayşe', string $last = 'Yılmaz'): void
    {
        $this->withKernel(static function () use ($email, $role, $first, $last): void {
            $factory = static::getContainer()->get(UserFactory::class);
            $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
            $users = static::getContainer()->get(UserRepository::class);
            self::assertInstanceOf(UserFactory::class, $factory);
            self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
            self::assertInstanceOf(UserRepository::class, $users);
            $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
            $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
            if (UserStatus::PendingVerification === $user->getStatus()) {
                $lifecycle->markEmailVerifiedAndActivate($user);
            }
            if ($initial !== $role) {
                $user->addGlobalRole($role);
                $users->save($user);
            }
        });
    }

    private function purge(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            QuestionBankDbCleanup::deleteTables($em->getConnection(), [
                'institution_teacher_invite_pending_guards',
                'institution_teacher_invitations',
                'academic_year_student_enrollment_guards',
                'classroom_student_enrollments',
                'classroom_teacher_active_guards',
                'classroom_homeroom_guards',
                'classroom_teacher_assignments',
                'classrooms',
                'institution_active_academic_year_guards',
                'academic_years',
                'institution_memberships',
                'institutions',
                'student_profiles',
                'security_audit_events',
                'users',
            ]);
            self::ensureKernelShutdown();
        } catch (\Throwable) {
        }
    }
}
