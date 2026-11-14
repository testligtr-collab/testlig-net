<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AccountSummaryHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';
    private const FOREIGN_EMAIL = 'account_summary_foreign@example.com';
    private const INSTITUTION = 'Account Summary Koleji';

    protected function tearDown(): void
    {
        try {
            if (static::$booted) {
                $em = static::getContainer()->get(EntityManagerInterface::class);
                self::assertInstanceOf(EntityManagerInterface::class, $em);
                $em->getConnection()->executeStatement(
                    "DELETE FROM institution_memberships WHERE user_id IN (
                        SELECT id FROM users WHERE normalized_email LIKE 'account_summary_%@example.com'
                    ) OR institution_id IN (
                        SELECT id FROM institutions WHERE name = ?
                    )",
                    [self::INSTITUTION],
                );
                $em->getConnection()->executeStatement(
                    'DELETE FROM institutions WHERE name = ?',
                    [self::INSTITUTION],
                );
                $em->getConnection()->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'account_summary_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function testAnonymousRedirectsToLogin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
    }

    public function testRolesSeeOwnIdentityAndHideForeignEmail(): void
    {
        $this->persistActive(self::FOREIGN_EMAIL, UserRole::Teacher, 'Yabanci', 'Kullanici');

        $cases = [
            [UserRole::SuperAdmin, 'account_summary_sa@example.com', 'Su', 'Admin'],
            [UserRole::Admin, 'account_summary_admin@example.com', 'Ad', 'Yonetici'],
            [UserRole::Teacher, 'account_summary_teacher@example.com', 'Og', 'Retmen'],
            [UserRole::Student, 'account_summary_student@example.com', 'Ogr', 'Enci'],
        ];

        foreach ($cases as [$role, $email, $first, $last]) {
            $this->persistActive($email, $role, $first, $last);
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->authenticate($client, $email);
            $client->request('GET', '/hesabim');
            self::assertResponseIsSuccessful($email);
            self::assertSelectorExists('.account-summary-page');
            self::assertSelectorTextContains('#account-summary-heading', 'Hesabım');
            self::assertSelectorTextContains('.account-summary__lede', 'salt okunur özeti');
            self::assertSelectorTextContains('.account-summary__field:nth-child(1) .account-summary__value', $first);
            self::assertSelectorTextContains('.account-summary__field:nth-child(2) .account-summary__value', $last);
            self::assertSelectorTextContains('.account-summary__value--wrap', $email);
            self::assertSelectorExists('a.account-summary__action[href="/hesabim/sifre-degistir"]');
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString(self::FOREIGN_EMAIL, $html);
            self::assertStringNotContainsString('Yabanci', $html);

            if (UserRole::Student === $role) {
                self::assertSelectorNotExists('#workspace-heading');
                self::assertSelectorNotExists('a[href="/yonetim/icerikler"]');
                self::assertSelectorNotExists('#account-memberships-heading');
            } else {
                self::assertSelectorExists('#workspace-heading');
                self::assertSelectorExists('a[href="/yonetim/icerikler"]');
            }
        }
    }

    public function testMembershipVisibleOnlyForSelfAndHiddenWhenAbsent(): void
    {
        $this->persistActive('account_summary_owner@example.com', UserRole::Teacher, 'Kurum', 'Sahibi');
        $this->persistActive('account_summary_sa_setup@example.com', UserRole::SuperAdmin, 'Kur', 'Kurucu');
        $this->persistActive('account_summary_member@example.com', UserRole::Teacher, 'Uye', 'Ogretmen');
        $this->persistActive('account_summary_lonely@example.com', UserRole::Teacher, 'Yalniz', 'Ogretmen');

        self::ensureKernelShutdown();
        self::bootKernel();
        $creator = static::getContainer()->get(InstitutionCreator::class);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        $memberships = static::getContainer()->get(InstitutionMembershipManager::class);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        self::assertInstanceOf(InstitutionMembershipManager::class, $memberships);
        self::assertInstanceOf(UserRepository::class, $users);

        $sa = $users->findOneBy(['email' => 'account_summary_sa_setup@example.com']);
        $owner = $users->findOneBy(['email' => 'account_summary_owner@example.com']);
        $member = $users->findOneBy(['email' => 'account_summary_member@example.com']);
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(User::class, $owner);
        self::assertInstanceOf(User::class, $member);

        $institution = $creator->create($sa, $owner, self::INSTITUTION, InstitutionType::School, 'account_summary_setup');
        $status->activate($institution, $sa, 'account_summary_activate');
        $memberships->addMember($institution, $owner, $member, InstitutionMembershipRole::Teacher, 'account_summary_member');

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, 'account_summary_member@example.com');
        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#account-memberships-heading');
        self::assertSelectorTextContains('.account-summary__membership-name', self::INSTITUTION);
        self::assertSelectorTextContains('.account-summary__membership-meta', 'Öğretmen');
        self::assertStringNotContainsString('account_summary_owner@example.com', (string) $client->getResponse()->getContent());

        self::ensureKernelShutdown();
        $lonely = static::createClient();
        $this->authenticate($lonely, 'account_summary_lonely@example.com');
        $lonely->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#account-memberships-heading');
        self::assertStringNotContainsString(self::INSTITUTION, (string) $lonely->getResponse()->getContent());
    }

    public function testGetDoesNotWriteUserFixtureFields(): void
    {
        $user = $this->persistActive('account_summary_nowrite@example.com', UserRole::Teacher, 'No', 'Write');
        $fixtureId = $user->getId();

        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $before = $this->fingerprintUser($em, $fixtureId);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, 'account_summary_nowrite@example.com');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame($before, $this->fingerprintUser($em, $fixtureId));

        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-summary__value--wrap', 'account_summary_nowrite@example.com');

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        self::assertSame($before, $this->fingerprintUser($emAfter, $fixtureId));
    }

    private function fingerprintUser(EntityManagerInterface $em, Uuid $id): string
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT first_name, last_name, email, password, status, email_verified_at, global_roles FROM users WHERE id = ?',
            [$id->toBinary()],
        );
        self::assertIsArray($row);

        return implode('|', [
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['email'],
            (string) $row['password'],
            (string) $row['status'],
            (string) ($row['email_verified_at'] ?? ''),
            (string) $row['global_roles'],
        ]);
    }

    private function persistActive(string $email, UserRole $role, string $first, string $last): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
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

    private function authenticate(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();
    }
}
