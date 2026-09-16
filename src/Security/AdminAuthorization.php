<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\CommerceException;
use App\Service\ActiveVerifiedUserPolicy;

/**
 * Authorization for the Stage 2.20 admin / SuperAdmin operations panel.
 *
 * Callers must pass a freshly loaded User (FreshUserLoader + PESSIMISTIC_READ).
 * Holding ROLE_* on a stale in-memory User never bypasses these gates.
 */
final class AdminAuthorization
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly CommerceAuthorization $commerceAuthorization,
    ) {
    }

    /**
     * Active+verified ADMIN or SUPER_ADMIN may enter the admin shell.
     */
    public function assertCanAccessAdminShell(User $actor): void
    {
        $this->assertActiveVerifiedAdminOrSuperAdmin($actor);
    }

    /**
     * Same gate as shell access; ADMIN sees limited system content in the read-model.
     */
    public function assertCanViewSystemSummary(User $actor): void
    {
        $this->assertCanAccessAdminShell($actor);
    }

    /**
     * Payment / webhook / reconciliation operations: SUPER_ADMIN only.
     */
    public function assertCanOperatePayments(User $actor): void
    {
        $this->commerceAuthorization->assertCanOperatePayments($actor);
    }

    /**
     * Security audit listing: SUPER_ADMIN only.
     *
     * ADMIN is denied because audit metadata may include operational identifiers
     * (provider codes, inbox event ids, reason codes) beyond general admin scope.
     */
    public function assertCanViewSecurityAudit(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if (!$this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            throw CommerceException::unauthorized();
        }
    }

    /**
     * Dead-letter requeue: same bar as payment operations (SUPER_ADMIN).
     */
    public function assertCanRequeueDeadLetter(User $actor): void
    {
        $this->assertCanOperatePayments($actor);
    }

    public function canAccessAdminShell(User $actor): bool
    {
        return $this->isActiveVerifiedAdminOrSuperAdmin($actor);
    }

    public function canViewSystemSummary(User $actor): bool
    {
        return $this->canAccessAdminShell($actor);
    }

    public function canOperatePayments(User $actor): bool
    {
        return $this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor);
    }

    public function canViewSecurityAudit(User $actor): bool
    {
        return $this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor);
    }

    public function canRequeueDeadLetter(User $actor): bool
    {
        return $this->canOperatePayments($actor);
    }

    private function assertActiveVerifiedAdminOrSuperAdmin(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if (!$this->isAdminOrSuperAdmin($actor)) {
            throw CommerceException::unauthorized();
        }
    }

    private function assertActiveVerified(User $actor): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw CommerceException::unauthorized();
        }
    }

    private function isActiveVerifiedAdminOrSuperAdmin(User $actor): bool
    {
        return $this->activeVerifiedUserPolicy->isActiveAndVerified($actor)
            && $this->isAdminOrSuperAdmin($actor);
    }

    private function isAdminOrSuperAdmin(User $actor): bool
    {
        $roles = $actor->getRoles();

        return \in_array(UserRole::Admin->value, $roles, true)
            || \in_array(UserRole::SuperAdmin->value, $roles, true);
    }
}
