<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\PhoneVerificationFailureReason;
use App\Enum\PhoneVerificationPurpose;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Exception\PhoneVerificationException;
use App\Repository\PhoneVerificationClaimRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\PhoneOtpDigestHasher;
use App\Service\PhoneVerificationClaimManager;
use App\Service\SecurityAuditMetadataSanitizer;
use App\Service\SecurityAuditRecorder;
use App\Service\UserFactory;
use App\Tests\Support\ParentStudentLinkDbCleanup;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class PhoneVerificationClaimManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private PhoneVerificationClaimManager $manager;

    private UserFactory $factory;

    private UserRepository $users;

    private PhoneVerificationClaimRepository $claims;

    private PhoneOtpDigestHasher $hasher;

    private SecurityAuditEventRepository $events;

    private SecurityAuditRecorder $auditRecorder;

    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

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

        $hasher = $c->get(PhoneOtpDigestHasher::class);
        self::assertInstanceOf(PhoneOtpDigestHasher::class, $hasher);
        $this->hasher = $hasher;

        $events = $c->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;

        $audit = $c->get(SecurityAuditRecorder::class);
        self::assertInstanceOf(SecurityAuditRecorder::class, $audit);
        $this->auditRecorder = $audit;
        $this->auditRecorder->resetRequestDedup();

        $this->clock = new MockClock('2026-09-20 12:00:00');
        Clock::set($this->clock);

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

    public function testIssueNormalizesPhonePersistsDigestOnlyAndSetsTtl(): void
    {
        $user = $this->newUser('pvc-issue@example.com');
        $otp = '123456';
        $claim = $this->manager->issue($user, '0532 123 45 67', $otp);

        self::assertSame('+905321234567', $claim->getTargetPhone());
        self::assertSame('+905321234567', $claim->getTargetNormalizedPhone());
        self::assertSame(300, $claim->getExpiresAt()->getTimestamp() - $claim->getCreatedAt()->getTimestamp());
        self::assertNull($claim->getConsumedAt());
        self::assertNull($claim->getRevokedAt());

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT target_phone, target_normalized_phone, code_digest, failed_attempt_count FROM phone_verification_claims WHERE id = ?',
            [$claim->getId()->toBinary()],
        );
        self::assertIsArray($row);
        self::assertSame('+905321234567', $row['target_phone']);
        self::assertSame('+905321234567', $row['target_normalized_phone']);
        self::assertSame(64, \strlen((string) $row['code_digest']));
        self::assertNotSame($otp, (string) $row['code_digest']);
        self::assertArrayNotHasKey('otp', $row);

        $expected = $this->hasher->hash(
            PhoneVerificationPurpose::BindPhone,
            $claim->getId(),
            '+905321234567',
            $otp,
        );
        self::assertSame($expected, $row['code_digest']);
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::PhoneVerificationClaimCreated->value));
    }

    public function testIssueRevokesPreviousOpenClaimForSameUserPurpose(): void
    {
        $user = $this->newUser('pvc-revoke@example.com');
        $first = $this->manager->issue($user, '+905321111111', '111111');
        $second = $this->manager->issue($user, '+905322222222', '222222');

        $this->em->clear();
        $reloadedFirst = $this->claims->findOneById($first->getId());
        $reloadedSecond = $this->claims->findOneById($second->getId());
        self::assertNotNull($reloadedFirst);
        self::assertNotNull($reloadedSecond);
        self::assertTrue($reloadedFirst->isRevoked());
        self::assertTrue($reloadedSecond->isPending($this->clock->now()));
        self::assertGreaterThanOrEqual(1, $this->events->countByAction(SecurityAuditAction::PhoneVerificationRevoked->value));
    }

    public function testIssueDoesNotReserveOtherUsersPendingClaim(): void
    {
        $alice = $this->newUser('pvc-alice@example.com');
        $bob = $this->newUser('pvc-bob@example.com');
        $phone = '+905323333333';

        $aliceClaim = $this->manager->issue($alice, $phone, '333333');
        $bobClaim = $this->manager->issue($bob, $phone, '444444');

        $this->em->clear();
        $reloadedAlice = $this->claims->findOneById($aliceClaim->getId());
        $reloadedBob = $this->claims->findOneById($bobClaim->getId());
        self::assertNotNull($reloadedAlice);
        self::assertNotNull($reloadedBob);
        self::assertTrue($reloadedAlice->isPending($this->clock->now()));
        self::assertTrue($reloadedBob->isPending($this->clock->now()));
        self::assertSame($phone, $reloadedAlice->getTargetNormalizedPhone());
        self::assertSame($phone, $reloadedBob->getTargetNormalizedPhone());
    }

    public function testIssueRejectsInvalidOtpFormatWithoutLeakingCode(): void
    {
        $user = $this->newUser('pvc-bad-otp@example.com');
        foreach (['12345', '1234567', '12-456', '１２３４５６', 'abcdef', ''] as $bad) {
            try {
                $this->manager->issue($user, '+905321234567', $bad);
                self::fail('Expected invalid OTP rejection');
            } catch (PhoneVerificationException $e) {
                self::assertSame(PhoneVerificationFailureReason::InvalidInput, $e->getReason());
                if ('' !== $bad) {
                    self::assertStringNotContainsString($bad, $e->getMessage());
                }
            }
        }
    }

    public function testIssueRejectsInvalidPhone(): void
    {
        $user = $this->newUser('pvc-bad-phone@example.com');
        try {
            $this->manager->issue($user, '02121234567', '123456');
            self::fail('Expected invalid phone rejection');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::InvalidInput, $e->getReason());
            self::assertStringNotContainsString('02121234567', $e->getMessage());
        }
    }

    public function testVerifyAndBindSuccessConsumesAndSetsPhoneTriplet(): void
    {
        $user = $this->newUser('pvc-bind@example.com');
        $otp = '654321';
        $claim = $this->manager->issue($user, '0532 123 45 67', $otp);

        $this->manager->verifyAndBind($user, $claim->getId(), $otp);

        $this->em->clear();
        $reloadedUser = $this->users->findOneById($user->getId());
        $reloadedClaim = $this->claims->findOneById($claim->getId());
        self::assertNotNull($reloadedUser);
        self::assertNotNull($reloadedClaim);
        self::assertTrue($reloadedUser->hasVerifiedPhone());
        self::assertSame('+905321234567', $reloadedUser->getPhone());
        self::assertSame('+905321234567', $reloadedUser->getNormalizedPhone());
        self::assertNotNull($reloadedUser->getPhoneVerifiedAt());
        self::assertTrue($reloadedClaim->isConsumed());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::PhoneBound->value));
    }

    public function testReplayRevokedExpiredAndWrongOwnerAreRejected(): void
    {
        $owner = $this->newUser('pvc-owner@example.com');
        $intruder = $this->newUser('pvc-intruder@example.com');
        $otp = '111222';

        $claim = $this->manager->issue($owner, '+905324444444', $otp);
        $this->manager->verifyAndBind($owner, $claim->getId(), $otp);

        try {
            $this->manager->verifyAndBind($owner, $claim->getId(), $otp);
            self::fail('Replay should fail');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::ClaimUnavailable, $e->getReason());
        }

        $revoked = $this->manager->issue($owner, '+905325555555', '555555');
        $this->manager->issue($owner, '+905326666666', '666666');
        try {
            $this->manager->verifyAndBind($owner, $revoked->getId(), '555555');
            self::fail('Revoked claim should fail');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::ClaimUnavailable, $e->getReason());
        }

        $foreign = $this->manager->issue($owner, '+905327777777', '777777');
        try {
            $this->manager->verifyAndBind($intruder, $foreign->getId(), '777777');
            self::fail('Foreign user should fail');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::ClaimUnavailable, $e->getReason());
        }

        $expiring = $this->manager->issue($owner, '+905328888888', '888888');
        $this->clock->modify('+301 seconds');
        try {
            $this->manager->verifyAndBind($owner, $expiring->getId(), '888888');
            self::fail('Expired claim should fail');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::ClaimUnavailable, $e->getReason());
        }

        $this->em->clear();
        $ownerReloaded = $this->users->findOneById($owner->getId());
        self::assertNotNull($ownerReloaded);
        self::assertSame('+905324444444', $ownerReloaded->getNormalizedPhone());
    }

    public function testWrongOtpPersistsAttemptsAndBlocksSixthWithoutDigest(): void
    {
        $user = $this->newUser('pvc-attempts@example.com');
        $otp = '999888';
        $claim = $this->manager->issue($user, '+905329999999', $otp);

        for ($i = 1; $i <= 4; ++$i) {
            try {
                $this->manager->verifyAndBind($user, $claim->getId(), '000000');
                self::fail('Wrong OTP should fail at attempt '.$i);
            } catch (PhoneVerificationException $e) {
                self::assertSame(PhoneVerificationFailureReason::InvalidOtp, $e->getReason());
                self::assertStringNotContainsString('000000', $e->getMessage());
                self::assertStringNotContainsString('+905329999999', $e->getMessage());
            }

            $this->em->clear();
            $reloaded = $this->claims->findOneById($claim->getId());
            self::assertNotNull($reloaded);
            self::assertSame($i, $reloaded->getFailedAttemptCount());
            self::assertFalse($reloaded->isConsumed());
        }

        try {
            $this->manager->verifyAndBind($user, $claim->getId(), '000000');
            self::fail('Fifth wrong OTP should fail');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::AttemptsExceeded, $e->getReason());
        }

        $this->em->clear();
        $afterFifth = $this->claims->findOneById($claim->getId());
        self::assertNotNull($afterFifth);
        self::assertSame(5, $afterFifth->getFailedAttemptCount());

        try {
            $this->manager->verifyAndBind($user, $claim->getId(), $otp);
            self::fail('Sixth attempt must not verify even with correct OTP');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::AttemptsExceeded, $e->getReason());
        }

        $this->em->clear();
        $userReloaded = $this->users->findOneById($user->getId());
        $claimReloaded = $this->claims->findOneById($claim->getId());
        self::assertNotNull($userReloaded);
        self::assertNotNull($claimReloaded);
        self::assertFalse($userReloaded->hasVerifiedPhone());
        self::assertNull($userReloaded->getNormalizedPhone());
        self::assertSame(5, $claimReloaded->getFailedAttemptCount());
        self::assertFalse($claimReloaded->isConsumed());
        self::assertGreaterThanOrEqual(5, $this->events->countByAction(SecurityAuditAction::PhoneVerificationFailed->value));
    }

    public function testAuditMetadataContainsNoSensitiveFields(): void
    {
        $user = $this->newUser('pvc-audit@example.com');
        $otp = '121212';
        $claim = $this->manager->issue($user, '+905321010101', $otp);
        try {
            $this->manager->verifyAndBind($user, $claim->getId(), '000000');
        } catch (PhoneVerificationException) {
        }
        $this->manager->verifyAndBind($user, $claim->getId(), $otp);

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT action, metadata FROM security_audit_events WHERE action IN (?, ?, ?, ?) ORDER BY occurred_at ASC',
            [
                SecurityAuditAction::PhoneVerificationClaimCreated->value,
                SecurityAuditAction::PhoneVerificationFailed->value,
                SecurityAuditAction::PhoneBound->value,
                SecurityAuditAction::PhoneVerificationRevoked->value,
            ],
        );
        self::assertNotEmpty($rows);

        $sanitizer = new SecurityAuditMetadataSanitizer();
        foreach ($rows as $row) {
            $meta = json_decode((string) $row['metadata'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($meta);
            $sanitizer->sanitize($meta);
            $encoded = json_encode($meta, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($otp, $encoded);
            self::assertStringNotContainsString('+905321010101', $encoded);
            self::assertStringNotContainsString('code_digest', $encoded);
            self::assertArrayNotHasKey('otp', $meta);
            self::assertArrayNotHasKey('phone', $meta);
            self::assertArrayNotHasKey('normalized_phone', $meta);
            self::assertArrayNotHasKey('code_digest', $meta);
            self::assertArrayNotHasKey('pepper', $meta);
        }
    }

    public function testPhoneConflictLeavesLoserUnboundAndClaimOpen(): void
    {
        $this->requireMariaDb();

        $winner = $this->newUser('pvc-win@example.com');
        $loser = $this->newUser('pvc-lose@example.com');
        $phone = '+905320000001';
        $otpWin = '101010';
        $otpLose = '202020';

        $winClaim = $this->manager->issue($winner, $phone, $otpWin);
        $loseClaim = $this->manager->issue($loser, $phone, $otpLose);

        $this->manager->verifyAndBind($winner, $winClaim->getId(), $otpWin);

        try {
            $this->manager->verifyAndBind($loser, $loseClaim->getId(), $otpLose);
            self::fail('Second bind of same phone must fail');
        } catch (PhoneVerificationException $e) {
            self::assertSame(PhoneVerificationFailureReason::PhoneConflict, $e->getReason());
            self::assertStringNotContainsString($phone, $e->getMessage());
            self::assertStringNotContainsString('uniq_users_normalized_phone', $e->getMessage());
        }

        $this->em->clear();
        $winnerReloaded = $this->users->findOneById($winner->getId());
        $loserReloaded = $this->users->findOneById($loser->getId());
        $loseClaimReloaded = $this->claims->findOneById($loseClaim->getId());
        self::assertNotNull($winnerReloaded);
        self::assertNotNull($loserReloaded);
        self::assertNotNull($loseClaimReloaded);
        self::assertTrue($winnerReloaded->hasVerifiedPhone());
        self::assertSame($phone, $winnerReloaded->getNormalizedPhone());
        self::assertFalse($loserReloaded->hasVerifiedPhone());
        self::assertNull($loserReloaded->getNormalizedPhone());
        self::assertFalse($loseClaimReloaded->isConsumed());
        self::assertNull($loseClaimReloaded->getConsumedAt());
        self::assertTrue($loseClaimReloaded->isPending($this->clock->now()));

        // No automatic account merge: still two distinct users.
        self::assertNotSame($winnerReloaded->getId()->toRfc4122(), $loserReloaded->getId()->toRfc4122());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM users WHERE id IN (?, ?)',
            [$winner->getId()->toBinary(), $loser->getId()->toBinary()],
        ));
    }

    public function testFailedVerifyDoesNotChangeUserPhone(): void
    {
        $user = $this->newUser('pvc-nochange@example.com');
        $claim = $this->manager->issue($user, '+905321212121', '121212');
        try {
            $this->manager->verifyAndBind($user, $claim->getId(), '000000');
        } catch (PhoneVerificationException) {
        }

        $this->em->clear();
        $reloaded = $this->users->findOneById($user->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->hasVerifiedPhone());
        self::assertNull($reloaded->getPhone());
        self::assertNull($reloaded->getNormalizedPhone());
        self::assertNull($reloaded->getPhoneVerifiedAt());
    }

    public function testMockClockDoesNotLeakAfterSuite(): void
    {
        Clock::set(new MockClock('2099-01-01 00:00:00'));
        self::assertSame('2099', Clock::get()->now()->format('Y'));
        Clock::set(new NativeClock());
        self::assertNotSame('2099', Clock::get()->now()->format('Y'));
    }

    private function newUser(string $email): User
    {
        return $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'Phone', 'User', UserRole::Student);
    }

    private function requireMariaDb(): void
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Phone unique race assertions target MariaDB/MySQL.');
        }
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        ParentStudentLinkDbCleanup::deleteAll($connection);
        foreach (['security_audit_events', 'phone_verification_claims', 'reset_password_requests', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }
}
