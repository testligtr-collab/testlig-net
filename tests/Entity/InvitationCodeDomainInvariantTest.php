<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Institution;
use App\Entity\ParticipationCode;
use App\Entity\PersonalInvitation;
use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\ParticipationCodeScope;
use App\Enum\UserRole;
use App\Exception\InvitationCodeException;
use App\Invitation\InvitationPurposeContract;
use App\Service\InvitationCodeDigestHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\UuidV7;

final class InvitationCodeDomainInvariantTest extends TestCase
{
    private const DIGEST_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const DIGEST_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testPersonalInvitationRequiresRecipientAndIsSingleUse(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T12:00:00+00:00');
        $invite = PersonalInvitation::createPending(
            createdBy: $this->user('creator@example.com'),
            intendedRecipient: $this->user('recipient@example.com'),
            purposeCode: 'membership_invite',
            codeDigest: self::DIGEST_A,
            pepperKeyId: 'v1',
            expiresAt: $now->modify('+1 day'),
            now: $now,
        );

        self::assertTrue($invite->isUsable($now));
        self::assertFalse($invite->isUsable($now->modify('+2 days')));

        $invite->markConsumed($now);
        self::assertTrue($invite->isConsumed());
        self::assertFalse($invite->isUsable($now));

        $this->expectException(InvitationCodeException::class);
        $invite->markConsumed($now);
    }

    public function testPersonalInvitationRevokeBlocksConsume(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T12:00:00+00:00');
        $invite = PersonalInvitation::createPending(
            createdBy: $this->user('creator2@example.com'),
            intendedRecipient: $this->user('recipient2@example.com'),
            purposeCode: 'teacher_invite',
            codeDigest: self::DIGEST_B,
            pepperKeyId: 'v1',
            expiresAt: $now->modify('+1 day'),
            now: $now,
        );
        $invite->markRevoked($now);
        self::assertFalse($invite->isUsable($now));

        $this->expectException(InvitationCodeException::class);
        $invite->markConsumed($now);
    }

    public function testPersonalInvitationRejectsSelfRecipient(): void
    {
        $user = $this->user('same@example.com');
        $now = new \DateTimeImmutable('2026-09-21T12:00:00+00:00');

        $this->expectException(InvitationCodeException::class);
        PersonalInvitation::createPending(
            createdBy: $user,
            intendedRecipient: $user,
            purposeCode: 'parent_link',
            codeDigest: self::DIGEST_A,
            pepperKeyId: 'v1',
            expiresAt: $now->modify('+1 day'),
            now: $now,
        );
    }

    public function testUnknownPurposeIsRejectedForRedeemFailClosed(): void
    {
        self::assertSame(['parent_link'], InvitationPurposeContract::REDEEM_ALLOW_LIST);
        self::assertSame('parent_link', InvitationPurposeContract::assertKnownForRedeem('parent_link'));
        self::assertSame('membership_invite', InvitationPurposeContract::normalizeForStorage('membership_invite'));

        $this->expectException(InvitationCodeException::class);
        InvitationPurposeContract::assertKnownForRedeem('membership_invite');
    }

    public function testParticipationCodeQuotaAndRevoke(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T12:00:00+00:00');
        $institution = Institution::create(
            'Demo Okul',
            'demo okul',
            'demo-okul',
            InstitutionType::School,
            $now,
        );
        $code = ParticipationCode::createActive(
            createdBy: $this->user('manager@example.com'),
            institution: $institution,
            scope: ParticipationCodeScope::Institution,
            codeDigest: self::DIGEST_A,
            pepperKeyId: 'v1',
            maxRedemptions: 2,
            expiresAt: $now->modify('+7 days'),
            now: $now,
        );

        self::assertTrue($code->isUsable($now));
        $code->recordRedemption($now);
        self::assertSame(1, $code->getRedemptionCount());
        self::assertSame(1, $code->getRemainingRedemptions());
        $code->recordRedemption($now);
        self::assertTrue($code->isExhausted());
        self::assertFalse($code->isUsable($now));

        $this->expectException(InvitationCodeException::class);
        $code->recordRedemption($now);
    }

    public function testParticipationCodeRejectsInvalidMaxRedemptions(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T12:00:00+00:00');
        $institution = Institution::create(
            'Demo Okul Cap',
            'demo okul cap',
            'demo-okul-cap',
            InstitutionType::School,
            $now,
        );

        $this->expectException(InvitationCodeException::class);
        ParticipationCode::createActive(
            createdBy: $this->user('manager-cap@example.com'),
            institution: $institution,
            scope: ParticipationCodeScope::Institution,
            codeDigest: self::DIGEST_A,
            pepperKeyId: 'v1',
            maxRedemptions: 0,
            expiresAt: $now->modify('+7 days'),
            now: $now,
        );
    }

    public function testParticipationClassroomScopeRequiresMatchingClassroom(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T12:00:00+00:00');
        $institution = Institution::create(
            'Demo Okul 2',
            'demo okul 2',
            'demo-okul-2',
            InstitutionType::School,
            $now,
        );

        $this->expectException(InvitationCodeException::class);
        ParticipationCode::createActive(
            createdBy: $this->user('manager2@example.com'),
            institution: $institution,
            scope: ParticipationCodeScope::Classroom,
            codeDigest: self::DIGEST_B,
            pepperKeyId: 'v1',
            maxRedemptions: 5,
            expiresAt: $now->modify('+7 days'),
            now: $now,
            classroom: null,
        );
    }

    public function testParticipationInstitutionScopeRejectsClassroomAttachment(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T12:00:00+00:00');
        $institution = Institution::create(
            'Demo Okul 3',
            'demo okul 3',
            'demo-okul-3',
            InstitutionType::School,
            $now,
        );
        $classroom = $this->createStub(\App\Entity\Classroom::class);
        $classroom->method('getInstitution')->willReturn($institution);
        $classroom->method('getId')->willReturn(new UuidV7());

        $this->expectException(InvitationCodeException::class);
        ParticipationCode::createActive(
            createdBy: $this->user('manager3@example.com'),
            institution: $institution,
            scope: ParticipationCodeScope::Institution,
            codeDigest: self::DIGEST_B,
            pepperKeyId: 'v1',
            maxRedemptions: 3,
            expiresAt: $now->modify('+7 days'),
            now: $now,
            classroom: $classroom,
        );
    }

    public function testDigestGetterIsIgnoredForSerializerContract(): void
    {
        $reflection = new \ReflectionClass(PersonalInvitation::class);
        $prop = $reflection->getProperty('codeDigest');
        $attrs = $prop->getAttributes(Ignore::class);
        self::assertNotEmpty($attrs);

        $reflectionCode = new \ReflectionClass(ParticipationCode::class);
        $propCode = $reflectionCode->getProperty('codeDigest');
        self::assertNotEmpty($propCode->getAttributes(Ignore::class));
    }

    public function testHasherPurposeHelperRejectsInvalidPurpose(): void
    {
        $this->expectException(InvitationCodeException::class);
        InvitationCodeDigestHasher::assertPurposeCode('Bad Purpose');
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
