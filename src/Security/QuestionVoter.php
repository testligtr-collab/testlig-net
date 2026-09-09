<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Question;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Enum\UserRole;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use App\Security\Authorization\QuestionAuthorizationSnapshot;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Platform / institution question authorization via DBAL snapshots.
 *
 * Matrix (active+verified required):
 * - SUPER_ADMIN: all
 * - Platform: HEAD/EXPERT_TEACHER manage/review/publish/answer; TEACHER manage own drafts; VIEW published for active users
 * - Institution: Owner/Manager all; Teacher manage own + VIEW published; Staff/Student deny (except no auto VIEW)
 * - ADMIN/MODERATOR: no publish rights from global role alone
 *
 * @extends Voter<string, Question>
 */
final class QuestionVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Question && \in_array($attribute, QuestionPermission::all(), true);
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

        $question = $this->authLookup->getQuestionSnapshot($subject->getId());
        if (!$question instanceof QuestionAuthorizationSnapshot) {
            return false;
        }

        if (QuestionScope::Platform === $question->scope) {
            return $this->platformAllows($attribute, $user->roles, $user->id->equals($question->createdById), $question);
        }

        if (null === $question->institutionId) {
            return false;
        }
        $institution = $this->authLookup->getInstitutionSnapshot($question->institutionId);
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
            $user->id->equals($question->createdById),
            $question,
        );
    }

    /**
     * @param list<string> $roles
     */
    private function platformAllows(
        string $attribute,
        array $roles,
        bool $isAuthor,
        QuestionAuthorizationSnapshot $question,
    ): bool {
        $isHeadOrExpert = \in_array(UserRole::HeadTeacher->value, $roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $roles, true);
        $isTeacher = \in_array(UserRole::Teacher->value, $roles, true);

        return match ($attribute) {
            QuestionPermission::VIEW => $question->isPublished()
                || $isHeadOrExpert
                || ($isTeacher && $isAuthor),
            QuestionPermission::MANAGE => $isHeadOrExpert
                || ($isTeacher && $isAuthor && \in_array($question->status, [QuestionStatus::Draft, QuestionStatus::InReview], true)),
            QuestionPermission::REVIEW,
            QuestionPermission::PUBLISH,
            QuestionPermission::ANSWER_KEY_VIEW => $isHeadOrExpert,
            default => false,
        };
    }

    private function institutionAllows(
        string $attribute,
        InstitutionMembershipRole $role,
        bool $isAuthor,
        QuestionAuthorizationSnapshot $question,
    ): bool {
        return match ($role) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => true,
            InstitutionMembershipRole::Teacher => match ($attribute) {
                QuestionPermission::VIEW => $question->isPublished() || $isAuthor,
                QuestionPermission::MANAGE => $isAuthor,
                QuestionPermission::ANSWER_KEY_VIEW => $isAuthor,
                default => false,
            },
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => false,
        };
    }
}
