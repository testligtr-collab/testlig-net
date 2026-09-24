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

    /**
     * User list/detail: active+verified ADMIN or SUPER_ADMIN.
     */
    public function assertCanViewUsers(User $actor): void
    {
        $this->assertCanAccessAdminShell($actor);
    }

    /**
     * User role/status mutations: same shell gate; domain managers re-assert.
     */
    public function assertCanManageUsers(User $actor): void
    {
        $this->assertCanAccessAdminShell($actor);
    }

    /**
     * Institution list/detail: active+verified ADMIN or SUPER_ADMIN.
     */
    public function assertCanViewInstitutions(User $actor): void
    {
        $this->assertCanAccessAdminShell($actor);
    }

    /**
     * Institution create / status: SUPER_ADMIN only (matches InstitutionCreator/StatusManager).
     */
    public function assertCanCreateInstitutions(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if (!$this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            throw CommerceException::unauthorized();
        }
    }

    public function assertCanManageInstitutions(User $actor): void
    {
        $this->assertCanCreateInstitutions($actor);
    }

    /**
     * Membership list/manage: SUPER_ADMIN only (domain allows SA or institution owner/manager;
     * admin panel does not expose institution-scoped owner tools here).
     */
    public function assertCanViewMemberships(User $actor): void
    {
        $this->assertCanCreateInstitutions($actor);
    }

    public function assertCanManageMemberships(User $actor): void
    {
        $this->assertCanCreateInstitutions($actor);
    }

    /**
     * Course catalog admin: active+verified ADMIN or SUPER_ADMIN.
     */
    public function assertCanViewCatalog(User $actor): void
    {
        $this->assertCanAccessAdminShell($actor);
    }

    public function assertCanManageCatalog(User $actor): void
    {
        $this->assertCanAccessAdminShell($actor);
    }

    /**
     * Canonical Subject mapping on CatalogSubject: SA | Admin | Head | Expert.
     */
    public function assertCanMapCatalogCanonical(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if (!$this->hasAnyRole($actor, [
            UserRole::SuperAdmin,
            UserRole::Admin,
            UserRole::HeadTeacher,
            UserRole::ExpertTeacher,
        ])) {
            throw CommerceException::unauthorized();
        }
    }

    /**
     * Learning content workspace list/detail: SA | Admin | Head | Expert | Moderator | Teacher.
     */
    public function assertCanViewLearningContentWorkspace(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if (!$this->hasAnyRole($actor, [
            UserRole::SuperAdmin,
            UserRole::Admin,
            UserRole::HeadTeacher,
            UserRole::ExpertTeacher,
            UserRole::Moderator,
            UserRole::Teacher,
        ])) {
            throw CommerceException::unauthorized();
        }
    }

    /**
     * Learning content create / policy mutations: SA | Admin | Head | Expert | Teacher.
     */
    public function assertCanManageLearningContentWorkspace(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if (!$this->hasAnyRole($actor, [
            UserRole::SuperAdmin,
            UserRole::Admin,
            UserRole::HeadTeacher,
            UserRole::ExpertTeacher,
            UserRole::Teacher,
        ])) {
            throw CommerceException::unauthorized();
        }
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

    public function canViewUsers(User $actor): bool
    {
        return $this->canAccessAdminShell($actor);
    }

    public function canManageUsers(User $actor): bool
    {
        return $this->canAccessAdminShell($actor);
    }

    public function canViewInstitutions(User $actor): bool
    {
        return $this->canAccessAdminShell($actor);
    }

    public function canCreateInstitutions(User $actor): bool
    {
        return $this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor);
    }

    public function canManageInstitutions(User $actor): bool
    {
        return $this->canCreateInstitutions($actor);
    }

    public function canViewMemberships(User $actor): bool
    {
        return $this->canCreateInstitutions($actor);
    }

    public function canManageMemberships(User $actor): bool
    {
        return $this->canCreateInstitutions($actor);
    }

    public function canViewCatalog(User $actor): bool
    {
        return $this->canAccessAdminShell($actor);
    }

    public function canManageCatalog(User $actor): bool
    {
        return $this->canAccessAdminShell($actor);
    }

    public function canMapCatalogCanonical(User $actor): bool
    {
        return $this->activeVerifiedUserPolicy->isActiveAndVerified($actor)
            && $this->hasAnyRole($actor, [
                UserRole::SuperAdmin,
                UserRole::Admin,
                UserRole::HeadTeacher,
                UserRole::ExpertTeacher,
            ]);
    }

    public function canViewLearningContentWorkspace(User $actor): bool
    {
        return $this->activeVerifiedUserPolicy->isActiveAndVerified($actor)
            && $this->hasAnyRole($actor, [
                UserRole::SuperAdmin,
                UserRole::Admin,
                UserRole::HeadTeacher,
                UserRole::ExpertTeacher,
                UserRole::Moderator,
                UserRole::Teacher,
            ]);
    }

    public function canManageLearningContentWorkspace(User $actor): bool
    {
        return $this->activeVerifiedUserPolicy->isActiveAndVerified($actor)
            && $this->hasAnyRole($actor, [
                UserRole::SuperAdmin,
                UserRole::Admin,
                UserRole::HeadTeacher,
                UserRole::ExpertTeacher,
                UserRole::Teacher,
            ]);
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
        return $this->hasAnyRole($actor, [UserRole::Admin, UserRole::SuperAdmin]);
    }

    /**
     * @param list<UserRole> $roles
     */
    private function hasAnyRole(User $actor, array $roles): bool
    {
        $held = $actor->getRoles();
        foreach ($roles as $role) {
            if (\in_array($role->value, $held, true)) {
                return true;
            }
        }

        return false;
    }
}
