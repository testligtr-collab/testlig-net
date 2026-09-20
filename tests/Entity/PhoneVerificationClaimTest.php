<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PhoneVerificationClaim;
use App\Entity\User;
use App\Enum\PhoneVerificationPurpose;
use App\Enum\UserRole;
use App\Exception\PhoneVerificationException;
use App\Service\PhoneOtpDigestHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\UuidV7;

final class PhoneVerificationClaimTest extends TestCase
{
    public function testPendingLifecycleDerivedFromTimestamps(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');
        $claim = $this->newClaim($now);

        self::assertTrue($claim->isPending($now));
        self::assertFalse($claim->isExpired($now));
        self::assertSame(PhoneVerificationClaim::OTP_TTL_SECONDS, 300);
        self::assertSame(PhoneVerificationClaim::MAX_FAILED_ATTEMPTS, 5);

        $expiredNow = $now->modify('+301 seconds');
        self::assertTrue($claim->isExpired($expiredNow));
        self::assertFalse($claim->isPending($expiredNow));
    }

    public function testConsumeAndRevoke(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');
        $claim = $this->newClaim($now);
        $claim->markConsumed($now->modify('+30 seconds'));
        self::assertTrue($claim->isConsumed());
        self::assertFalse($claim->isPending($now->modify('+30 seconds')));

        $other = $this->newClaim($now);
        $other->markRevoked($now->modify('+10 seconds'));
        self::assertTrue($other->isRevoked());
    }

    public function testFailedAttemptsCap(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');
        $claim = $this->newClaim($now);
        for ($i = 0; $i < PhoneVerificationClaim::MAX_FAILED_ATTEMPTS; ++$i) {
            $claim->recordFailedAttempt($now);
        }
        self::assertTrue($claim->hasExceededFailedAttempts());
        $this->expectException(PhoneVerificationException::class);
        $claim->recordFailedAttempt($now);
    }

    public function testCannotConsumeAfterRevoke(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');
        $claim = $this->newClaim($now);
        $claim->markRevoked($now->modify('+5 seconds'));
        $this->expectException(PhoneVerificationException::class);
        $claim->markConsumed($now->modify('+10 seconds'));
    }

    public function testCannotRevokeAfterConsume(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');
        $claim = $this->newClaim($now);
        $claim->markConsumed($now->modify('+5 seconds'));
        $this->expectException(PhoneVerificationException::class);
        $claim->markRevoked($now->modify('+10 seconds'));
    }

    public function testCodeDigestIsSerializerIgnored(): void
    {
        $ref = new \ReflectionProperty(PhoneVerificationClaim::class, 'codeDigest');
        $attrs = $ref->getAttributes(Ignore::class);
        self::assertNotEmpty($attrs);
    }

    public function testBindPhoneOnlyPurpose(): void
    {
        self::assertSame('bind_phone', PhoneVerificationPurpose::BindPhone->value);
    }

    private function newClaim(\DateTimeImmutable $now): PhoneVerificationClaim
    {
        $user = User::create(
            'user@example.com',
            'user@example.com',
            'Ada',
            'Yılmaz',
            'hash',
            UserRole::Student,
            new UuidV7(),
        );
        $digest = str_repeat('ab', 32);
        PhoneOtpDigestHasher::assertDigest($digest);

        return PhoneVerificationClaim::createPending(
            user: $user,
            purpose: PhoneVerificationPurpose::BindPhone,
            targetPhone: '+905321234567',
            targetNormalizedPhone: '+905321234567',
            codeDigest: $digest,
            pepperKeyId: 'v1',
            now: $now,
        );
    }
}
