<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\SecurityAuditMetadataException;
use App\Service\SecurityAuditMetadataSanitizer;
use PHPUnit\Framework\TestCase;

final class PhoneVerificationAuditMetadataSanitizerTest extends TestCase
{
    public function testAllowsSafePhoneClaimKeys(): void
    {
        $sanitizer = new SecurityAuditMetadataSanitizer();
        $clean = $sanitizer->sanitize([
            'claim_id' => '0199a000-0000-7000-8000-000000000001',
            'purpose' => 'bind_phone',
            'reason_code' => 'invalid_otp',
            'failed_attempt_count' => 2,
            'revoked_claim_count' => 1,
        ]);

        self::assertSame('bind_phone', $clean['purpose']);
        self::assertSame(2, $clean['failed_attempt_count']);
        self::assertSame(1, $clean['revoked_claim_count']);
    }

    public function testRejectsPepperKeyIdFromAuditMetadata(): void
    {
        $sanitizer = new SecurityAuditMetadataSanitizer();
        $this->expectException(SecurityAuditMetadataException::class);
        $sanitizer->sanitize(['pepper_key_id' => 'v1']);
    }

    public function testRejectsSensitivePhoneKeys(): void
    {
        $sanitizer = new SecurityAuditMetadataSanitizer();
        foreach (['otp', 'plain_otp', 'phone', 'normalized_phone', 'code_digest', 'pepper', 'target_phone'] as $key) {
            try {
                $sanitizer->sanitize([$key => 'x']);
                self::fail('Expected forbidden key: '.$key);
            } catch (SecurityAuditMetadataException $e) {
                self::assertInstanceOf(SecurityAuditMetadataException::class, $e);
            }
        }
    }
}
