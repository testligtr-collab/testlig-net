<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ClassroomCourse;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Security\Authorization\CourseTeacherAssignmentAuthorizationSnapshot;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use App\Security\Authorization\StudentEnrollmentAuthorizationSnapshot;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Decisions always target a specific ClassroomCourse.
 *
 * Matrix:
 * - Owner/Manager: all CLASSROOM_COURSE_*
 * - Teacher with active course assignment: VIEW, TEACHERS_VIEW, CURRICULUM_VIEW
 * - Staff: VIEW, CURRICULUM_VIEW
 * - Student with active classroom enrollment: VIEW, CURRICULUM_VIEW
 * - SUPER_ADMIN (active+verified): all
 *
 * @extends Voter<string, ClassroomCourse>
 */
final class ClassroomCourseVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ClassroomCourse && \in_array($attribute, ClassroomCoursePermission::all(), true);
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

        $course = $this->authLookup->getClassroomCourseSnapshot($subject->getId());
        if (null === $course) {
            return false;
        }

        $institution = $this->authLookup->getInstitutionSnapshot($course->institutionId);
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
            InstitutionMembershipRole::Teacher => $this->teacherAllows($attribute, $user->id, $course->id),
            InstitutionMembershipRole::Staff => \in_array($attribute, [
                ClassroomCoursePermission::VIEW,
                ClassroomCoursePermission::CURRICULUM_VIEW,
            ], true),
            InstitutionMembershipRole::Student => $this->studentAllows($attribute, $user->id, $course->classroomId),
        };
    }

    private function teacherAllows(string $attribute, Uuid $userId, Uuid $courseId): bool
    {
        $assignment = $this->authLookup->getCourseTeacherAssignmentSnapshot($userId, $courseId);
        if (!$assignment instanceof CourseTeacherAssignmentAuthorizationSnapshot || !$assignment->isActive()) {
            return false;
        }

        return match ($attribute) {
            ClassroomCoursePermission::VIEW,
            ClassroomCoursePermission::TEACHERS_VIEW,
            ClassroomCoursePermission::CURRICULUM_VIEW => true,
            default => false,
        };
    }

    private function studentAllows(string $attribute, Uuid $userId, Uuid $classroomId): bool
    {
        if (!\in_array($attribute, [
            ClassroomCoursePermission::VIEW,
            ClassroomCoursePermission::CURRICULUM_VIEW,
        ], true)) {
            return false;
        }

        $enrollment = $this->authLookup->getStudentEnrollmentSnapshot($userId, $classroomId);

        return $enrollment instanceof StudentEnrollmentAuthorizationSnapshot && $enrollment->isActive();
    }
}
