<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ParentStudentLink;
use App\Entity\PersonalInvitation;
use App\Entity\SecurityAuditEvent;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\ParentStudentLinkException;
use App\Invitation\InvitationPurposeContract;
use App\Repository\ParentStudentLinkActiveGuardRepository;
use App\Service\ParentStudentLinkConsentManager;
use App\Service\UserFactory;
use App\Tests\Support\ParentStudentLinkDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Stage 2.22.5b consent manager — verified link does not open child-data routes.
 */
final class ParentStudentLinkConsentManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ParentStudentLinkConsentManager $manager;
    private UserFactory $userFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $manager = $c->get(ParentStudentLinkConsentManager::class);
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(ParentStudentLinkConsentManager::class, $manager);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->em = $em;
        $this->manager = $manager;
        $this->userFactory = $factory;
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        ParentStudentLinkDbCleanup::deleteAll($connection);
        foreach (['security_audit_events', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    public function testNonStudentIssuerRejected(): void
    {
        $parentA = $this->verifiedUser('issuer-parent-a@example.com', UserRole::Parent);
        $parentB = $this->verifiedUser('issuer-parent-b@example.com', UserRole::Parent);

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->requestFromStudent($parentA, $parentB);
    }

    public function testNonParentRecipientRejected(): void
    {
        $student = $this->verifiedUser('student-to-student@example.com', UserRole::Student);
        $otherStudent = $this->verifiedUser('other-student@example.com', UserRole::Student);

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->requestFromStudent($student, $otherStudent);
    }

    public function testUnverifiedStudentRejected(): void
    {
        $student = $this->userFactory->createAndPersist(
            email: 'unverified-student+'.bin2hex(random_bytes(4)).'@example.com',
            plainPassword: 'Password1!',
            firstName: 'U',
            lastName: 'Student',
            initialRole: UserRole::Student,
        );
        $parent = $this->verifiedUser('parent-for-unverified@example.com', UserRole::Parent);

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->requestFromStudent($student, $parent);
    }

    public function testSuccessfulAcceptVerifiesLinkAndConsumesInvitation(): void
    {
        $student = $this->verifiedUser('ok-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('ok-parent@example.com', UserRole::Parent);

        $issued = $this->manager->requestFromStudent($student, $parent);
        self::assertTrue($issued->link->isPending());
        self::assertSame(InvitationPurposeContract::PURPOSE_PARENT_LINK, $issued->invitation->getPurposeCode());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $issued->plainCode);

        $verified = $this->manager->acceptByParent($parent, $issued->invitation->getId(), $issued->plainCode);
        self::assertTrue($verified->isVerified());
        self::assertFalse($verified->isPending());

        $invite = $this->em->find(PersonalInvitation::class, $issued->invitation->getId());
        self::assertInstanceOf(PersonalInvitation::class, $invite);
        self::assertTrue($invite->isConsumed());

        $events = $this->em->getRepository(SecurityAuditEvent::class)->findBy(
            ['action' => SecurityAuditAction::ParentStudentLinkVerified],
            ['occurredAt' => 'DESC'],
            1,
        );
        self::assertNotEmpty($events);
        $meta = $events[0]->getMetadata();
        self::assertFalse($meta['grants_child_data_access'] ?? true);
        self::assertArrayNotHasKey('plain_code', $meta);
        self::assertArrayNotHasKey('code_digest', $meta);
        self::assertArrayNotHasKey('email', $meta);
    }

    public function testWrongCodeLeavesInvitationUsableThenReplayFailsAfterSuccess(): void
    {
        $student = $this->verifiedUser('replay-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('replay-parent@example.com', UserRole::Parent);
        $issued = $this->manager->requestFromStudent($student, $parent);

        try {
            $this->manager->acceptByParent($parent, $issued->invitation->getId(), str_repeat('0', 64));
            self::fail('wrong code must fail');
        } catch (ParentStudentLinkException) {
        }

        $invite = $this->em->find(PersonalInvitation::class, $issued->invitation->getId());
        self::assertInstanceOf(PersonalInvitation::class, $invite);
        self::assertFalse($invite->isConsumed());
        self::assertTrue($invite->isUsable(new \DateTimeImmutable('now')));

        $link = $this->em->find(ParentStudentLink::class, $issued->link->getId());
        self::assertInstanceOf(ParentStudentLink::class, $link);
        self::assertTrue($link->isPending());

        $this->manager->acceptByParent($parent, $issued->invitation->getId(), $issued->plainCode);

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->acceptByParent($parent, $issued->invitation->getId(), $issued->plainCode);
    }

    public function testOtherParentCannotAccept(): void
    {
        $student = $this->verifiedUser('other-p-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('intended-parent@example.com', UserRole::Parent);
        $intruder = $this->verifiedUser('intruder-parent@example.com', UserRole::Parent);
        $issued = $this->manager->requestFromStudent($student, $parent);

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->acceptByParent($intruder, $issued->invitation->getId(), $issued->plainCode);
    }

    public function testExpiredInvitationRejected(): void
    {
        $student = $this->verifiedUser('exp-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('exp-parent@example.com', UserRole::Parent);
        $issued = $this->manager->requestFromStudent($student, $parent);

        $this->em->getConnection()->executeStatement(
            'UPDATE personal_invitations SET created_at = ?, expires_at = ?, updated_at = ? WHERE id = ?',
            [
                '2020-01-01 00:00:00',
                '2020-01-08 00:00:00',
                '2020-01-08 00:00:00',
                $issued->invitation->getId()->toBinary(),
            ],
        );
        $this->em->clear();
        $parent = $this->em->find(User::class, $parent->getId());
        self::assertInstanceOf(User::class, $parent);

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->acceptByParent($parent, $issued->invitation->getId(), $issued->plainCode);
    }

    public function testRevokedInvitationRejected(): void
    {
        $student = $this->verifiedUser('rev-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('rev-parent@example.com', UserRole::Parent);
        $issued = $this->manager->requestFromStudent($student, $parent);

        $this->manager->endByParticipant($student, $issued->link->getId());

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->acceptByParent($parent, $issued->invitation->getId(), $issued->plainCode);
    }

    public function testDuplicateActivePairConflict(): void
    {
        $student = $this->verifiedUser('dup-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('dup-parent@example.com', UserRole::Parent);
        $this->manager->requestFromStudent($student, $parent);

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->requestFromStudent($student, $parent);
    }

    public function testEndPreservesHistoryAndAllowsNewRequest(): void
    {
        $student = $this->verifiedUser('hist-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('hist-parent@example.com', UserRole::Parent);
        $first = $this->manager->requestFromStudent($student, $parent);
        $this->manager->acceptByParent($parent, $first->invitation->getId(), $first->plainCode);
        $firstId = $first->link->getId();

        $this->manager->endByParticipant($parent, $firstId);
        $ended = $this->em->find(ParentStudentLink::class, $firstId);
        self::assertInstanceOf(ParentStudentLink::class, $ended);
        self::assertTrue($ended->isEnded());

        $guards = static::getContainer()->get(ParentStudentLinkActiveGuardRepository::class);
        self::assertInstanceOf(ParentStudentLinkActiveGuardRepository::class, $guards);
        self::assertNull($guards->findForPair($parent->getId(), $student->getId()));

        $second = $this->manager->requestFromStudent($student, $parent);
        self::assertTrue($second->link->isPending());
        self::assertNotSame($firstId->toRfc4122(), $second->link->getId()->toRfc4122());
    }

    public function testAcceptRateLimitAfterRepeatedWrongCodes(): void
    {
        $student = $this->verifiedUser('rl-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('rl-parent@example.com', UserRole::Parent);
        $issued = $this->manager->requestFromStudent($student, $parent);

        for ($i = 0; $i < 5; ++$i) {
            try {
                $this->manager->acceptByParent($parent, $issued->invitation->getId(), str_repeat((string) $i, 64));
            } catch (ParentStudentLinkException) {
            }
        }

        $this->expectException(ParentStudentLinkException::class);
        $this->manager->acceptByParent($parent, $issued->invitation->getId(), $issued->plainCode);
    }

    public function testNoChildDataRoutesRegisteredForParentLink(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $path = $route->getPath();
            self::assertStringNotContainsString('parent-student', $path);
            self::assertStringNotContainsString('veli-ogrenci', $path);
            self::assertDoesNotMatchRegularExpression('/child.?data/i', $name);
        }
        self::assertFalse(class_exists('App\\Security\\ParentStudentLinkVoter'));
    }

    private function verifiedUser(string $email, UserRole $role): User
    {
        $unique = str_replace('@', '+'.bin2hex(random_bytes(4)).'@', $email);
        $user = $this->userFactory->createAndPersist(
            email: $unique,
            plainPassword: 'Password1!',
            firstName: 'Test',
            lastName: 'User',
            initialRole: $role,
        );
        $user->markEmailVerified();
        $user->transitionTo(UserStatus::Active);
        $this->em->flush();

        return $user;
    }
}
