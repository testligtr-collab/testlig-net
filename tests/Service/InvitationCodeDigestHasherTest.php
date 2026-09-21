<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\InvitationCodeKind;
use App\Exception\InvitationCodeException;
use App\Service\InvitationCodeDigestHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class InvitationCodeDigestHasherTest extends TestCase
{
    private const PEPPER = 'test_invitation_code_pepper_not_for_production_32ch';

    public function testHashIsStableHexAndVerifies(): void
    {
        $hasher = $this->hasher();
        $id = new UuidV7();
        $code = 'AbcdEFGH12!xyz';

        $digest = $hasher->hash(InvitationCodeKind::PersonalInvitation, $id, 'membership_invite', $code);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        self::assertSame('v1', $hasher->getKeyId());

        $hasher->verify($digest, 'v1', InvitationCodeKind::PersonalInvitation, $id, 'membership_invite', $code);
    }

    public function testDifferentCodeFailsConstantTimeCompare(): void
    {
        $hasher = $this->hasher();
        $id = new UuidV7();
        $digest = $hasher->hash(InvitationCodeKind::ParticipationCode, $id, 'classroom', 'JoinCode99');

        $this->expectException(InvitationCodeException::class);
        $hasher->verify($digest, 'v1', InvitationCodeKind::ParticipationCode, $id, 'classroom', 'JoinCode00');
    }

    public function testKindPurposeAndRecordIdBindDigest(): void
    {
        $hasher = $this->hasher();
        $idA = new UuidV7();
        $idB = new UuidV7();
        $code = 'SamePlainCodeValue1';
        $a = $hasher->hash(InvitationCodeKind::PersonalInvitation, $idA, 'parent_link', $code);
        $b = $hasher->hash(InvitationCodeKind::ParticipationCode, $idA, 'institution', $code);
        $c = $hasher->hash(InvitationCodeKind::PersonalInvitation, $idA, 'teacher_invite', $code);
        $d = $hasher->hash(InvitationCodeKind::PersonalInvitation, $idB, 'parent_link', $code);
        self::assertNotSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertNotSame($a, $d);
    }

    public function testMismatchedPepperKeyIdFailsWithoutCurrentKeyFallback(): void
    {
        $hasher = $this->hasher();
        $id = new UuidV7();
        $digest = $hasher->hash(InvitationCodeKind::PersonalInvitation, $id, 'parent_link', 'HighEntropyToken99');

        $this->expectException(InvitationCodeException::class);
        $hasher->verify($digest, 'v0-legacy', InvitationCodeKind::PersonalInvitation, $id, 'parent_link', 'HighEntropyToken99');
    }

    public function testExceptionMessagesDoNotLeakSecrets(): void
    {
        $plain = 'SecretPlainCodeXYZ1';
        $hasher = $this->hasher();
        $id = new UuidV7();
        $digest = $hasher->hash(InvitationCodeKind::PersonalInvitation, $id, 'parent_link', $plain);

        try {
            $hasher->verify($digest, 'v1', InvitationCodeKind::PersonalInvitation, $id, 'parent_link', 'WrongPlainCodeXYZ1');
            self::fail('Expected mismatch');
        } catch (InvitationCodeException $e) {
            self::assertStringNotContainsString($plain, $e->getMessage());
            self::assertStringNotContainsString('WrongPlainCodeXYZ1', $e->getMessage());
            self::assertStringNotContainsString($digest, $e->getMessage());
            self::assertStringNotContainsString(self::PEPPER, $e->getMessage());
        }
    }

    public function testProductionPlaceholderPepperRejected(): void
    {
        $this->expectException(InvitationCodeException::class);
        new InvitationCodeDigestHasher(
            'change-me-invitation-code-pepper-not-for-production-32b',
            'v1',
            'prod',
        );
    }

    public function testShortPepperRejected(): void
    {
        $this->expectException(InvitationCodeException::class);
        new InvitationCodeDigestHasher('too-short', 'v1', 'test');
    }

    public function testNoAppSecretFallbackInSource(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Service/InvitationCodeDigestHasher.php');
        self::assertStringNotContainsString('%env(APP_SECRET)%', $source);
        self::assertStringNotContainsString('kernel.secret', $source);
        self::assertStringNotContainsString('hash(\'sha256\'', $source);
        self::assertStringContainsString("hash_hmac('sha256'", $source);
        self::assertStringContainsString('hash_equals', $source);
        self::assertStringContainsString('pepperKeyMismatch', $source);
    }

    private function hasher(): InvitationCodeDigestHasher
    {
        return new InvitationCodeDigestHasher(self::PEPPER, 'v1', 'test');
    }
}
