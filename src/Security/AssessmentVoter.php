<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Assessment;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\ModeratorModuleGrant;
use App\Enum\UserRole;
use App\Security\Authorization\AssessmentAuthorizationSnapshot;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use App\Security\Authorization\UserAuthorizationSnapshot;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Platform / institution assessment authorization via DBAL snapshots.
 *
 * Matrix (active+verified required):
 * - SUPER_ADMIN: all
 * - Platform: HEAD/EXPERT full; ADMIN create/review/publish/archive (SoD is enforced in the manager); MODERATOR view/review/return, no publish; TEACHER create/revise/submit own drafts + VIEW published
 * - Institution: Owner/Manager all; Teacher create/revise/submit own + VIEW; Staff limited VIEW; Student deny
 *
 * @extends Voter<string, Assessment|null>
 */
final class AssessmentVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
        private readonly UserModulePermissionPolicy $modulePermissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, AssessmentPermission::all(), true)) {
            return false;
        }

        if (AssessmentPermission::CREATE === $attribute) {
            return null === $subject || $subject instanceof Assessment;
        }

        return $subject instanceof Assessment;
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

        if (AssessmentPermission::CREATE === $attribute && null === $subject) {
            return $this->mayCreateWithoutSubject($user);
        }

        if (!$subject instanceof Assessment) {
            return false;
        }

        $assessment = $this->authLookup->getAssessmentSnapshot($subject->getId());
        if (!$assessment instanceof AssessmentAuthorizationSnapshot) {
            return false;
        }

        if (AssessmentScope::Platform === $assessment->scope) {
            return $this->platformAllows($attribute, $user, $user->id->equals($assessment->createdById), $assessment);
        }

        if (null === $assessment->institutionId) {
            return false;
        }
        $institution = $this->authLookup->getInstitutionSnapshot($assessment->institutionId);
        if (null === $institution || !$institution->isActive()) {
            return false;
        }
        $membership = $this->authLookup->getMembershipSnapshot($user->id, $institution->id);
        if (!$membership instanceof MembershipAuthorizationSnapshot || !$membership->isActive()) {
            return false;
        }

        return $this->institutionAllows(
            $attribute,
            $membership->role,
            $user->id->equals($assessment->createdById),
            $assessment,
        );
    }

    private function mayCreateWithoutSubject(UserAuthorizationSnapshot $user): bool
    {
        return \in_array(UserRole::HeadTeacher->value, $user->roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $user->roles, true)
            || \in_array(UserRole::Teacher->value, $user->roles, true)
            || \in_array(UserRole::Admin->value, $user->roles, true)
            || $this->modulePermissions->snapshotAllows($user, ModeratorModuleGrant::AssessmentAuthor);
    }

    private function platformAllows(
        string $attribute,
        UserAuthorizationSnapshot $user,
        bool $isAuthor,
        AssessmentAuthorizationSnapshot $assessment,
    ): bool {
        $roles = $user->roles;
        $isHeadOrExpert = \in_array(UserRole::HeadTeacher->value, $roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $roles, true);
        $isAdmin = \in_array(UserRole::Admin->value, $roles, true);
        $isModerator = \in_array(UserRole::Moderator->value, $roles, true);
        $isTeacher = \in_array(UserRole::Teacher->value, $roles, true);
        $ownDraft = $isTeacher && $isAuthor && AssessmentStatus::Draft === $assessment->status;
        $granted = $this->modulePermissions->snapshotAllows($user, ModeratorModuleGrant::AssessmentAuthor);
        $grantedDraft = $granted && AssessmentStatus::Draft === $assessment->status;

        return match ($attribute) {
            AssessmentPermission::VIEW => $assessment->isPublished()
                || $isHeadOrExpert
                || $isAdmin
                || $isModerator
                || ($isTeacher && $isAuthor),
            AssessmentPermission::CREATE => $isHeadOrExpert || $isAdmin || $isTeacher || $granted,
            AssessmentPermission::REVISE,
            AssessmentPermission::SUBMIT => $isHeadOrExpert || ($isAdmin && AssessmentStatus::Draft === $assessment->status) || $ownDraft || $grantedDraft,
            AssessmentPermission::REVIEW => $isHeadOrExpert || $isAdmin || $isModerator,
            AssessmentPermission::PUBLISH,
            AssessmentPermission::ARCHIVE => $isHeadOrExpert || $isAdmin,
            default => false,
        };
    }

    private function institutionAllows(
        string $attribute,
        InstitutionMembershipRole $role,
        bool $isAuthor,
        AssessmentAuthorizationSnapshot $assessment,
    ): bool {
        return match ($role) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => true,
            InstitutionMembershipRole::Teacher => match ($attribute) {
                AssessmentPermission::VIEW => $assessment->isPublished() || $isAuthor,
                AssessmentPermission::CREATE => true,
                AssessmentPermission::REVISE,
                AssessmentPermission::SUBMIT => $isAuthor,
                default => false,
            },
            InstitutionMembershipRole::Staff => AssessmentPermission::VIEW === $attribute && $assessment->isPublished(),
            InstitutionMembershipRole::Student => false,
        };
    }
}
