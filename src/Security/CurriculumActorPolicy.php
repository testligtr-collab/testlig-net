<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\CurriculumOperation;
use App\Enum\ModeratorModuleGrant;
use App\Enum\UserRole;
use App\Repository\UserModulePermissionRepository;
use App\Security\Authorization\UserAuthorizationSnapshot;
use App\Service\ActiveVerifiedUserPolicy;

/**
 * One curriculum gate for the menu, voter, and write services.
 *
 * Active verified superadmin and admin keep the existing catalog scope.
 * A moderator needs the matching direct grant. Content, question, and test
 * grants do not count. The fresh user row and grant table are read on write.
 */
final class CurriculumActorPolicy
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly UserModulePermissionRepository $grants,
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
    ) {
    }

    public function allows(User $user, CurriculumOperation $operation): bool
    {
        $snapshot = $this->authLookup->getUserSnapshot($user->getId());

        return $snapshot instanceof UserAuthorizationSnapshot
            && $this->snapshotAllows($snapshot, $operation);
    }

    public function allowsFresh(User $user, CurriculumOperation $operation): bool
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($user)) {
            return false;
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($user)
            || \in_array(UserRole::Admin->value, $user->getRoles(), true)
        ) {
            return true;
        }
        if (!\in_array(UserRole::Moderator->value, $user->getRoles(), true)) {
            return false;
        }

        return \in_array($this->grantFor($operation)->value, $this->grants->codesFor($user->getId()), true);
    }

    public function snapshotAllows(UserAuthorizationSnapshot $user, CurriculumOperation $operation): bool
    {
        if (!$user->isActiveAndVerified()) {
            return false;
        }
        if ($user->isSuperAdmin() || \in_array(UserRole::Admin->value, $user->roles, true)) {
            return true;
        }
        if (!\in_array(UserRole::Moderator->value, $user->roles, true)) {
            return false;
        }

        return \in_array($this->grantFor($operation)->value, $user->moduleGrants, true);
    }

    private function grantFor(CurriculumOperation $operation): ModeratorModuleGrant
    {
        return match ($operation) {
            CurriculumOperation::View => ModeratorModuleGrant::CurriculumView,
            CurriculumOperation::Manage => ModeratorModuleGrant::CurriculumManage,
            CurriculumOperation::Publish => ModeratorModuleGrant::CurriculumPublish,
            CurriculumOperation::Availability => ModeratorModuleGrant::CurriculumAvailability,
            CurriculumOperation::Archive => ModeratorModuleGrant::CurriculumArchive,
        };
    }
}
