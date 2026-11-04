<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\HomeworkSubmission;
use App\Entity\User;
use App\Enum\HomeworkSubmissionStatus;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Homework\HomeworkTeacherEvaluationScope;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Fail-closed teacher evaluation access. Admin/SA/co-teacher never grant.
 * Student enrollment is not consulted.
 *
 * @extends Voter<string, HomeworkSubmission>
 */
final class HomeworkEvaluationVoter extends Voter
{
    public function __construct(
        private readonly HomeworkTeacherEvaluationScope $scope,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, HomeworkEvaluationPermission::all(), true)) {
            return false;
        }

        return $subject instanceof HomeworkSubmission;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$this->activeUsers->isActiveAndVerified($user)) {
            return false;
        }
        // $subject is HomeworkSubmission because supports() already gated the type.
        if (HomeworkSubmissionStatus::Submitted !== $subject->getStatus()) {
            return false;
        }

        // Evaluate requires open classroom/year; view submitted uses the same open scope (§4.3).
        return $this->scope->canManageSubmitted($user, $subject, openScope: true);
    }
}
