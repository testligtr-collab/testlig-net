<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ParentStudentLink;
use App\Entity\ParentStudentLinkActiveGuard;
use App\Entity\User;
use App\Enum\ParentStudentLinkStatus;
use App\Enum\UserRole;
use App\Exception\ParentStudentLinkException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Domain invariants for Stage 2.22.5a. Verified status here is not an access grant.
 */
final class ParentStudentLinkDomainInvariantTest extends TestCase
{
    public function testPendingRejectsSameUserAsParentAndStudent(): void
    {
        $user = $this->user('same@example.com');
        $now = new \DateTimeImmutable('2026-09-21T15:00:00+00:00');

        $this->expectException(ParentStudentLinkException::class);
        ParentStudentLink::createPending($user, $user, $user, $now);
    }

    public function testLifecyclePendingVerifiedEndedAndHistorySlot(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T15:00:00+00:00');
        $parent = $this->user('parent@example.com');
        $student = $this->user('student@example.com');

        $link = ParentStudentLink::createPending($parent, $student, $parent, $now);
        self::assertTrue($link->isPending());
        self::assertTrue($link->isActivePairOccupant());
        self::assertNull($link->getVerifiedAt());
        self::assertNull($link->getEndedAt());

        $guard = ParentStudentLinkActiveGuard::bind($link);
        self::assertTrue($guard->getParentUserId()->equals($parent->getId()));
        self::assertTrue($guard->getStudentUserId()->equals($student->getId()));

        $verifiedAt = $now->modify('+1 hour');
        $link->markVerified($student, $verifiedAt);
        self::assertSame(ParentStudentLinkStatus::Verified, $link->getStatus());
        self::assertTrue($link->isActivePairOccupant());
        self::assertSame($verifiedAt, $link->getVerifiedAt());
        // Representational verified ≠ child-data authorization in this foundation slice.
        self::assertTrue($link->isVerified());

        $endedAt = $verifiedAt->modify('+1 day');
        $link->markEnded($endedAt);
        self::assertTrue($link->isEnded());
        self::assertFalse($link->isActivePairOccupant());
        self::assertSame($endedAt, $link->getEndedAt());

        $this->expectException(ParentStudentLinkException::class);
        ParentStudentLinkActiveGuard::bind($link);
    }

    public function testCancelPendingWithoutVerification(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T15:00:00+00:00');
        $parent = $this->user('p2@example.com');
        $student = $this->user('s2@example.com');
        $link = ParentStudentLink::createPending($parent, $student, $parent, $now);
        $link->markEnded($now->modify('+10 minutes'));
        self::assertTrue($link->isEnded());
        self::assertNull($link->getVerifiedAt());
    }

    public function testCannotVerifyEndedOrDoubleEnd(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T15:00:00+00:00');
        $parent = $this->user('p3@example.com');
        $student = $this->user('s3@example.com');
        $link = ParentStudentLink::createPending($parent, $student, $parent, $now);
        $link->markEnded($now->modify('+1 minute'));

        try {
            $link->markVerified($student, $now->modify('+2 minutes'));
            self::fail('Expected invalid transition');
        } catch (ParentStudentLinkException) {
            self::assertTrue($link->isEnded());
        }

        $this->expectException(ParentStudentLinkException::class);
        $link->markEnded($now->modify('+3 minutes'));
    }

    public function testEndedHistoryAllowsNewPendingForSamePairConceptually(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T15:00:00+00:00');
        $parent = $this->user('p4@example.com');
        $student = $this->user('s4@example.com');

        $first = ParentStudentLink::createPending($parent, $student, $parent, $now);
        $first->markEnded($now->modify('+1 hour'));
        self::assertFalse($first->isActivePairOccupant());

        $second = ParentStudentLink::createPending($parent, $student, $student, $now->modify('+2 hours'));
        self::assertTrue($second->isPending());
        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        ParentStudentLinkActiveGuard::bind($second);
    }

    private function user(string $email): User
    {
        return User::create(
            email: $email,
            normalizedEmail: strtolower($email),
            firstName: 'Test',
            lastName: 'User',
            passwordHash: '!',
            initialRole: UserRole::User,
            id: new UuidV7(),
        );
    }
}
