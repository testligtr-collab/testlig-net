<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\PhoneVerificationPurpose;
use App\Exception\PhoneVerificationException;
use App\Service\PhoneOtpDigestHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class PhoneOtpDigestHasherTest extends TestCase
{
    private const PEPPER = 'test_phone_otp_pepper_not_for_production_32chars_min';

    public function testHashIsStableHexAndVerifies(): void
    {
        $hasher = $this->hasher();
        $claimId = new UuidV7();
        $phone = '+905321234567';
        $otp = '123456';

        $digest = $hasher->hash(PhoneVerificationPurpose::BindPhone, $claimId, $phone, $otp);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        self::assertSame('v1', $hasher->getKeyId());

        $hasher->verify($digest, PhoneVerificationPurpose::BindPhone, $claimId, $phone, $otp);
        // verify() throws on mismatch; reaching here means digest matched.
    }

    public function testDifferentOtpFailsConstantTimeCompare(): void
    {
        $hasher = $this->hasher();
        $claimId = new UuidV7();
        $digest = $hasher->hash(PhoneVerificationPurpose::BindPhone, $claimId, '+905321234567', '123456');

        $this->expectException(PhoneVerificationException::class);
        $hasher->verify($digest, PhoneVerificationPurpose::BindPhone, $claimId, '+905321234567', '654321');
    }

    public function testContextBindingChangesDigest(): void
    {
        $hasher = $this->hasher();
        $claimId = new UuidV7();
        $a = $hasher->hash(PhoneVerificationPurpose::BindPhone, $claimId, '+905321234567', '123456');
        $b = $hasher->hash(PhoneVerificationPurpose::BindPhone, $claimId, '+905399999999', '123456');
        self::assertNotSame($a, $b);
    }

    public function testProductionPlaceholderPepperRejected(): void
    {
        $this->expectException(PhoneVerificationException::class);
        new PhoneOtpDigestHasher(
            'change-me-phone-otp-pepper-not-for-production-32b',
            'v1',
            'prod',
        );
    }

    public function testShortPepperRejected(): void
    {
        $this->expectException(PhoneVerificationException::class);
        new PhoneOtpDigestHasher('too-short', 'v1', 'test');
    }

    public function testNoAppSecretFallbackInSource(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Service/PhoneOtpDigestHasher.php');
        self::assertStringNotContainsString('%env(APP_SECRET)%', $source);
        self::assertStringNotContainsString('kernel.secret', $source);
        self::assertStringNotContainsString('hash(\'sha256\'', $source);
        self::assertStringContainsString("hash_hmac('sha256'", $source);
        self::assertStringContainsString('hash_equals', $source);
    }

    private function hasher(): PhoneOtpDigestHasher
    {
        return new PhoneOtpDigestHasher(self::PEPPER, 'v1', 'test');
    }
}
