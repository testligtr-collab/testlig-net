<?php

declare(strict_types=1);

namespace App\Invitation;

/**
 * Stage 2.22.4b+ issuance authority notes (not wired; no public create routes in 2.22.4a).
 *
 * PersonalInvitation:
 * - Actor must be active + e-posta verified (ActiveVerifiedUserPolicy).
 * - Intended recipient User is required at issuance (ADR §7.A belirli alıcı). Recipient-less
 *   personal invites must not be treated as participation codes.
 * - Unverified e-mail alone must never auto-bind a recipient. Redeem requires the authenticated
 *   actor to match intendedRecipient after identity verification (ADR §7.1).
 * - Pre-account personal invites (no User yet) need a separate verified binding field — not in
 *   this foundation; do not leave intendedRecipient null as a workaround.
 * - purpose_code is opaque metadata only; see InvitationPurposeContract (fail-closed redeem).
 *
 * ParticipationCode:
 * - Actor must hold a fresh, active InstitutionMembership with issuance privilege for the
 *   target institution/classroom (InstitutionVoter / ClassroomVoter snapshot — not session context).
 * - Creating a code never grants roles or enrollments; redeem is a later transaction.
 *
 * Controllers must not call entity factories directly; prefer future *Manager services.
 */
final class InvitationIssuanceAuthority
{
    private function __construct()
    {
    }
}
