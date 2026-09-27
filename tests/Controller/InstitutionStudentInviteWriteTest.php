<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AcademicYearRepository;
use App\Repository\ClassroomRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\InstitutionStudentInvitationManager;
use App\Service\InvitationCodeDigestHasher;
use App\Service\StudentProfileManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class InstitutionStudentInviteWriteTest extends WebTestCase
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

    public function testAnonymousStudentInviteScreenIsEmptyAndPrivate(): void
    {
        $client = static::createClient();
        $client->request('GET', '/kurum/ogrenciler');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/davet/ogrenci');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('Davet kullanılamıyor', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->countTable('classroom_student_enrollments'));
        self::assertSame(0, $this->countTable('institution_student_invitations'));
    }

    public function testGlobalRolesCannotInviteAStudent(): void
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
            $email = strtolower($role->name).'-student-invite@example.com';
            $this->createActive($email, $role);
            $client = static::createClient();
            $this->login($client, $email);
            $client->request('POST', '/kurum/siniflar/'.str_repeat('a', 20).'/ogrenci-davet', ['email' => 'hedef@example.com']);
            self::assertResponseStatusCodeSame(403);
            self::ensureKernelShutdown();
        }
        self::assertSame(0, $this->countTable('institution_student_invitations'));
    }

    public function testOwnerAndManagerInviteAcceptEndAndTransfer(): void
    {
        $this->bootSchool();
        $this->addMember('ada-owner@example.com', 'mina-manager@example.com', InstitutionMembershipRole::Manager, 'Ada Okulu');
        $classroom = $this->classroomReference('Bes A');
        $otherClassroom = $this->classroomReference('Bes B');

        $client = static::createClient();
        $this->login($client, 'mina-manager@example.com');
        $client->request('GET', '/kurum/siniflar/'.$classroom);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Öğrenci davet et', (string) $client->getResponse()->getContent());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');

        $client->request('POST', '/kurum/siniflar/'.$classroom.'/ogrenci-davet', [
            'email' => 'yeni.ogrenci@example.com',
            '_token' => 'bad',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/kurum/siniflar/'.$classroom.'/ogrenci-davet');
        $client->submit($crawler->selectButton('Davet gönder')->form([
            'email' => 'yeni.ogrenci@example.com',
            'note' => 'gizli ogrenci notu',
        ]));
        self::assertResponseRedirects('/kurum/siniflar/'.$classroom);
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        $body = (string) $message->getHtmlBody();
        self::assertStringContainsString('Ada Okulu', $body);
        self::assertStringContainsString('Bes A', $body);
        self::assertStringContainsString('öğrenci olarak davet etti', $body);
        self::assertStringContainsString('72 saat', $body);
        self::assertStringNotContainsString('gizli ogrenci notu', $body);
        self::assertStringNotContainsString('ada-owner@example.com', $body);
        self::assertStringNotContainsString('mina-manager@example.com', $body);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $body);
        self::assertStringNotContainsString('ROLE_', $body);
        $token = $this->captureToken($body);
        self::assertSame(43, \strlen($token));
        $this->assertDigestOnly($token);
        self::assertSame(72, $this->inviteTtlHours());

        $client->followRedirect();
        $list = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Davet gönderildi veya mevcut bekleyen davet güncellendi.', $list);
        self::assertStringContainsString('y***@example.com', $list);
        self::assertStringNotContainsString('yeni.ogrenci@example.com', $list);
        self::assertStringNotContainsString('gizli ogrenci notu', $list);
        self::assertStringNotContainsString($token, $list);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $list);

        $missing = static::createClient();
        $this->login($missing, 'ada-owner@example.com');
        $ownerClassroom = $this->classroomReference('Bes A');
        $form = $missing->request('GET', '/kurum/siniflar/'.$ownerClassroom.'/ogrenci-davet');
        $missing->submit($form->selectButton('Davet gönder')->form([
            'email' => 'olmayan@example.com',
            'note' => '',
        ]));
        self::assertResponseRedirects('/kurum/siniflar/'.$ownerClassroom);
        $missing->followRedirect();
        self::assertStringContainsString('Davet gönderildi veya mevcut bekleyen davet güncellendi.', (string) $missing->getResponse()->getContent());
        self::ensureKernelShutdown();

        $before = $this->countTable('classroom_student_enrollments');
        $anon = static::createClient();
        $anon->request('GET', '/davet/ogrenci/'.$token);
        self::assertResponseRedirects('/davet/ogrenci');
        $anon->followRedirect();
        $review = (string) $anon->getResponse()->getContent();
        self::assertStringContainsString('Hesabınız varsa giriş yapın', $review);
        self::assertStringContainsString('Hesabınız yoksa öğrenci kaydı oluşturun', $review);
        self::assertStringNotContainsString($token, $review);
        self::assertSame($before, $this->clientCount($anon, 'classroom_student_enrollments'));

        $register = $anon->request('GET', '/kayit/ogrenci');
        $anon->submit($register->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'Ece',
            'registration_form[lastName]' => 'Ak',
            'registration_form[email]' => 'yeni.ogrenci@example.com',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]));
        self::assertResponseRedirects('/kayit/eposta-kontrol');
        self::assertEmailCount(1);
        $verifyMail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $verifyMail);
        $verifyPath = $this->verifyPath((string) $verifyMail->getHtmlBody());
        $anon->request('GET', $verifyPath);
        self::assertResponseRedirects('/giris');
        $this->login($anon, 'yeni.ogrenci@example.com');
        $anon->request('GET', '/davet/ogrenci/'.$token);
        self::assertResponseRedirects('/davet/ogrenci');
        $reviewPage = $anon->followRedirect();
        $anon->submit($reviewPage->selectButton('Daveti kabul et')->form());
        self::assertResponseRedirects('/davet/ogrenci');
        $anon->followRedirect();
        self::assertStringContainsString('profil kurulumunun tamamlanmış', (string) $anon->getResponse()->getContent());
        self::assertSame(0, $this->clientCount($anon, 'classroom_student_enrollments'));
        self::assertNull($this->clientScalar($anon, 'SELECT consumed_at FROM institution_student_invitations WHERE normalized_email = ?', ['yeni.ogrenci@example.com']));

        $setup = $anon->request('GET', '/ogrenci/kurulum');
        $anon->submit($setup->selectButton('Profilimi tamamla')->form([
            'student_profile[gradeLevel]' => (string) GradeLevel::Grade5->value,
        ]));
        self::assertResponseRedirects('/ogrenci');

        $accept = $anon->request('GET', '/davet/ogrenci');
        $anon->submit($accept->selectButton('Daveti kabul et')->form());
        self::assertResponseRedirects('/ogrenci');
        $anon->followRedirect();
        $panel = (string) $anon->getResponse()->getContent();
        self::assertStringContainsString('Ada Okulu', $panel);
        self::assertStringContainsString('Bes A', $panel);
        self::assertStringNotContainsString('ada-owner@example.com', $panel);
        self::assertStringNotContainsString('mina-manager@example.com', $panel);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $panel);
        self::assertStringNotContainsString($token, $panel);
        $anon->request('GET', '/kurum');
        self::assertResponseStatusCodeSame(403);
        $anon->request('POST', '/kurum/siniflar/'.$classroom.'/ogrenci/'.str_repeat('b', 20).'/sonlandir');
        self::assertResponseStatusCodeSame(403);
        self::ensureKernelShutdown();

        self::assertSame(1, $this->countTable('classroom_student_enrollments'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM institution_memberships WHERE role = ?', ['student']));
        self::assertNotNull($this->scalar('SELECT consumed_at FROM institution_student_invitations WHERE normalized_email = ?', ['yeni.ogrenci@example.com']));

        $owner = static::createClient();
        $this->login($owner, 'ada-owner@example.com');
        $page = $owner->request('GET', '/kurum/siniflar/'.$classroom);
        $html = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Ece Ak', $html);
        self::assertStringNotContainsString('yeni.ogrenci@example.com', $html);
        $attemptsBefore = $this->clientCount($owner, 'assessment_attempts');
        $owner->submit($page->selectButton('Aktar')->form());
        self::assertResponseRedirects('/kurum/siniflar/'.$classroom);
        $owner->followRedirect();
        self::assertStringContainsString('başka sınıfa aktarıldı', (string) $owner->getResponse()->getContent());
        self::assertSame(2, $this->clientCount($owner, 'classroom_student_enrollments'));
        self::assertSame(1, (int) $this->clientScalar($owner, "SELECT COUNT(*) FROM classroom_student_enrollments WHERE status = 'active'"));
        self::assertSame(1, (int) $this->clientScalar($owner, 'SELECT COUNT(*) FROM classroom_student_enrollments WHERE transferred_at IS NOT NULL'));

        $moved = $owner->request('GET', '/kurum/siniflar/'.$otherClassroom);
        self::assertStringContainsString('Ece Ak', (string) $owner->getResponse()->getContent());
        $owner->submit($moved->selectButton('Kaydı sonlandır')->form());
        self::assertResponseRedirects('/kurum/siniflar/'.$otherClassroom);
        $owner->followRedirect();
        self::assertStringNotContainsString('Ece Ak', (string) $owner->getResponse()->getContent());
        self::assertSame(2, $this->countTable('classroom_student_enrollments'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM classroom_student_enrollments WHERE status = 'active'"));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM institution_memberships WHERE role = ?', ['student']));
        self::assertSame($attemptsBefore, $this->countTable('assessment_attempts'));
        self::assertSame($attemptsBefore, $this->countTable('assessment_attempt_answers'));
    }

    public function testInviteRejectsClosedYearOtherInstitutionAndKeepsTeacherContextSeparate(): void
    {
        $this->bootSchool();
        $reference = $this->classroomReference('Bes A');
        $teacherToken = '';
        $this->withKernel(function () use ($reference, &$teacherToken): void {
            $hasher = static::getContainer()->get(InvitationCodeDigestHasher::class);
            self::assertInstanceOf(InvitationCodeDigestHasher::class, $hasher);
            $plain = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';
            self::assertNotSame($hasher->hashInstitutionTeacherInvite($plain), $hasher->hashInstitutionStudentInvite($plain));
            $teachers = static::getContainer()->get(\App\Service\InstitutionTeacherInvitationManager::class);
            $students = static::getContainer()->get(InstitutionStudentInvitationManager::class);
            self::assertInstanceOf(\App\Service\InstitutionTeacherInvitationManager::class, $teachers);
            self::assertInstanceOf(InstitutionStudentInvitationManager::class, $students);
            $owner = $this->user('ada-owner@example.com');
            $teacher = $teachers->issue($owner, $this->institution('Ada Okulu'), 'ogretmen.ayri@example.com', '');
            $teacherToken = $teacher->plainToken;
            self::assertNull($students->preview($teacherToken));
            $student = $students->issue($owner, $this->institution('Ada Okulu'), $reference, 'hazir@example.com', '');
            self::assertSame(43, \strlen($student->plainToken));
            self::assertNull($teachers->preview($student->plainToken));
        });

        $client = static::createClient();
        $client->request('GET', '/davet/ogrenci/'.$teacherToken);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countTable('classroom_student_enrollments'));
        self::ensureKernelShutdown();

        $other = static::createClient();
        $this->login($other, 'bora-owner@example.com');
        $other->request('GET', '/kurum/siniflar/'.$reference);
        self::assertResponseStatusCodeSame(404);
        self::ensureKernelShutdown();

        $this->closeYear();
        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $form = $client->request('GET', '/kurum/siniflar/'.$reference.'/ogrenci-davet');
        $client->submit($form->selectButton('Davet gönder')->form([
            'email' => 'kapali@example.com',
            'note' => '',
        ]));
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('eğitim dönemi şu anda davete açık değil', (string) $client->getResponse()->getContent());
    }

    public function testWrongAccountGradeAndUnreadyUserDoNotEnrollOrMutateProfile(): void
    {
        $this->bootSchool();
        $reference = $this->classroomReference('Bes A');
        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $client->submit($client->request('GET', '/kurum/siniflar/'.$reference.'/ogrenci-davet')->selectButton('Davet gönder')->form([
            'email' => 'hazir5@example.com',
            'note' => '',
        ]));
        self::assertEmailCount(1);
        $token = $this->captureToken($this->htmlBody());
        self::ensureKernelShutdown();

        $this->createReadyStudent('yanlis@example.com', GradeLevel::Grade5);
        $wrong = static::createClient();
        $this->login($wrong, 'yanlis@example.com');
        $wrong->request('GET', '/davet/ogrenci/'.$token);
        $wrong->submit($wrong->request('GET', '/davet/ogrenci')->selectButton('Daveti kabul et')->form());
        $wrong->followRedirect();
        self::assertStringContainsString('Davet kabul edilemedi.', (string) $wrong->getResponse()->getContent());
        self::assertSame(0, $this->countTable('classroom_student_enrollments'));
        self::assertNull($this->scalar('SELECT consumed_at FROM institution_student_invitations WHERE normalized_email = ?', ['hazir5@example.com']));
        self::ensureKernelShutdown();

        $this->createReadyStudent('hazir5@example.com', GradeLevel::Grade4);
        $gradeBefore = $this->scalar('SELECT grade_level FROM student_profiles sp INNER JOIN users u ON u.id = sp.user_id WHERE u.email = ?', ['hazir5@example.com']);
        $classroomGrade = $this->scalar('SELECT grade_level FROM classrooms WHERE name = ?', ['Bes A']);
        $mismatch = static::createClient();
        $this->login($mismatch, 'hazir5@example.com');
        $mismatch->request('GET', '/davet/ogrenci/'.$token);
        $mismatch->submit($mismatch->request('GET', '/davet/ogrenci')->selectButton('Daveti kabul et')->form());
        $mismatch->followRedirect();
        self::assertStringContainsString('Sınıf seviyeniz davet edilen sınıfla uyuşmuyor.', (string) $mismatch->getResponse()->getContent());
        self::assertSame($gradeBefore, $this->scalar('SELECT grade_level FROM student_profiles sp INNER JOIN users u ON u.id = sp.user_id WHERE u.email = ?', ['hazir5@example.com']));
        self::assertSame($classroomGrade, $this->scalar('SELECT grade_level FROM classrooms WHERE name = ?', ['Bes A']));
        self::assertSame(0, $this->countTable('classroom_student_enrollments'));
        self::ensureKernelShutdown();

        $this->withKernel(function () use ($reference): void {
            $users = static::getContainer()->get(UserRepository::class);
            $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
            $factory = static::getContainer()->get(UserFactory::class);
            self::assertInstanceOf(UserRepository::class, $users);
            self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
            self::assertInstanceOf(UserFactory::class, $factory);
            $pending = $factory->createAndPersist('beklemede@example.com', self::PASSWORD, 'Bekle', 'Me', UserRole::Student);
            self::assertSame(UserStatus::PendingVerification, $pending->getStatus());
            $manager = static::getContainer()->get(InstitutionStudentInvitationManager::class);
            self::assertInstanceOf(InstitutionStudentInvitationManager::class, $manager);
            $owner = $this->user('ada-owner@example.com');
            $dispatch = $manager->issue($owner, $this->institution('Ada Okulu'), $reference, 'beklemede@example.com', '');
            $caught = false;
            try {
                $manager->accept($pending, $dispatch->plainToken);
            } catch (\App\Exception\InstitutionStudentInviteException $exception) {
                $caught = true;
                self::assertSame(\App\Enum\InstitutionStudentInviteFailureReason::AccountNotReady, $exception->getReason());
            }
            self::assertTrue($caught);
        });
        self::assertSame(0, $this->countTable('classroom_student_enrollments'));
    }

    public function testResendAndRevokeInvalidateThePreviousTokenAndRateLimitIsEnforced(): void
    {
        $this->bootSchool();
        $reference = $this->classroomReference('Bes A');
        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $client->submit($client->request('GET', '/kurum/siniflar/'.$reference.'/ogrenci-davet')->selectButton('Davet gönder')->form([
            'email' => 'dondur@example.com',
            'note' => '',
        ]));
        self::assertEmailCount(1);
        $old = $this->captureToken($this->htmlBody());
        $client->followRedirect();
        $client->submit($client->getCrawler()->selectButton('Yeniden gönder')->form());
        $client->followRedirect();
        self::assertStringContainsString('Davet gönderildi veya mevcut bekleyen davet güncellendi.', (string) $client->getResponse()->getContent());
        $rotated = $this->latestPlainToken();
        self::assertNotSame($old, $rotated);
        $client->request('GET', '/davet/ogrenci/'.$old);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/davet/ogrenci/'.$rotated);
        self::assertResponseRedirects('/davet/ogrenci');
        $page = $client->request('GET', '/kurum/siniflar/'.$reference);
        $client->submit($page->selectButton('İptal et')->form());
        $client->followRedirect();
        self::assertStringContainsString('iptal edildi', (string) $client->getResponse()->getContent());
        $client->request('GET', '/davet/ogrenci/'.$rotated);
        self::assertResponseStatusCodeSame(404);

        $this->withKernel(function (): void {
            $limiter = static::getContainer()->get('limiter.institution_student_invite');
            self::assertInstanceOf(RateLimiterFactory::class, $limiter);
            $bucket = $limiter->create($this->user('ada-owner@example.com')->getId()->toRfc4122());
            while ($bucket->consume(1)->isAccepted()) {
            }
        });
        $form = $client->request('GET', '/kurum/siniflar/'.$reference.'/ogrenci-davet');
        $client->submit($form->selectButton('Davet gönder')->form([
            'email' => 'limit@example.com',
            'note' => '',
        ]));
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Çok fazla davet denemesi', (string) $client->getResponse()->getContent());
    }

    public function testCapacityFailureRollsBackMembershipAndASecondInviteInTheSameYearIsRejected(): void
    {
        $this->bootSchool();
        $limited = $this->classroomReference('Tek Kisi');
        $other = $this->classroomReference('Bes A');
        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $client->submit($client->request('GET', '/kurum/siniflar/'.$limited.'/ogrenci-davet')->selectButton('Davet gönder')->form([
            'email' => 'birinci@example.com',
            'note' => '',
        ]));
        $first = $this->captureToken($this->htmlBody());
        $client->followRedirect();
        $client->submit($client->request('GET', '/kurum/siniflar/'.$limited.'/ogrenci-davet')->selectButton('Davet gönder')->form([
            'email' => 'ikinci@example.com',
            'note' => '',
        ]));
        $second = $this->captureToken($this->htmlBody());
        self::ensureKernelShutdown();
        $this->createReadyStudent('birinci@example.com', GradeLevel::Grade5);
        $this->createReadyStudent('ikinci@example.com', GradeLevel::Grade5);
        $winner = static::createClient();
        $this->login($winner, 'birinci@example.com');
        $winner->request('GET', '/davet/ogrenci/'.$first);
        $winner->submit($winner->request('GET', '/davet/ogrenci')->selectButton('Daveti kabul et')->form());
        self::assertResponseRedirects('/ogrenci');
        self::ensureKernelShutdown();
        $loser = static::createClient();
        $this->login($loser, 'ikinci@example.com');
        $loser->request('GET', '/davet/ogrenci/'.$second);
        $loser->submit($loser->request('GET', '/davet/ogrenci')->selectButton('Daveti kabul et')->form());
        $loser->followRedirect();
        self::assertStringContainsString('kontenjanı dolu', (string) $loser->getResponse()->getContent());
        self::assertSame(1, $this->countTable('classroom_student_enrollments'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM institution_memberships WHERE role = ?', ['student']));
        self::assertNull($this->scalar('SELECT consumed_at FROM institution_student_invitations WHERE normalized_email = ?', ['ikinci@example.com']));
        self::ensureKernelShutdown();

        $owner = static::createClient();
        $this->login($owner, 'ada-owner@example.com');
        $owner->submit($owner->request('GET', '/kurum/siniflar/'.$other.'/ogrenci-davet')->selectButton('Davet gönder')->form([
            'email' => 'birinci@example.com',
            'note' => '',
        ]));
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('öğrenci olarak kaydedilemez', (string) $owner->getResponse()->getContent());
    }

    private function bootSchool(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('bora-owner@example.com', UserRole::User, 'Bora', 'Demir');
        $this->createActive('mina-manager@example.com', UserRole::User, 'Mina', 'Kurt');
        $this->openInstitution('ada-owner@example.com', 'Ada Okulu');
        $this->openInstitution('bora-owner@example.com', 'Bora Okulu');
        $this->openYear('ada-owner@example.com', 'Ada Okulu', 'Ada Donemi');
        $this->seedClassroom('ada-owner@example.com', 'Ada Donemi', 'Bes A', GradeLevel::Grade5, 24);
        $this->seedClassroom('ada-owner@example.com', 'Ada Donemi', 'Bes B', GradeLevel::Grade5, 24);
        $this->seedClassroom('ada-owner@example.com', 'Ada Donemi', 'Tek Kisi', GradeLevel::Grade5, 1);
        $this->activateYear('ada-owner@example.com', 'Ada Donemi');
    }

    private function createReadyStudent(string $email, GradeLevel $grade): void
    {
        $this->createActive($email, UserRole::Student, 'Ece', 'Ak');
        $this->withKernel(function () use ($email, $grade): void {
            $profiles = static::getContainer()->get(StudentProfileManager::class);
            self::assertInstanceOf(StudentProfileManager::class, $profiles);
            $request = new StudentProfileRequest();
            $request->gradeLevel = $grade;
            $profiles->completeOnboarding($this->user($email), $request);
        });
    }

    private function captureToken(string $body): string
    {
        $found = preg_match('#/davet/ogrenci/([A-Za-z0-9_-]{43})#', $body, $matches);
        self::assertSame(1, $found);

        return $matches[1];
    }

    private function htmlBody(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        return (string) $message->getHtmlBody();
    }

    private function verifyPath(string $body): string
    {
        $found = preg_match('#(/dogrula/eposta\?[^"\s<]+)#', $body, $matches);
        self::assertSame(1, $found);

        return html_entity_decode($matches[1], \ENT_QUOTES);
    }

    private function assertDigestOnly(string $plain): void
    {
        $this->withKernel(static function () use ($plain): void {
            $hasher = static::getContainer()->get(InvitationCodeDigestHasher::class);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(InvitationCodeDigestHasher::class, $hasher);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $digest = $hasher->hashInstitutionStudentInvite($plain);
            $stored = $em->getConnection()->fetchOne('SELECT token_digest FROM institution_student_invitations LIMIT 1');
            self::assertSame($digest, $stored);
            self::assertNotSame($plain, $stored);
            /** @var list<array{Field: string}> $rows */
            $rows = $em->getConnection()->fetchAllAssociative('SHOW COLUMNS FROM institution_student_invitations');
            $columns = [];
            foreach ($rows as $row) {
                $columns[] = $row['Field'];
            }
            self::assertContains('token_digest', $columns);
            self::assertNotContains('token', $columns);
            self::assertNotContains('plain_token', $columns);
        });
    }

    private function latestPlainToken(): string
    {
        $plain = '';
        $this->withKernel(function () use (&$plain): void {
            $manager = static::getContainer()->get(InstitutionStudentInvitationManager::class);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $rooms = static::getContainer()->get(ClassroomRepository::class);
            $hasher = static::getContainer()->get(InvitationCodeDigestHasher::class);
            self::assertInstanceOf(InstitutionStudentInvitationManager::class, $manager);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            self::assertInstanceOf(ClassroomRepository::class, $rooms);
            self::assertInstanceOf(InvitationCodeDigestHasher::class, $hasher);
            $email = (string) $em->getConnection()->fetchOne('SELECT normalized_email FROM institution_student_invitations ORDER BY created_at DESC LIMIT 1');
            $name = (string) $em->getConnection()->fetchOne('SELECT c.name FROM classrooms c INNER JOIN institution_student_invitations i ON i.classroom_id = c.id ORDER BY i.created_at DESC LIMIT 1');
            $classroom = $rooms->findOneBy(['name' => $name]);
            self::assertInstanceOf(Classroom::class, $classroom);
            $dispatch = $manager->issue(
                $this->user('ada-owner@example.com'),
                $this->institution('Ada Okulu'),
                $hasher->workspaceReference('classroom', $classroom->getId()),
                $email,
                '',
            );
            $plain = $dispatch->plainToken;
        });

        return $plain;
    }

    private function inviteTtlHours(): int
    {
        return (int) $this->scalar('SELECT TIMESTAMPDIFF(HOUR, created_at, expires_at) FROM institution_student_invitations ORDER BY created_at DESC LIMIT 1');
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params = []): mixed
    {
        $value = null;
        $this->withKernel(static function () use ($sql, $params, &$value): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $value = $em->getConnection()->fetchOne($sql, $params);
        });

        return $value;
    }

    private function classroomReference(string $name): string
    {
        $reference = '';
        $this->withKernel(static function () use ($name, &$reference): void {
            $rooms = static::getContainer()->get(ClassroomRepository::class);
            $hasher = static::getContainer()->get(InvitationCodeDigestHasher::class);
            self::assertInstanceOf(ClassroomRepository::class, $rooms);
            self::assertInstanceOf(InvitationCodeDigestHasher::class, $hasher);
            $classroom = $rooms->findOneBy(['name' => $name]);
            self::assertInstanceOf(Classroom::class, $classroom);
            $reference = $hasher->workspaceReference('classroom', $classroom->getId());
        });

        return $reference;
    }

    private function countTable(string $table): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM '.$table);
    }

    /**
     * @param list<mixed> $params
     */
    private function clientScalar(KernelBrowser $client, string $sql, array $params = []): mixed
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em->getConnection()->fetchOne($sql, $params);
    }

    private function clientCount(KernelBrowser $client, string $table): int
    {
        return (int) $this->clientScalar($client, 'SELECT COUNT(*) FROM '.$table);
    }

    private function seedClassroom(string $ownerEmail, string $yearName, string $classroomName, GradeLevel $grade, int $capacity): void
    {
        $this->withKernel(function () use ($ownerEmail, $yearName, $classroomName, $grade, $capacity): void {
            $years = static::getContainer()->get(AcademicYearRepository::class);
            $classrooms = static::getContainer()->get(ClassroomManager::class);
            self::assertInstanceOf(AcademicYearRepository::class, $years);
            self::assertInstanceOf(ClassroomManager::class, $classrooms);
            $year = $years->findOneBy(['name' => $yearName]);
            self::assertInstanceOf(AcademicYear::class, $year);
            $classrooms->create($year, $this->user($ownerEmail), $classroomName, $grade, 'panel_create', 'A', $capacity);
        });
    }

    private function activateYear(string $ownerEmail, string $yearName): void
    {
        $this->withKernel(function () use ($ownerEmail, $yearName): void {
            $years = static::getContainer()->get(AcademicYearRepository::class);
            $manager = static::getContainer()->get(AcademicYearManager::class);
            self::assertInstanceOf(AcademicYearRepository::class, $years);
            self::assertInstanceOf(AcademicYearManager::class, $manager);
            $year = $years->findOneBy(['name' => $yearName]);
            self::assertInstanceOf(AcademicYear::class, $year);
            $manager->activate($year, $this->user($ownerEmail), 'open_year');
        });
    }

    private function closeYear(): void
    {
        $this->withKernel(function (): void {
            $years = static::getContainer()->get(AcademicYearRepository::class);
            $manager = static::getContainer()->get(AcademicYearManager::class);
            self::assertInstanceOf(AcademicYearRepository::class, $years);
            self::assertInstanceOf(AcademicYearManager::class, $manager);
            $year = $years->findOneBy(['name' => 'Ada Donemi']);
            self::assertInstanceOf(AcademicYear::class, $year);
            $manager->close($year, $this->user('ada-owner@example.com'), 'close_year');
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
                new \DateTimeImmutable('2026-09-01'),
                new \DateTimeImmutable('2027-06-15'),
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
                'institution_student_invite_pending_guards',
                'institution_student_invitations',
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
