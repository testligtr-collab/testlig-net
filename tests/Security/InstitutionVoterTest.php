<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Institution;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\UserRepository;
use App\Security\InstitutionPermission;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class InstitutionVoterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private AccessDecisionManagerInterface $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $access = $c->get(AccessDecisionManagerInterface::class);
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $access);
        $this->access = $access;
        $this->cleanup();
    }

    /**
     * @return iterable<string, array{0: string, 1: InstitutionMembershipRole|null, 2: string, 3: bool, 4: ?string}>
     */
    public static function permissionMatrix(): iterable
    {
        yield 'owner_view' => ['owner', InstitutionMembershipRole::Owner, InstitutionPermission::VIEW, true, null];
        yield 'owner_manage' => ['owner', InstitutionMembershipRole::Owner, InstitutionPermission::MANAGE, true, null];
        yield 'owner_members_manage' => ['owner', InstitutionMembershipRole::Owner, InstitutionPermission::MEMBERS_MANAGE, true, null];
        yield 'manager_manage' => ['manager', InstitutionMembershipRole::Manager, InstitutionPermission::MANAGE, true, null];
        yield 'manager_members_view' => ['manager', InstitutionMembershipRole::Manager, InstitutionPermission::MEMBERS_VIEW, true, null];
        yield 'teacher_view' => ['teacher', InstitutionMembershipRole::Teacher, InstitutionPermission::VIEW, true, null];
        yield 'teacher_manage_denied' => ['teacher', InstitutionMembershipRole::Teacher, InstitutionPermission::MANAGE, false, null];
        yield 'teacher_members_manage_denied' => ['teacher', InstitutionMembershipRole::Teacher, InstitutionPermission::MEMBERS_MANAGE, false, null];
        yield 'staff_view' => ['staff', InstitutionMembershipRole::Staff, InstitutionPermission::VIEW, true, null];
        yield 'staff_members_view_denied' => ['staff', InstitutionMembershipRole::Staff, InstitutionPermission::MEMBERS_VIEW, false, null];
        yield 'staff_manage_denied' => ['staff', InstitutionMembershipRole::Staff, InstitutionPermission::MANAGE, false, null];
    }

    #[DataProvider('permissionMatrix')]
    public function testRolePermissions(string $label, InstitutionMembershipRole $role, string $attribute, bool $expected, ?string $unused): void
    {
        unset($label, $unused);
        [$institution, $user] = $this->activeInstitutionWithMember($role);
        self::assertSame($expected, $this->decide($user, $attribute, $institution));
    }

    public function testAnonymousDenied(): void
    {
        [$institution] = $this->activeInstitutionWithMember(InstitutionMembershipRole::Owner);
        $token = new NullToken();
        self::assertFalse($this->access->decide($token, [InstitutionPermission::VIEW], $institution));
    }

    public function testAdminAndModeratorDoNotGetAutomaticAccess(): void
    {
        [$institution] = $this->activeInstitutionWithMember(InstitutionMembershipRole::Owner);
        $admin = $this->activeUser('voter-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $mod = $this->activeUser('voter-mod@example.com');
        $mod->addGlobalRole(UserRole::Moderator);
        $this->users->save($mod);
        self::assertFalse($this->decide($admin, InstitutionPermission::VIEW, $institution));
        self::assertFalse($this->decide($mod, InstitutionPermission::MANAGE, $institution));
    }

    public function testSuperAdminOverride(): void
    {
        [$institution] = $this->activeInstitutionWithMember(InstitutionMembershipRole::Owner);
        $sa = $this->activeUser('voter-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        self::assertTrue($this->decide($sa, InstitutionPermission::MANAGE, $institution));
    }

    public function testOtherInstitutionMembershipDoesNotGrantAccess(): void
    {
        [$institutionA, $ownerA] = $this->activeInstitutionWithMember(InstitutionMembershipRole::Owner, 'A');
        [$institutionB] = $this->activeInstitutionWithMember(InstitutionMembershipRole::Owner, 'B');
        self::assertTrue($this->decide($ownerA, InstitutionPermission::VIEW, $institutionA));
        self::assertFalse($this->decide($ownerA, InstitutionPermission::VIEW, $institutionB));
    }

    public function testSuspendedMembershipDenied(): void
    {
        [$institution, $owner] = $this->activeInstitutionWithMember(InstitutionMembershipRole::Owner);
        $teacher = $this->activeUser('voter-susp-teacher@example.com');
        $mgr = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $mgr);
        $mgr->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_teacher');
        $memberships = static::getContainer()->get(InstitutionMembershipRepository::class);
        self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
        $membership = $memberships->findActiveMembership($teacher, $institution);
        self::assertNotNull($membership);
        $mgr->suspend($membership, $owner, 'suspend_teacher');
        self::assertFalse($this->decide($teacher, InstitutionPermission::VIEW, $institution));
    }

    public function testSuspendedInstitutionDeniedForMembers(): void
    {
        [$institution, $owner] = $this->activeInstitutionWithMember(InstitutionMembershipRole::Owner);
        $sa = $this->activeUser('voter-inst-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $status->suspend($institution, $sa, 'suspend_institution');
        self::assertFalse($this->decide($owner, InstitutionPermission::VIEW, $institution));
        self::assertTrue($this->decide($sa, InstitutionPermission::VIEW, $institution));
    }

    /**
     * @return array{0: Institution, 1: User}
     */
    private function activeInstitutionWithMember(InstitutionMembershipRole $role, string $suffix = 'X'): array
    {
        $sa = $this->activeUser('voter-sa-'.$suffix.'@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser('voter-owner-'.$suffix.'@example.com');
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        $institution = $creator->create($sa, $owner, 'Voter School '.$suffix, InstitutionType::School, 'platform_setup');
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $status->activate($institution, $sa, 'activate_ok');

        if (InstitutionMembershipRole::Owner === $role) {
            return [$institution, $owner];
        }

        $member = $this->activeUser('voter-'.$role->value.'-'.$suffix.'@example.com');
        $mgr = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $mgr);
        $mgr->addMember($institution, $owner, $member, $role, 'add_member');

        return [$institution, $member];
    }

    private function decide(User $user, string $attribute, Institution $institution): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $institution);
    }

    private function activeUser(string $email): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        foreach (['institution_memberships', 'institutions', 'security_audit_events', 'security_bootstrap_guards', 'reset_password_requests', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }
}
