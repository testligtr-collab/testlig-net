<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Exception\InvitationCodeException;
use App\Service\InvitationCodeDigestHasher;

/**
 * Opaque purpose_code contract for PersonalInvitation (ADR §7.A).
 *
 * Stage 2.22.5b enables only {@see PURPOSE_PARENT_LINK} for student→parent consent.
 * Other purposes remain fail-closed at redeem/issuance gates used by consent managers.
 */
final class InvitationPurposeContract
{
    public const PURPOSE_PARENT_LINK = 'parent_link';

    /**
     * Approved purpose allow-list for invitation redeem / parent-link issuance.
     *
     * @var list<string>
     */
    public const REDEEM_ALLOW_LIST = [
        self::PURPOSE_PARENT_LINK,
    ];

    private function __construct()
    {
    }

    public static function normalizeForStorage(string $purposeCode): string
    {
        return InvitationCodeDigestHasher::assertPurposeCode($purposeCode);
    }

    /**
     * Fail-closed gate: unknown purposes never map to a default membership type.
     */
    public static function assertKnownForRedeem(string $purposeCode): string
    {
        $purposeCode = self::normalizeForStorage($purposeCode);
        foreach (self::REDEEM_ALLOW_LIST as $approved) {
            if (hash_equals($approved, $purposeCode)) {
                return $purposeCode;
            }
        }

        throw InvitationCodeException::unknownPurpose();
    }
}
