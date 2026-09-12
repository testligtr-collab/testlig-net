<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningContentAccessDecisionReason: string
{
    case Allowed = 'allowed';
    case NotFound = 'not_found';
    case NotPublished = 'not_published';
    case Archived = 'archived';
    case InstitutionInactive = 'institution_inactive';
    case MembershipInactive = 'membership_inactive';
    case TenantMismatch = 'tenant_mismatch';
    case AssetNotReady = 'asset_not_ready';
    case Unauthorized = 'unauthorized';
    case AccessPolicyNotConfigured = 'access_policy_not_configured';
    case EntitlementRequired = 'entitlement_required';
}
