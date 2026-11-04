<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\HomeworkAssignment;
use App\Entity\User;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Homework\HomeworkStudentQuery;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Fail-closed student homework access. Admin/SA/teacher/parent roles never grant.
 *
 * @extends Voter<string, HomeworkAssignment>
 */
final class HomeworkSubmissionVoter extends Voter
{
    public function __construct(
        private readonly HomeworkStudentQuery $query,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, [
            HomeworkSubmissionPermission::VIEW,
            HomeworkSubmissionPermission::SAVE_DRAFT,
            HomeworkSubmissionPermission::SUBMIT,
        ], true)) {
            return false;
        }

        return $subject instanceof HomeworkAssignment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$this->activeUsers->isActiveAndVerified($user)) {
            return false;
        }
        if (!$subject instanceof HomeworkAssignment) {
            return false;
        }

        $detail = $this->query->detailFor($user, $subject->getId());
        if (null === $detail) {
            return false;
        }

        return match ($attribute) {
            HomeworkSubmissionPermission::VIEW => true,
            HomeworkSubmissionPermission::SAVE_DRAFT,
            HomeworkSubmissionPermission::SUBMIT => $detail->writable,
            default => false,
        };
    }
}
