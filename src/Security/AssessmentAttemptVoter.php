<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AssessmentAttempt;
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
 * Assessment attempt authorization via DBAL snapshots (no decrypted answer access).
 *
 * Matrix (active+verified required before SUPER_ADMIN override):
 * - SUPER_ADMIN: all
 * - Owner/Manager: VIEW + CANCEL
 * - Teacher: VIEW only for classroom/student deliveries they cover (never CANCEL, never answers)
 * - Staff: deny
 * - Student: START/VIEW/SAVE_ANSWER/SUBMIT only for own attempt / eligible recipient delivery
 *
 * @extends Voter<string, AssessmentAttempt|AssessmentDelivery|null>
 */
final class AssessmentAttemptVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
        private readonly Connection $connection,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, AssessmentAttemptPermission::all(), true)) {
            return false;
        }

        if (AssessmentAttemptPermission::START === $attribute) {
            return $subject instanceof AssessmentDelivery;
        }

        return $subject instanceof AssessmentAttempt;
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

        if (AssessmentAttemptPermission::START === $attribute) {
            if (!$subject instanceof AssessmentDelivery) {
                return false;
            }

            return $this->studentMayStart($user->id, $subject->getId());
        }

        if (!$subject instanceof AssessmentAttempt) {
            return false;
        }

        $attempt = $this->fetchAttemptScope($subject->getId());
        if (null === $attempt) {
            return false;
        }

        $institution = $this->authLookup->getInstitutionSnapshot($attempt['institution_id']);
        if (null === $institution || !$institution->isActive()) {
            return false;
        }

        $membership = $this->authLookup->getMembershipSnapshot($user->id, $institution->id);
        if (!$membership instanceof MembershipAuthorizationSnapshot || !$membership->isActive()) {
            return false;
        }

        if ($user->id->equals($attempt['user_id'])
            && InstitutionMembershipRole::Student === $membership->role
        ) {
            return \in_array($attribute, AssessmentAttemptPermission::studentSelfAttributes(), true);
        }

        return match ($membership->role) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => \in_array($attribute, [
                AssessmentAttemptPermission::VIEW,
                AssessmentAttemptPermission::CANCEL,
            ], true),
            InstitutionMembershipRole::Teacher => AssessmentAttemptPermission::VIEW === $attribute
                && $this->teacherMayView($user->id, $attempt),
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => false,
        };
    }

    private function studentMayStart(Uuid $userId, Uuid $deliveryId): bool
    {
        $delivery = $this->authLookup->getAssessmentDeliverySnapshot($deliveryId);
        if (!$delivery instanceof AssessmentDeliveryAuthorizationSnapshot) {
            return false;
        }
        $institution = $this->authLookup->getInstitutionSnapshot($delivery->institutionId);
        if (null === $institution || !$institution->isActive()) {
            return false;
        }
        $membership = $this->authLookup->getMembershipSnapshot($userId, $institution->id);
        if (!$membership instanceof MembershipAuthorizationSnapshot
            || !$membership->isActive()
            || InstitutionMembershipRole::Student !== $membership->role
        ) {
            return false;
        }

        return $this->isEligibleRecipient($deliveryId, $userId);
    }

    /**
     * @param array{
     *     institution_id: Uuid,
     *     user_id: Uuid,
     *     delivery_id: Uuid,
     *     audience_type: string,
     *     classroom_id: ?Uuid,
     *     student_user_id: ?Uuid
     * } $attempt
     */
    private function teacherMayView(Uuid $teacherUserId, array $attempt): bool
    {
        $audience = AssessmentDeliveryAudienceType::tryFrom($attempt['audience_type']);
        if (AssessmentDeliveryAudienceType::Institution === $audience) {
            return false;
        }
        if (AssessmentDeliveryAudienceType::Classroom === $audience) {
            return null !== $attempt['classroom_id']
                && $this->teacherAssignedToClassroom($teacherUserId, $attempt['classroom_id']);
        }
        if (null === $attempt['student_user_id']) {
            return false;
        }

        return $this->teacherCoversStudent(
            $teacherUserId,
            $attempt['institution_id'],
            $attempt['student_user_id'],
        );
    }

    private function isEligibleRecipient(Uuid $deliveryId, Uuid $userId): bool
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM assessment_delivery_recipients
             WHERE delivery_id = :deliveryId AND user_id = :userId
             LIMIT 1',
            [
                'deliveryId' => $deliveryId->toBinary(),
                'userId' => $userId->toBinary(),
            ],
        );

        return \is_string($status) && AssessmentDeliveryRecipientStatus::Eligible->value === $status;
    }

    /**
     * @return array{
     *     institution_id: Uuid,
     *     user_id: Uuid,
     *     delivery_id: Uuid,
     *     audience_type: string,
     *     classroom_id: ?Uuid,
     *     student_user_id: ?Uuid
     * }|null
     */
    private function fetchAttemptScope(Uuid $attemptId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.institution_id, a.user_id, a.delivery_id, d.audience_type, d.classroom_id,
                    sm.user_id AS student_audience_user_id
             FROM assessment_attempts a
             INNER JOIN assessment_deliveries d ON d.id = a.delivery_id
             LEFT JOIN institution_memberships sm ON sm.id = d.student_membership_id
             WHERE a.id = :id
             LIMIT 1',
            ['id' => $attemptId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        return [
            'institution_id' => Uuid::fromBinary((string) $row['institution_id']),
            'user_id' => Uuid::fromBinary((string) $row['user_id']),
            'delivery_id' => Uuid::fromBinary((string) $row['delivery_id']),
            'audience_type' => (string) $row['audience_type'],
            'classroom_id' => null !== $row['classroom_id']
                ? Uuid::fromBinary((string) $row['classroom_id'])
                : null,
            'student_user_id' => null !== $row['student_audience_user_id']
                ? Uuid::fromBinary((string) $row['student_audience_user_id'])
                : null,
        ];
    }

    private function teacherAssignedToClassroom(Uuid $userId, Uuid $classroomId): bool
    {
        if (null !== $this->authLookup->getTeacherAssignmentSnapshot($userId, $classroomId)) {
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
                'enrollmentStatus' => 'active',
                'studentUserId' => $studentUserId->toBinary(),
                'teacherUserId' => $teacherUserId->toBinary(),
                'teacherStatus' => TeacherAssignmentStatus::Active->value,
                'courseTeacherStatus' => CourseTeacherAssignmentStatus::Active->value,
            ],
        );

        return false !== $row;
    }
}
