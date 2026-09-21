<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Exception\InvitationCodeException;
use App\Service\InvitationCodeDigestHasher;

/**
 * Opaque purpose_code contract for PersonalInvitation (ADR §7.A).
 *
 * ADR lists only example purposes (parent link, teacher–institution, membership) — not a
 * closed product enum. Therefore:
 * - Issuance may persist a format-valid opaque purpose_code as routing metadata only.
 * - purpose_code never grants UserRole, InstitutionMembership, or classroom access.
 * - Redeem managers MUST call assertKnownForRedeem and fail closed on unknown values.
 * - Do not map unknown purposes to a default membership type.
 *
 * @phpstan-type PurposeCode string
 */
final class InvitationPurposeContract
{
    /**
     * Approved redeem allow-list. Empty until product locks ADR §7.A examples into policy.
     *
     * @var list<string>
     */
    public const REDEEM_ALLOW_LIST = [];

    private function __construct()
    {
    }

    public static function normalizeForStorage(string $purposeCode): string
    {
        return InvitationCodeDigestHasher::assertPurposeCode($purposeCode);
    }

    /**
     * Fail-closed gate for future redeem / membership wiring.
     *
     * Until product locks ADR §7.A examples into {@see REDEEM_ALLOW_LIST}, every purpose
     * is unknown at redeem time. Do not map unknowns to a default membership type.
     */
    public static function assertKnownForRedeem(string $purposeCode): never
    {
        self::normalizeForStorage($purposeCode);

        throw InvitationCodeException::unknownPurpose();
    }
}
