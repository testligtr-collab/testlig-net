<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\Admin\ContentWorkspaceSummary;
use App\Service\InstitutionWorkspaceGate;
use App\Service\StudentProfileManager;

/**
 * Chooses the page after a successful login.
 *
 * Student, parent, and active institution leadership come first.
 * Admin shell beats the content workspace. The workspace decision uses the
 * same authorization and active-assignment count as /calisma-alani.
 */
final class PostLoginDestinationResolver
{
    public function __construct(
        private readonly StudentProfileManager $profiles,
        private readonly InstitutionWorkspaceGate $institutionWorkspace,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly ContentWorkspaceSummary $workspaceSummary,
    ) {
    }

    public function resolve(User $user, ?string $targetPath): PostLoginDestination
    {
        $target = $this->acceptedTarget($user, $targetPath);
        if (null !== $target) {
            return PostLoginDestination::path($target);
        }

        return PostLoginDestination::route($this->routeFor($user));
    }

    private function routeFor(User $user): string
    {
        if ($this->hasRole($user, UserRole::Student)) {
            return $this->profiles->isOnboardingCompleted($user)
                ? PostLoginRoute::STUDENT_HOME
                : PostLoginRoute::STUDENT_ONBOARDING;
        }
        if ($this->hasRole($user, UserRole::Parent)) {
            return PostLoginRoute::PARENT_HOME;
        }

        $institution = $this->institutionWorkspace->resolve($user);
        if (InstitutionWorkspaceGate::PANEL === $institution->outcome || InstitutionWorkspaceGate::CHOOSE === $institution->outcome) {
            return PostLoginRoute::INSTITUTION_HOME;
        }
        if ($this->adminAuthorization->canAccessAdminShell($user)) {
            return PostLoginRoute::ADMIN_HOME;
        }
        if ($this->canOpenWorkspace($user)) {
            return PostLoginRoute::WORKSPACE_HOME;
        }

        return PostLoginRoute::ACCOUNT_HOME;
    }

    private function acceptedTarget(User $user, ?string $targetPath): ?string
    {
        if (!\is_string($targetPath) || '' === $targetPath) {
            return null;
        }
        if (!str_starts_with($targetPath, '/') || str_starts_with($targetPath, '//')) {
            return null;
        }
        if (str_contains($targetPath, '://') || str_contains($targetPath, '\\')) {
            return null;
        }

        $path = $targetPath;
        $suffix = '';
        $query = strpos($targetPath, '?');
        if (false !== $query) {
            $path = substr($targetPath, 0, $query);
            $suffix = substr($targetPath, $query);
        }
        if (str_contains($path, '#') || str_contains($suffix, '://') || str_contains($suffix, '\\') || str_contains($suffix, '//')) {
            return null;
        }
        if ('/giris' === $path || str_starts_with($path, '/giris/') || '/cikis' === $path || str_starts_with($path, '/cikis/')) {
            return null;
        }
        if (!$this->canOpenPath($user, $path)) {
            return null;
        }

        return $path.$suffix;
    }

    private function canOpenPath(User $user, string $path): bool
    {
        if (str_starts_with($path, '/yonetim/icerikler/yeni')) {
            return $this->adminAuthorization->canManageLearningContentWorkspace($user);
        }
        if (str_starts_with($path, '/yonetim/sorular/yeni')) {
            return $this->adminAuthorization->canAuthorQuestions($user);
        }
        if (str_starts_with($path, '/yonetim/testler/yeni')) {
            return $this->adminAuthorization->canAuthorTests($user);
        }
        if (str_starts_with($path, '/yonetim/icerikler')
            || str_starts_with($path, '/yonetim/sorular')
            || str_starts_with($path, '/yonetim/testler')) {
            return $this->adminAuthorization->canViewLearningContentWorkspace($user);
        }
        if ('/yonetim' === $path || str_starts_with($path, '/yonetim/')) {
            return $this->adminAuthorization->canAccessAdminShell($user);
        }
        if ('/calisma-alani' === $path || str_starts_with($path, '/calisma-alani/')) {
            return $this->canOpenWorkspace($user);
        }
        if ('/ogrenci' === $path || str_starts_with($path, '/ogrenci/')) {
            return $this->hasRole($user, UserRole::Student);
        }
        if ('/veli' === $path || str_starts_with($path, '/veli/')) {
            return $this->hasRole($user, UserRole::Parent);
        }
        if ('/kurum' === $path || str_starts_with($path, '/kurum/')) {
            $institution = $this->institutionWorkspace->resolve($user);

            return InstitutionWorkspaceGate::PANEL === $institution->outcome
                || InstitutionWorkspaceGate::CHOOSE === $institution->outcome;
        }
        if ('/ogretmen' === $path || str_starts_with($path, '/ogretmen/')) {
            return $this->workspaceSummary->activeClassroomCount($user) > 0;
        }

        return true;
    }

    private function canOpenWorkspace(User $user): bool
    {
        return $this->adminAuthorization->canViewLearningContentWorkspace($user)
            || $this->workspaceSummary->activeClassroomCount($user) > 0;
    }

    private function hasRole(User $user, UserRole $role): bool
    {
        return \in_array($role->value, $user->getRoles(), true);
    }
}
