<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Classroom;
use App\Entity\HomeworkDefinition;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\HomeworkDefinitionStatus;
use App\Enum\InstitutionMembershipRole;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use App\Security\Authorization\TeacherAssignmentAuthorizationSnapshot;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher draft homework authorization. Fails closed.
 *
 * - No SuperAdmin / Admin override: global roles never grant homework access.
 * - Requires an active, verified user, an active institution, an active teacher membership
 *   and a CURRENT active ClassroomTeacherAssignment on the homework classroom.
 * - Ended assignment denies, including viewing history.
 * - Another teacher (including a co-teacher on the same classroom) is denied: ownership is
 *   created_by_user_id.
 *
 * @extends Voter<string, HomeworkDefinition|Classroom>
 */
final class HomeworkDefinitionVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
        private readonly Connection $connection,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, HomeworkDefinitionPermission::all(), true)) {
            return false;
        }

        return HomeworkDefinitionPermission::CREATE === $attribute
            ? $subject instanceof Classroom
            : $subject instanceof HomeworkDefinition;
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

        if ($subject instanceof HomeworkDefinition && HomeworkDefinitionPermission::CREATE !== $attribute) {
            $owner = $subject->getCreatedByUser();
            $classroom = $subject->getClassroom();
            $membership = $subject->getCreatedByMembership();
            if (!$owner->getId()->equals($user->id)) {
                return false;
            }
            $mutating = \in_array($attribute, [HomeworkDefinitionPermission::EDIT, HomeworkDefinitionPermission::ASSIGN], true);
            if ($mutating && HomeworkDefinitionStatus::Draft !== $subject->getStatus()) {
                return false;
            }
            if ($mutating && $this->hasClassroomAssignment($subject->getId())) {
                return false;
            }

            return $this->teachesClassroom($user->id, $classroom->getId(), $mutating, $membership->getId());
        }

        if ($subject instanceof Classroom && HomeworkDefinitionPermission::CREATE === $attribute) {
            return $this->teachesClassroom($user->id, $subject->getId(), true, null);
        }

        return false;
    }

    private function teachesClassroom(Uuid $userId, Uuid $classroomId, bool $openScope, ?Uuid $membershipId): bool
    {
        $classroom = $this->authLookup->getClassroomSnapshot($classroomId);
        if (null === $classroom) {
            return false;
        }
        $institution = $this->authLookup->getInstitutionSnapshot($classroom->institutionId);
        if (null === $institution || !$institution->isActive()) {
            return false;
        }
        $membership = $this->authLookup->getMembershipSnapshot($userId, $institution->id);
        if (!$membership instanceof MembershipAuthorizationSnapshot
            || !$membership->isActive()
            || InstitutionMembershipRole::Teacher !== $membership->role
        ) {
            return false;
        }
        if ($membershipId instanceof Uuid && !$membershipId->equals($membership->id)) {
            return false;
        }
        $assignment = $this->authLookup->getTeacherAssignmentSnapshot($userId, $classroomId);
        if (!$assignment instanceof TeacherAssignmentAuthorizationSnapshot
            || !$assignment->isActive()
            || !$assignment->membershipId->equals($membership->id)
        ) {
            return false;
        }
        if (!$openScope) {
            return true;
        }

        return $classroom->isActive() && $this->yearIsActive($classroom->academicYearId);
    }

    private function yearIsActive(Uuid $academicYearId): bool
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM academic_years WHERE id = :id',
            ['id' => $academicYearId->toBinary()],
        );

        return AcademicYearStatus::Active->value === $status;
    }

    private function hasClassroomAssignment(Uuid $definitionId): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM homework_assignments WHERE definition_id = :id LIMIT 1',
            ['id' => $definitionId->toBinary()],
        );

        return false !== $found && null !== $found;
    }
}
