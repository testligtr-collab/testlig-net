<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AssessmentDelivery;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\TeacherAssignmentStatus;
use App\Security\Authorization\AssessmentDeliveryAuthorizationSnapshot;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Assessment delivery authorization via DBAL snapshots.
 *
 * Matrix (active+verified required before SUPER_ADMIN override):
 * - SUPER_ADMIN: all
 * - Owner/Manager: all institution delivery ops
 * - Teacher: classroom audience for assigned classrooms; student audience if student enrolled
 *   in an assigned classroom; no institution-wide create/manage
 * - Staff: deny
 * - Student: ACCESS_SELF only when eligible recipient of the delivery
 *
 * @extends Voter<string, AssessmentDelivery|null>
 */
final class AssessmentDeliveryVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
        private readonly Connection $connection,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, AssessmentDeliveryPermission::all(), true)) {
            return false;
        }

        if (AssessmentDeliveryPermission::CREATE === $attribute) {
            return null === $subject || $subject instanceof AssessmentDelivery;
        }

        return $subject instanceof AssessmentDelivery;
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

        if (AssessmentDeliveryPermission::CREATE === $attribute && null === $subject) {
            // Subject-less CREATE is resolved in manager with audience context.
            return false;
        }

        if (!$subject instanceof AssessmentDelivery) {
            return false;
        }

        $delivery = $this->authLookup->getAssessmentDeliverySnapshot($subject->getId());
        if (!$delivery instanceof AssessmentDeliveryAuthorizationSnapshot) {
            return false;
        }

        $institution = $this->authLookup->getInstitutionSnapshot($delivery->institutionId);
        if (null === $institution || !$institution->isActive()) {
            return false;
        }

        $membership = $this->authLookup->getMembershipSnapshot($user->id, $institution->id);
        if (!$membership instanceof MembershipAuthorizationSnapshot || !$membership->isActive()) {
            return false;
        }

        if (AssessmentDeliveryPermission::ACCESS_SELF === $attribute) {
            return InstitutionMembershipRole::Student === $membership->role
                && $this->isEligibleRecipient($delivery->id, $user->id);
        }

        return match ($membership->role) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => true,
            InstitutionMembershipRole::Teacher => $this->teacherAllows($attribute, $user->id, $delivery),
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => false,
        };
    }

    private function teacherAllows(
        string $attribute,
        Uuid $userId,
        AssessmentDeliveryAuthorizationSnapshot $delivery,
    ): bool {
        if (AssessmentDeliveryAudienceType::Institution === $delivery->audienceType) {
            return false;
        }

        if (AssessmentDeliveryAudienceType::Classroom === $delivery->audienceType) {
            if (null === $delivery->classroomId) {
                return false;
            }
            if (!$this->teacherAssignedToClassroom($userId, $delivery->classroomId)) {
                return false;
            }

            return \in_array($attribute, AssessmentDeliveryPermission::manageAttributes(), true)
                || AssessmentDeliveryPermission::CREATE === $attribute;
        }

        // Student audience: teacher may manage only if student is enrolled in a classroom
        // the teacher is assigned to.
        if (null === $delivery->studentUserId) {
            return false;
        }
        if (!$this->teacherCoversStudent($userId, $delivery->institutionId, $delivery->studentUserId)) {
            return false;
        }

        return \in_array($attribute, AssessmentDeliveryPermission::manageAttributes(), true)
            || AssessmentDeliveryPermission::CREATE === $attribute;
    }

    private function teacherAssignedToClassroom(Uuid $userId, Uuid $classroomId): bool
    {
        $homeroom = $this->authLookup->getTeacherAssignmentSnapshot($userId, $classroomId);
        if (null !== $homeroom) {
            return true;
        }

        $row = $this->connection->fetchOne(
            'SELECT 1
             FROM course_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             INNER JOIN classroom_courses cc ON cc.id = a.classroom_course_id
             WHERE cc.classroom_id = :classroomId
               AND m.user_id = :userId
               AND a.status = :status
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'status' => CourseTeacherAssignmentStatus::Active->value,
            ],
        );

        return false !== $row;
    }

    private function teacherCoversStudent(Uuid $teacherUserId, Uuid $institutionId, Uuid $studentUserId): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT 1
             FROM classroom_student_enrollments e
             INNER JOIN institution_memberships sm ON sm.id = e.student_membership_id
             WHERE e.institution_id = :institutionId
               AND e.status = :enrollmentStatus
               AND sm.user_id = :studentUserId
               AND (
                    EXISTS (
                        SELECT 1 FROM classroom_teacher_assignments ta
                        INNER JOIN institution_memberships tm ON tm.id = ta.teacher_membership_id
                        WHERE ta.classroom_id = e.classroom_id
                          AND tm.user_id = :teacherUserId
                          AND ta.status = :teacherStatus
                    )
                    OR EXISTS (
                        SELECT 1 FROM course_teacher_assignments cta
                        INNER JOIN institution_memberships ctm ON ctm.id = cta.teacher_membership_id
                        INNER JOIN classroom_courses cc ON cc.id = cta.classroom_course_id
                        WHERE cc.classroom_id = e.classroom_id
                          AND ctm.user_id = :teacherUserId
                          AND cta.status = :courseTeacherStatus
                    )
               )
             LIMIT 1',
            [
                'institutionId' => $institutionId->toBinary(),
                'enrollmentStatus' => \App\Enum\StudentEnrollmentStatus::Active->value,
                'studentUserId' => $studentUserId->toBinary(),
                'teacherUserId' => $teacherUserId->toBinary(),
                'teacherStatus' => TeacherAssignmentStatus::Active->value,
                'courseTeacherStatus' => CourseTeacherAssignmentStatus::Active->value,
            ],
        );

        return false !== $row;
    }

    private function isEligibleRecipient(Uuid $deliveryId, Uuid $userId): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT 1
             FROM assessment_delivery_recipients
             WHERE delivery_id = :deliveryId
               AND user_id = :userId
               AND status = :status
             LIMIT 1',
            [
                'deliveryId' => $deliveryId->toBinary(),
                'userId' => $userId->toBinary(),
                'status' => AssessmentDeliveryRecipientStatus::Eligible->value,
            ],
        );

        return false !== $row;
    }
}
