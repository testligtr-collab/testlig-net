<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Stable, lowercase snake_case values persisted for security audit events.
 */
enum SecurityAuditAction: string
{
    case UserRegistered = 'user_registered';
    case EmailVerified = 'email_verified';
    case LoginSucceeded = 'login_succeeded';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordChanged = 'password_changed';
    case RoleChanged = 'role_changed';
    case StatusChanged = 'status_changed';
    case SuperAdminBootstrapped = 'super_admin_bootstrapped';
    case InstitutionCreated = 'institution_created';
    case InstitutionStatusChanged = 'institution_status_changed';
    case InstitutionMemberAdded = 'institution_member_added';
    case InstitutionMemberRoleChanged = 'institution_member_role_changed';
    case InstitutionMemberSuspended = 'institution_member_suspended';
    case InstitutionMemberReactivated = 'institution_member_reactivated';
    case InstitutionMemberEnded = 'institution_member_ended';
}
