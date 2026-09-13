<?php

declare(strict_types=1);

namespace App\Enum;

enum EntitlementAccessDecisionReason: string
{
    case AuthenticationRequired = 'authentication_required';
    case UserNotActive = 'user_not_active';
    case EmailNotVerified = 'email_not_verified';
    case ResourceNotFound = 'resource_not_found';
    case ResourceNotPublished = 'resource_not_published';
    case AccessPolicyNotConfigured = 'access_policy_not_configured';
    case EntitlementRequired = 'entitlement_required';
    case LicenseNotActive = 'license_not_active';
    case LicenseNotStarted = 'license_not_started';
    case LicenseExpired = 'license_expired';
    case LicenseSuspended = 'license_suspended';
    case LicenseRevoked = 'license_revoked';
    case SeatRequired = 'seat_required';
    case SeatRevoked = 'seat_revoked';
    case MembershipNotActive = 'membership_not_active';
    case InstitutionNotActive = 'institution_not_active';
    case ScopeMismatch = 'scope_mismatch';
    case IntegrityFailed = 'integrity_failed';
    case AssetNotReady = 'asset_not_ready';
    case Allowed = 'allowed';
}
