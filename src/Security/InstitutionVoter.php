<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Institution;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decisions always target a specific Institution. Global ROLE_* alone never grants access.
 *
 * Authorization reads DBAL snapshots via {@see RequestScopedInstitutionAuthLookup} — never
 * Doctrine-managed User/Institution/Membership state. Active + verified is checked before
 * SUPER_ADMIN override. No DB locks on the read path.
 *
 * @extends Voter<string, Institution>
 */
final class InstitutionVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Institution && \in_array($attribute, InstitutionPermission::all(), true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $tokenUser = $token->getUser();
        if (!$tokenUser instanceof User) {
            return false;
        }

        $user = $this->authLookup->getUserSnapshot($tokenUser->getId());
        if (null === $user || !$user->isActiveAndVerified()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $institution = $this->authLookup->getInstitutionSnapshot($subject->getId());
        if (null === $institution || !$institution->isActive()) {
            return false;
        }

        $membership = $this->authLookup->getMembershipSnapshot($user->id, $institution->id);
        if (!$membership instanceof MembershipAuthorizationSnapshot || !$membership->isActive()) {
            return false;
        }

        return match ($attribute) {
            InstitutionPermission::VIEW => true,
            InstitutionPermission::MEMBERS_VIEW => $this->canViewMembers($membership->role),
            InstitutionPermission::MANAGE,
            InstitutionPermission::MEMBERS_MANAGE => InstitutionMembershipRole::Owner === $membership->role
                || InstitutionMembershipRole::Manager === $membership->role,
            default => false,
        };
    }

    private function canViewMembers(InstitutionMembershipRole $role): bool
    {
        return match ($role) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
            InstitutionMembershipRole::Teacher => true,
            InstitutionMembershipRole::Staff => false,
        };
    }
}
