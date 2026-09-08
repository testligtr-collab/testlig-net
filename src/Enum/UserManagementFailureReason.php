<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Stable machine-readable reasons for privileged user mutation failures.
 */
enum UserManagementFailureReason: string
{
    case ActorNotActive = 'actor_not_active';
    case ActorNotVerified = 'actor_not_verified';
    case ActorNotAuthorized = 'actor_not_authorized';
    case SelfManagementForbidden = 'self_management_forbidden';
    case ProtectedSuperAdmin = 'protected_super_admin';
    case TargetPrivilegeTooHigh = 'target_privilege_too_high';
    case UserNotFound = 'user_not_found';
    case Conflict = 'conflict';
    case InvalidTransition = 'invalid_transition';
    case InvalidInput = 'invalid_input';
}
