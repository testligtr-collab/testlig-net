<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\PhoneVerificationException;
use App\Service\PhoneNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class UserPhoneBindingTest extends TestCase
{
    public function testBindVerifiedPhoneSetsTriplet(): void
    {
        $user = $this->user();
        self::assertFalse($user->hasVerifiedPhone());

        $at = new \DateTimeImmutable('2026-09-20 12:00:00');
        $user->bindVerifiedPhone('+905321234567', '+905321234567', $at);

        self::assertTrue($user->hasVerifiedPhone());
        self::assertSame('+905321234567', $user->getPhone());
        self::assertSame('+905321234567', $user->getNormalizedPhone());
        self::assertSame($at, $user->getPhoneVerifiedAt());
    }

    public function testBindRejectsNonCanonical(): void
    {
        $user = $this->user();
        $this->expectException(PhoneVerificationException::class);
        $user->bindVerifiedPhone('05321234567', '05321234567', new \DateTimeImmutable('now'));
    }

    public function testBindRejectsDisplayNormalizedMismatch(): void
    {
        $user = $this->user();
        $this->expectException(PhoneVerificationException::class);
        $user->bindVerifiedPhone('05321234567', '+905321234567', new \DateTimeImmutable('now'));
    }

    public function testBindRejectsLandlineNormalized(): void
    {
        $user = $this->user();
        $this->expectException(PhoneVerificationException::class);
        $user->bindVerifiedPhone('+902121234567', '+902121234567', new \DateTimeImmutable('now'));
    }

    public function testCanonicalPatternConstant(): void
    {
        self::assertSame(1, preg_match(PhoneNormalizer::CANONICAL_PATTERN, '+905551112233'));
        self::assertSame(0, preg_match(PhoneNormalizer::CANONICAL_PATTERN, '+902121234567'));
    }

    private function user(): User
    {
        return User::create(
            'phone@example.com',
            'phone@example.com',
            'Can',
            'Demir',
            'hash',
            UserRole::Student,
            new UuidV7(),
        );
    }
}
