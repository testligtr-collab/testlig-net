<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\LearningContent;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\UserRole;
use App\Security\Authorization\LearningContentAuthorizationSnapshot;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Platform / institution learning content authorization via DBAL snapshots.
 *
 * Matrix (active+verified required):
 * - SUPER_ADMIN: all
 * - Platform: HEAD/EXPERT create/review/publish; TEACHER own draft manage (no publish);
 *   ADMIN/MODERATOR no auto publish from global role alone
 * - Institution: Owner/Manager manage+publish (review separation in manager);
 *   Teacher own draft; Staff/Student deny; global roles alone grant no tenant access
 *
 * @extends Voter<string, LearningContent|null>
 */
final class LearningContentVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
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
            return $this->mayCreateWithoutSubject($user->roles);
        }

        if (!$subject instanceof LearningContent) {
            return false;
        }

        $content = $this->authLookup->getLearningContentSnapshot($subject->getId());
        if (!$content instanceof LearningContentAuthorizationSnapshot) {
            return false;
        }

        if (LearningContentScope::Platform === $content->scope) {
            return $this->platformAllows($attribute, $user->roles, $user->id->equals($content->createdById), $content);
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

    /**
     * @param list<string> $roles
     */
    private function mayCreateWithoutSubject(array $roles): bool
    {
        return \in_array(UserRole::HeadTeacher->value, $roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $roles, true)
            || \in_array(UserRole::Teacher->value, $roles, true);
    }

    /**
     * @param list<string> $roles
     */
    private function platformAllows(
        string $attribute,
        array $roles,
        bool $isAuthor,
        LearningContentAuthorizationSnapshot $content,
    ): bool {
        $isHeadOrExpert = \in_array(UserRole::HeadTeacher->value, $roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $roles, true);
        $isTeacher = \in_array(UserRole::Teacher->value, $roles, true);

        return match ($attribute) {
            LearningContentPermission::VIEW_METADATA => $content->isPublished()
                || $isHeadOrExpert
                || ($isTeacher && $isAuthor),
            LearningContentPermission::CREATE => $isHeadOrExpert || $isTeacher,
            LearningContentPermission::MANAGE,
            LearningContentPermission::ATTACH_ASSET,
            LearningContentPermission::MANAGE_ALIGNMENT => $isHeadOrExpert
                || ($isTeacher && $isAuthor && \in_array($content->status, [
                    LearningContentStatus::Draft,
                    LearningContentStatus::InReview,
                ], true)),
            LearningContentPermission::SUBMIT_REVIEW => $isHeadOrExpert
                || ($isTeacher && $isAuthor && LearningContentStatus::Draft === $content->status),
            LearningContentPermission::RETURN_DRAFT,
            LearningContentPermission::PUBLISH,
            LearningContentPermission::ARCHIVE => $isHeadOrExpert,
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
