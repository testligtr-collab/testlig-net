<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SecurityAuditEvent;
use App\Entity\User;
use App\Enum\ParentStudentLinkStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\ParentStudentLinkException;
use App\Repository\ParentStudentLinkRepository;
use App\Service\ParentStudentLinkCodeManager;
use App\Service\UserFactory;
use App\Tests\Support\ParentStudentLinkDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ParentStudentLinkCodeManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ParentStudentLinkCodeManager $manager;
    private UserFactory $userFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $manager = $c->get(ParentStudentLinkCodeManager::class);
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(ParentStudentLinkCodeManager::class, $manager);
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

    public function testStudentIssuesDigestOnlyCode(): void
    {
        $student = $this->verifiedUser('code-student@example.com', UserRole::Student);
        $issued = $this->manager->issue($student);

        self::assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}(-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}){2}$/', $issued->displayCode);
        $raw = str_replace('-', '', $issued->displayCode);
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM parent_student_link_codes');
        self::assertCount(1, $rows);
        foreach ($rows[0] as $value) {
            self::assertNotSame($raw, (string) $value);
            self::assertNotSame($issued->displayCode, (string) $value);
        }
        self::assertSame(64, \strlen((string) $rows[0]['token_digest']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['token_digest']);

        $events = $this->em->getRepository(SecurityAuditEvent::class)->findBy(['action' => SecurityAuditAction::LinkCodeCreated]);
        self::assertCount(1, $events);
        $encoded = json_encode($events[0]->getMetadata(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($raw, $encoded);
        self::assertStringNotContainsString('example.com', $encoded);
    }

    public function testNonStudentsCannotIssue(): void
    {
        foreach ([UserRole::Parent, UserRole::Teacher, UserRole::Admin] as $role) {
            $user = $this->verifiedUser(strtolower($role->name).'-issue@example.com', $role);
            try {
                $this->manager->issue($user);
                self::fail('Expected rejection for '.$role->value);
            } catch (ParentStudentLinkException $exception) {
                self::assertSame('Bu veli–öğrenci işlemi için yetkiniz yok.', $exception->getMessage());
            }
        }
    }

    public function testExpiredRevokedAndReusedCodesShareTheGenericError(): void
    {
        $student = $this->verifiedUser('life-student@example.com', UserRole::Student);
        $parent = $this->verifiedUser('life-parent@example.com', UserRole::Parent);
        $issued = $this->manager->issue($student);
        $this->expireOpenCode();

        $expired = $this->redeemMessage($parent, $issued->displayCode);

        $fresh = $this->manager->issue($student);
        $this->manager->revokeOpenCode($student);
        $revoked = $this->redeemMessage($parent, $fresh->displayCode);

        $live = $this->manager->issue($student);
        $this->manager->redeem($parent, $live->displayCode, '203.0.113.10');
        $reused = $this->redeemMessage($parent, strtolower($live->displayCode));
        $unknown = $this->redeemMessage($parent, 'ZZZZ-ZZZZ-ZZZZ');

        self::assertSame('Kod geçersiz veya süresi dolmuş.', $expired);
        self::assertSame($expired, $revoked);
        self::assertSame($expired, $reused);
        self::assertSame($expired, $unknown);
        self::assertStringNotContainsString('example.com', $unknown);
    }

    public function testSelfLinkWrongRoleDuplicateAndParentCap(): void
    {
        $both = $this->verifiedUser('self@example.com', UserRole::Student);
        $both->addGlobalRole(UserRole::Parent);
        $this->em->flush();
        $own = $this->manager->issue($both);
        $selfMessage = $this->redeemMessage($both, $own->displayCode);
        self::assertSame('Kod geçersiz veya süresi dolmuş.', $selfMessage);

        $student = $this->verifiedUser('cap-student@example.com', UserRole::Student);
        $teacher = $this->verifiedUser('cap-teacher@example.com', UserRole::Teacher);
        $issued = $this->manager->issue($student);
        try {
            $this->manager->redeem($teacher, $issued->displayCode, '203.0.113.20');
            self::fail('Teacher must not redeem.');
        } catch (ParentStudentLinkException $exception) {
            self::assertSame('Bu veli–öğrenci işlemi için yetkiniz yok.', $exception->getMessage());
        }

        $parents = [];
        for ($i = 0; $i < 4; ++$i) {
            $parents[] = $this->verifiedUser('cap-parent-'.$i.'@example.com', UserRole::Parent);
            $code = $this->manager->issue($student);
            $this->manager->redeem($parents[$i], $code->displayCode, '203.0.113.2'.$i);
        }
        $fifth = $this->verifiedUser('cap-parent-4@example.com', UserRole::Parent);
        $extra = $this->manager->issue($student);
        try {
            $this->manager->redeem($fifth, $extra->displayCode, '203.0.113.30');
            self::fail('Fifth parent must be rejected.');
        } catch (ParentStudentLinkException $exception) {
            self::assertSame('Bu öğrenci için bağlantı sınırı dolu.', $exception->getMessage());
        }

        $again = $this->manager->issue($student);
        try {
            $this->manager->redeem($parents[0], $again->displayCode, '203.0.113.31');
            self::fail('Duplicate pair must be rejected.');
        } catch (ParentStudentLinkException $exception) {
            self::assertSame('Veli–öğrenci ilişkisi için çakışma oluştu.', $exception->getMessage());
        }

        $links = $this->em->getRepository(\App\Entity\ParentStudentLink::class)->findBy([
            'student' => $student,
            'status' => ParentStudentLinkStatus::Verified,
        ]);
        self::assertCount(4, $links);
    }

    public function testParentCanLinkMultipleStudents(): void
    {
        $parent = $this->verifiedUser('multi-parent@example.com', UserRole::Parent);
        foreach (['multi-a@example.com', 'multi-b@example.com'] as $email) {
            $student = $this->verifiedUser($email, UserRole::Student);
            $issued = $this->manager->issue($student);
            $this->manager->redeem($parent, $issued->displayCode, '203.0.113.40');
        }
        $repo = static::getContainer()->get(ParentStudentLinkRepository::class);
        self::assertInstanceOf(ParentStudentLinkRepository::class, $repo);
        self::assertCount(2, $repo->findVerifiedForParent($parent->getId()));
    }

    public function testIssueAndRedeemRateLimits(): void
    {
        $student = $this->verifiedUser('rate-student@example.com', UserRole::Student);
        for ($i = 0; $i < 8; ++$i) {
            $this->manager->issue($student);
        }
        try {
            $this->manager->issue($student);
            self::fail('Issue rate limit expected.');
        } catch (ParentStudentLinkException $exception) {
            self::assertSame('Çok fazla deneme. Lütfen daha sonra tekrar deneyin.', $exception->getMessage());
        }

        $parent = $this->verifiedUser('rate-parent@example.com', UserRole::Parent);
        for ($i = 0; $i < 8; ++$i) {
            $message = $this->redeemMessage($parent, 'ZZZZ-ZZZZ-ZZZ'.$i);
            self::assertSame('Kod geçersiz veya süresi dolmuş.', $message);
        }
        try {
            $this->manager->redeem($parent, 'ZZZZ-ZZZZ-ZZZZ', '203.0.113.50');
            self::fail('Redeem rate limit expected.');
        } catch (ParentStudentLinkException $exception) {
            self::assertSame('Çok fazla deneme. Lütfen daha sonra tekrar deneyin.', $exception->getMessage());
        }

        $ipLimiter = static::getContainer()->get('limiter.parent_link_code_redeem_ip');
        self::assertInstanceOf(RateLimiterFactory::class, $ipLimiter);
        $bucket = $ipLimiter->create('ip:198.51.100.77');
        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($bucket->consume(1)->isAccepted());
        }
        self::assertFalse($bucket->consume(1)->isAccepted());
    }

    private function expireOpenCode(): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE parent_student_link_codes SET expires_at = '2000-01-01 00:00:00' WHERE consumed_at IS NULL AND revoked_at IS NULL",
        );
        $this->em->clear();
    }

    private function redeemMessage(User $parent, string $code): string
    {
        try {
            $this->manager->redeem($parent, $code, '203.0.113.60');
        } catch (ParentStudentLinkException $exception) {
            return $exception->getMessage();
        }
        self::fail('Redeem should have failed.');
    }

    private function verifiedUser(string $email, UserRole $role): User
    {
        $unique = str_replace('@', '+'.bin2hex(random_bytes(3)).'@', $email);
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

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        ParentStudentLinkDbCleanup::deleteAll($connection);
        foreach (['security_audit_events', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
        $this->em->clear();
    }
}
