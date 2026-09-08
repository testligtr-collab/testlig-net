<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Service\ActiveVerifiedUserPolicy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decisions always target a specific Institution. Global ROLE_* alone never grants access.
 *
 * Token users are reloaded once per request (no write lock). Active + verified is required
 * before any SUPER_ADMIN override or membership grant.
 *
 * @extends Voter<string, Institution>
 */
final class InstitutionVoter extends Voter
{
    public function __construct(
        private readonly InstitutionMembershipRepository $memberships,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly RequestScopedUserLookup $userLookup,
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

        $user = $this->userLookup->findCurrent($tokenUser);
        if (!$user instanceof User) {
            return false;
        }

        // Account gate runs before SUPER_ADMIN override.
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($user)) {
            return false;
        }

        if ($this->activeVerifiedUserPolicy->isSuperAdmin($user)) {
            return true;
        }

        // ADMIN / MODERATOR / any global role does not imply institution access.
        if (InstitutionStatus::Active !== $subject->getStatus()) {
            return false;
        }

        $membership = $this->memberships->findActiveMembership($user, $subject);
        if (!$membership instanceof InstitutionMembership) {
            return false;
        }

        if (InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            return false;
        }

        return match ($attribute) {
            InstitutionPermission::VIEW => true,
            InstitutionPermission::MEMBERS_VIEW => $this->canViewMembers($membership->getRole()),
            InstitutionPermission::MANAGE => InstitutionMembershipRole::Owner === $membership->getRole()
                || InstitutionMembershipRole::Manager === $membership->getRole(),
            InstitutionPermission::MEMBERS_MANAGE => InstitutionMembershipRole::Owner === $membership->getRole()
                || InstitutionMembershipRole::Manager === $membership->getRole(),
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
