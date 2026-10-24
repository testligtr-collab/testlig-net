<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\LearningContent;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\ModeratorModuleGrant;
use App\Enum\UserRole;
use App\Security\Authorization\LearningContentAuthorizationSnapshot;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use App\Security\Authorization\UserAuthorizationSnapshot;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Platform / institution learning content authorization via DBAL snapshots.
 *
 * Matrix (active+verified required):
 * - SUPER_ADMIN: all
 * - Platform ADMIN: create/manage/review/publish/archive
 * - Platform MODERATOR: view + return draft (no publish)
 * - Platform HEAD/EXPERT: create/review/publish
 * - Platform TEACHER: own draft manage + submit (no publish)
 * - Institution: Owner/Manager manage+publish; Teacher own draft; Staff/Student deny
 *
 * @extends Voter<string, LearningContent|null>
 */
final class LearningContentVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
        private readonly UserModulePermissionPolicy $modulePermissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, LearningContentPermission::all(), true)) {
            return false;
        }

        if (LearningContentPermission::CREATE === $attribute) {
            return null === $subject || $subject instanceof LearningContent;
        }

        return $subject instanceof LearningContent;
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

        if (LearningContentPermission::CREATE === $attribute && null === $subject) {
            return $this->mayCreateWithoutSubject($user);
        }

        if (!$subject instanceof LearningContent) {
            return false;
        }

        $content = $this->authLookup->getLearningContentSnapshot($subject->getId());
        if (!$content instanceof LearningContentAuthorizationSnapshot) {
            return false;
        }

        if (LearningContentScope::Platform === $content->scope) {
            return $this->platformAllows($attribute, $user, $user->id->equals($content->createdById), $content);
        }

        if (null === $content->institutionId) {
            return false;
        }
        $institution = $this->authLookup->getInstitutionSnapshot($content->institutionId);
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
            $user->id->equals($content->createdById),
            $content,
        );
    }

    private function mayCreateWithoutSubject(UserAuthorizationSnapshot $user): bool
    {
        return \in_array(UserRole::HeadTeacher->value, $user->roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $user->roles, true)
            || \in_array(UserRole::Teacher->value, $user->roles, true)
            || \in_array(UserRole::Admin->value, $user->roles, true)
            || $this->modulePermissions->snapshotAllows($user, ModeratorModuleGrant::LearningContentManage);
    }

    private function platformAllows(
        string $attribute,
        UserAuthorizationSnapshot $user,
        bool $isAuthor,
        LearningContentAuthorizationSnapshot $content,
    ): bool {
        $roles = $user->roles;
        $isHeadOrExpert = \in_array(UserRole::HeadTeacher->value, $roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $roles, true);
        $isTeacher = \in_array(UserRole::Teacher->value, $roles, true);
        $isAdmin = \in_array(UserRole::Admin->value, $roles, true);
        $isModerator = \in_array(UserRole::Moderator->value, $roles, true);
        $granted = $this->modulePermissions->snapshotAllows($user, ModeratorModuleGrant::LearningContentManage);

        return match ($attribute) {
            LearningContentPermission::VIEW_METADATA => $content->isPublished()
                || $isHeadOrExpert
                || $isAdmin
                || $isModerator
                || ($isTeacher && $isAuthor),
            LearningContentPermission::CREATE => $isHeadOrExpert || $isTeacher || $isAdmin || $granted,
            LearningContentPermission::MANAGE,
            LearningContentPermission::ATTACH_ASSET,
            LearningContentPermission::MANAGE_ALIGNMENT => $isHeadOrExpert
                || $isAdmin
                || $granted
                || ($isTeacher && $isAuthor && \in_array($content->status, [
                    LearningContentStatus::Draft,
                    LearningContentStatus::InReview,
                ], true)),
            LearningContentPermission::SUBMIT_REVIEW => $isHeadOrExpert
                || $isAdmin
                || $granted
                || ($isTeacher && $isAuthor && LearningContentStatus::Draft === $content->status),
            LearningContentPermission::RETURN_DRAFT => $isHeadOrExpert || $isAdmin || $isModerator,
            LearningContentPermission::PUBLISH,
            LearningContentPermission::ARCHIVE => $isHeadOrExpert || $isAdmin,
            default => false,
        };
    }

    private function institutionAllows(
        string $attribute,
        InstitutionMembershipRole $role,
        bool $isAuthor,
        LearningContentAuthorizationSnapshot $content,
    ): bool {
        return match ($role) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => true,
            InstitutionMembershipRole::Teacher => match ($attribute) {
                LearningContentPermission::VIEW_METADATA => $content->isPublished() || $isAuthor,
                LearningContentPermission::CREATE => true,
                LearningContentPermission::MANAGE,
                LearningContentPermission::ATTACH_ASSET,
                LearningContentPermission::MANAGE_ALIGNMENT,
                LearningContentPermission::SUBMIT_REVIEW => $isAuthor
                    && \in_array($content->status, [
                        LearningContentStatus::Draft,
                        LearningContentStatus::InReview,
                    ], true),
                default => false,
            },
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => false,
        };
    }
}
