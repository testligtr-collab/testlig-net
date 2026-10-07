<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\CatalogTopicAssessment;
use App\Entity\User;
use App\Enum\CatalogPublicationStatus;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Platform catalog assessment placement authorization.
 *
 * Matrix (active+verified):
 * - SUPER_ADMIN: all
 * - ADMIN: create/manage/view/publish/archive
 * - MODERATOR: view only (review of Assessment is separate voter)
 * - HEAD/EXPERT: create/manage/view/publish/archive
 * - TEACHER: create/manage own draft placements; no publish/archive
 * - STUDENT: no placement management
 *
 * @extends Voter<string, CatalogTopicAssessment|null>
 */
final class CatalogTopicAssessmentVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, CatalogTopicAssessmentPermission::all(), true)) {
            return false;
        }

        if (CatalogTopicAssessmentPermission::CREATE === $attribute) {
            return null === $subject || $subject instanceof CatalogTopicAssessment;
        }

        return $subject instanceof CatalogTopicAssessment;
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

        $roles = $user->roles;
        $isAdmin = \in_array(UserRole::Admin->value, $roles, true);
        $isModerator = \in_array(UserRole::Moderator->value, $roles, true);
        $isHeadOrExpert = \in_array(UserRole::HeadTeacher->value, $roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $roles, true);
        $isTeacher = \in_array(UserRole::Teacher->value, $roles, true);

        if (CatalogTopicAssessmentPermission::CREATE === $attribute && null === $subject) {
            return $isAdmin || $isHeadOrExpert || $isTeacher;
        }

        if (!$subject instanceof CatalogTopicAssessment) {
            return false;
        }

        $isAuthor = null !== $subject->getCreatedBy()
            && $user->id->equals($subject->getCreatedBy()->getId());
        $isDraft = CatalogPublicationStatus::Draft === $subject->getVisibilityStatus();

        return match ($attribute) {
            CatalogTopicAssessmentPermission::VIEW => $isAdmin || $isModerator || $isHeadOrExpert
                || ($isTeacher && ($isAuthor || $subject->isPublished())),
            CatalogTopicAssessmentPermission::CREATE,
            CatalogTopicAssessmentPermission::MANAGE => $isAdmin || $isHeadOrExpert
                || ($isTeacher && $isAuthor && $isDraft),
            CatalogTopicAssessmentPermission::PUBLISH,
            CatalogTopicAssessmentPermission::ARCHIVE => $isAdmin || $isHeadOrExpert,
            default => false,
        };
    }
}
