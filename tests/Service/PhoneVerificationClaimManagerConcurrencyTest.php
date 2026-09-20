<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\PhoneVerificationFailureReason;
use App\Enum\UserRole;
use App\Exception\PhoneVerificationException;
use App\Repository\PhoneVerificationClaimRepository;
use App\Repository\UserRepository;
use App\Service\PhoneVerificationClaimManager;
use App\Service\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * MariaDB-only concurrency: two connections racing the same normalized_phone unique index.
 */
final class PhoneVerificationClaimManagerConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private PhoneVerificationClaimManager $manager;

    private UserFactory $factory;

    private UserRepository $users;

    private PhoneVerificationClaimRepository $claims;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $platform = $this->em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Concurrency race requires MariaDB/MySQL unique constraints.');
        }

        $manager = $c->get(PhoneVerificationClaimManager::class);
        self::assertInstanceOf(PhoneVerificationClaimManager::class, $manager);
        $this->manager = $manager;

        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;

        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $claims = $c->get(PhoneVerificationClaimRepository::class);
        self::assertInstanceOf(PhoneVerificationClaimRepository::class, $claims);
        $this->claims = $claims;

        Clock::set(new NativeClock());
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        if ($this->em->isOpen()) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    public function testConcurrentNormalizedPhoneBindOnlyOneWins(): void
    {
        $phone = '+905321212100';
        $otpA = '101010';
        $otpB = '202020';

        $userA = $this->factory->createAndPersist('pvc-race-a@example.com', 'Guclu-Parola-123!', 'Race', 'A', UserRole::Student);
        $userB = $this->factory->createAndPersist('pvc-race-b@example.com', 'Guclu-Parola-123!', 'Race', 'B', UserRole::Student);

        $claimA = $this->manager->issue($userA, $phone, $otpA);
        $claimB = $this->manager->issue($userB, $phone, $otpB);

        // Hold both rows under FOR UPDATE on separate connections, then race the phone UPDATE.
        $connA = $this->openSecondaryConnection();
        $connB = $this->openSecondaryConnection();

        try {
            $connA->beginTransaction();
            $connB->beginTransaction();

            $connA->executeStatement(
                'SELECT id FROM users WHERE id = ? FOR UPDATE',
                [$userA->getId()->toBinary()],
            );
            $connB->executeStatement(
                'SELECT id FROM users WHERE id = ? FOR UPDATE',
                [$userB->getId()->toBinary()],
            );

            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $connA->executeStatement(
                'UPDATE users SET phone = ?, normalized_phone = ?, phone_verified_at = ?, updated_at = ? WHERE id = ?',
                [$phone, $phone, $now, $now, $userA->getId()->toBinary()],
            );
            $connA->commit();

            $uniqueFailed = false;
            try {
                $connB->executeStatement(
                    'UPDATE users SET phone = ?, normalized_phone = ?, phone_verified_at = ?, updated_at = ? WHERE id = ?',
                    [$phone, $phone, $now, $now, $userB->getId()->toBinary()],
                );
                $connB->commit();
            } catch (\Throwable $exception) {
                if ($connB->isTransactionActive()) {
                    $connB->rollBack();
                }
                $uniqueFailed = str_contains($exception->getMessage(), 'uniq_users_normalized_phone')
                    || str_contains($exception->getMessage(), 'Duplicate entry');
            }

            self::assertTrue($uniqueFailed, 'Second concurrent phone bind must hit uniq_users_normalized_phone');
        } finally {
            if ($connA->isTransactionActive()) {
                $connA->rollBack();
            }
            if ($connB->isTransactionActive()) {
                $connB->rollBack();
            }
            $connA->close();
            $connB->close();
        }

        // Manager path for the loser: claim stays open, no merge, generic conflict.
        $this->em->clear();
        $userA = $this->users->findOneById($userA->getId());
        $userB = $this->users->findOneById($userB->getId());
        self::assertNotNull($userA);
        self::assertNotNull($userB);
        self::assertTrue($userA->hasVerifiedPhone());

        // Reset B phone if any partial state, then try manager verify (A already owns phone).
        if ($userB->hasVerifiedPhone()) {
            self::fail('Loser must not keep a verified phone after unique race');
        }

        try {
            $this->manager->verifyAndBind($userB, $claimB->getId(), $otpB);
            self::fail('Manager must reject loser bind after winner owns phone');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::PhoneConflict, $e->getReason());
        }

        $this->em->clear();
        $loser = $this->users->findOneById($userB->getId());
        $loseClaim = $this->claims->findOneById($claimB->getId());
        $winner = $this->users->findOneById($userA->getId());
        self::assertNotNull($loser);
        self::assertNotNull($loseClaim);
        self::assertNotNull($winner);
        self::assertFalse($loser->hasVerifiedPhone());
        self::assertFalse($loseClaim->isConsumed());
        self::assertTrue($winner->hasVerifiedPhone());
        self::assertSame($phone, $winner->getNormalizedPhone());
        self::assertNotSame($winner->getId()->toRfc4122(), $loser->getId()->toRfc4122());

        // Winner claim from manager issue is still open (DB race used raw UPDATE); consume via manager is out of scope.
        unset($claimA);
    }

    private function openSecondaryConnection(): Connection
    {
        $params = $this->em->getConnection()->getParams();

        return DriverManager::getConnection($params);
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        foreach (['security_audit_events', 'phone_verification_claims', 'reset_password_requests', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }
}
