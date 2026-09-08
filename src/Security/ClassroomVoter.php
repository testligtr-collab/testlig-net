<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Classroom;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use App\Security\Authorization\StudentEnrollmentAuthorizationSnapshot;
use App\Security\Authorization\TeacherAssignmentAuthorizationSnapshot;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Decisions always target a specific Classroom. Global ROLE_* alone never grants access.
 *
 * Authorization reads DBAL snapshots via {@see RequestScopedInstitutionAuthLookup}.
 *
 * @extends Voter<string, Classroom>
 */
final class ClassroomVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Classroom && \in_array($attribute, ClassroomPermission::all(), true);
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

        $classroom = $this->authLookup->getClassroomSnapshot($subject->getId());
        if (null === $classroom) {
            return false;
        }

        $institution = $this->authLookup->getInstitutionSnapshot($classroom->institutionId);
        if (null === $institution || !$institution->isActive()) {
            return false;
        }

        $membership = $this->authLookup->getMembershipSnapshot($user->id, $institution->id);
        if (!$membership instanceof MembershipAuthorizationSnapshot || !$membership->isActive()) {
            return false;
        }

        return match ($membership->role) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => true,
            InstitutionMembershipRole::Teacher => $this->teacherAllows($attribute, $user->id, $classroom->id),
            InstitutionMembershipRole::Staff => ClassroomPermission::VIEW === $attribute,
            InstitutionMembershipRole::Student => $this->studentAllows($attribute, $user->id, $classroom->id),
        };
    }

    private function teacherAllows(string $attribute, Uuid $userId, Uuid $classroomId): bool
    {
        $assignment = $this->authLookup->getTeacherAssignmentSnapshot($userId, $classroomId);
        if (!$assignment instanceof TeacherAssignmentAuthorizationSnapshot || !$assignment->isActive()) {
            return false;
        }

        return match ($attribute) {
            ClassroomPermission::VIEW,
            ClassroomPermission::STUDENTS_VIEW => true,
            default => false,
        };
    }

    private function studentAllows(string $attribute, Uuid $userId, Uuid $classroomId): bool
    {
        if (ClassroomPermission::VIEW !== $attribute) {
            return false;
        }

        $enrollment = $this->authLookup->getStudentEnrollmentSnapshot($userId, $classroomId);

        return $enrollment instanceof StudentEnrollmentAuthorizationSnapshot && $enrollment->isActive();
    }
}
