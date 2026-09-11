<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\ClassroomCourseStatus;
use App\Enum\ClassroomStatus;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserStatus;
use App\Exception\AssessmentAnalyticsException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Uid\Uuid;

/**
 * Fresh authorization for assessment analytics projections.
 *
 * Never trusts caller-provided User status/roles/verification from the identity map.
 * Teacher coverage SQL mirrors AssessmentResultAccessGate.
 */
final class AssessmentAnalyticsAccessGate
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly Connection $connection,
    ) {
    }

    public function assertCanViewDeliveryAnalytics(User $actor, Uuid $deliveryId): void
    {
        $freshActor = $this->requireFreshActiveVerifiedActor($actor->getId());
        $scope = $this->fetchDeliveryScope($deliveryId);
        if (null === $scope) {
            throw AssessmentAnalyticsException::notFound();
        }

        if ($this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($freshActor)) {
            return;
        }

        $institution = $this->requireActiveInstitution($scope['institution_id']);
        $membership = $this->requireActiveMembership($freshActor->getId(), $institution->getId());

        match ($membership->getRole()) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => null,
            InstitutionMembershipRole::Teacher => $this->assertTeacherCoversDelivery(
                $freshActor->getId(),
                $scope,
            ),
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => throw AssessmentAnalyticsException::unauthorized(),
        };
    }

    public function assertCanViewClassroomAnalytics(User $actor, Uuid $classroomId, Uuid $institutionId): void
    {
        $freshActor = $this->requireFreshActiveVerifiedActor($actor->getId());

        if ($this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($freshActor)) {
            return;
        }

        $institution = $this->requireActiveInstitution($institutionId);
        $membership = $this->requireActiveMembership($freshActor->getId(), $institution->getId());

        match ($membership->getRole()) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => null,
            InstitutionMembershipRole::Teacher => $this->assertTeacherAssignedToClassroom(
                $freshActor->getId(),
                $classroomId,
                $institution->getId(),
            ),
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => throw AssessmentAnalyticsException::unauthorized(),
        };
    }

    /**
     * Student analytics DTO: own attempt for students; Owner/Manager/covering Teacher.
     * SUPER_ADMIN has no automatic personal-student override (same as Stage 2.13 review DTO).
     */
    public function assertCanViewStudentAnalytics(User $actor, Uuid $studentUserId, Uuid $attemptId): void
    {
        $freshActor = $this->requireFreshActiveVerifiedActor($actor->getId());

        $scope = $this->fetchAttemptScope($attemptId);
        if (null === $scope) {
            throw AssessmentAnalyticsException::notFound();
        }
        if (!$scope['user_id']->equals($studentUserId)) {
            throw AssessmentAnalyticsException::scopeMismatch();
        }

        $institution = $this->requireActiveInstitution($scope['institution_id']);
        $membership = $this->requireActiveMembership($freshActor->getId(), $institution->getId());

        if ($freshActor->getId()->equals($studentUserId)
            && InstitutionMembershipRole::Student === $membership->getRole()
        ) {
            return;
        }

        match ($membership->getRole()) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => null,
            InstitutionMembershipRole::Teacher => $this->assertTeacherCoversStudent(
                $freshActor->getId(),
                $scope['institution_id'],
                $studentUserId,
            ),
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => throw AssessmentAnalyticsException::unauthorized(),
        };
    }

    private function requireFreshActiveVerifiedActor(Uuid $actorId): User
    {
        $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
        $freshActor = $users[$actorId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User
            || !$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
        ) {
            throw AssessmentAnalyticsException::unauthorized();
        }

        return $freshActor;
    }

    private function requireActiveInstitution(Uuid $institutionId): Institution
    {
        $institution = $this->freshEntities->findFreshLockedInstitution(
            $institutionId,
            LockMode::PESSIMISTIC_READ,
        );
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentAnalyticsException::unauthorized();
        }

        return $institution;
    }

    private function requireActiveMembership(Uuid $userId, Uuid $institutionId): InstitutionMembership
    {
        $membership = $this->freshEntities->findFreshMembershipForUser(
            $userId,
            $institutionId,
            LockMode::PESSIMISTIC_READ,
        );
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw AssessmentAnalyticsException::unauthorized();
        }

        return $membership;
    }

    /**
     * @param array{
     *     institution_id: Uuid,
     *     audience_type: string,
     *     classroom_id: ?Uuid,
     *     student_user_id: ?Uuid
     * } $scope
     */
    private function assertTeacherCoversDelivery(Uuid $teacherUserId, array $scope): void
    {
        $audience = AssessmentDeliveryAudienceType::tryFrom($scope['audience_type']);
        if (AssessmentDeliveryAudienceType::Institution === $audience) {
            throw AssessmentAnalyticsException::unauthorized();
        }
        if (AssessmentDeliveryAudienceType::Classroom === $audience) {
            if (null === $scope['classroom_id']) {
                throw AssessmentAnalyticsException::unauthorized();
            }
            $this->assertTeacherAssignedToClassroom(
                $teacherUserId,
                $scope['classroom_id'],
                $scope['institution_id'],
            );

            return;
        }

        if (AssessmentDeliveryAudienceType::Student === $audience) {
            if (null === $scope['student_user_id']
                || !$this->teacherCoversStudent(
                    $teacherUserId,
                    $scope['institution_id'],
                    $scope['student_user_id'],
                )
            ) {
                throw AssessmentAnalyticsException::unauthorized();
            }

            return;
        }

        throw AssessmentAnalyticsException::unauthorized();
    }

    private function assertTeacherAssignedToClassroom(
        Uuid $userId,
        Uuid $classroomId,
        Uuid $institutionId,
    ): void {
        if (!$this->teacherAssignedToClassroom($userId, $classroomId, $institutionId)) {
            throw AssessmentAnalyticsException::unauthorized();
        }
    }

    private function assertTeacherCoversStudent(
        Uuid $teacherUserId,
        Uuid $institutionId,
        Uuid $studentUserId,
    ): void {
        if (!$this->teacherCoversStudent($teacherUserId, $institutionId, $studentUserId)) {
            throw AssessmentAnalyticsException::unauthorized();
        }
    }

    /**
     * @return array{
     *     institution_id: Uuid,
     *     audience_type: string,
     *     classroom_id: ?Uuid,
     *     assessment_id: Uuid,
     *     student_user_id: ?Uuid
     * }|null
     */
    public function fetchDeliveryScope(Uuid $deliveryId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT d.institution_id, d.audience_type, d.classroom_id, d.assessment_id, sm.user_id AS student_user_id
             FROM assessment_deliveries d
             LEFT JOIN institution_memberships sm ON sm.id = d.student_membership_id
             WHERE d.id = :id
             LIMIT 1',
            ['id' => $deliveryId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        return [
            'institution_id' => Uuid::fromBinary((string) $row['institution_id']),
            'audience_type' => (string) $row['audience_type'],
            'classroom_id' => null !== $row['classroom_id']
                ? Uuid::fromBinary((string) $row['classroom_id'])
                : null,
            'assessment_id' => Uuid::fromBinary((string) $row['assessment_id']),
            'student_user_id' => null !== $row['student_user_id']
                ? Uuid::fromBinary((string) $row['student_user_id'])
                : null,
        ];
    }

    /**
     * @return array{
     *     institution_id: Uuid,
     *     user_id: Uuid,
     *     audience_type: string,
     *     classroom_id: ?Uuid,
     *     delivery_id: Uuid,
     *     assessment_id: Uuid,
     *     student_membership_id: Uuid
     * }|null
     */
    public function fetchAttemptScope(Uuid $attemptId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.institution_id, a.user_id, a.delivery_id, a.assessment_id, a.student_membership_id,
                    d.audience_type, d.classroom_id
             FROM assessment_attempts a
             INNER JOIN assessment_deliveries d ON d.id = a.delivery_id
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
            'audience_type' => (string) $row['audience_type'],
            'classroom_id' => null !== $row['classroom_id']
                ? Uuid::fromBinary((string) $row['classroom_id'])
                : null,
            'delivery_id' => Uuid::fromBinary((string) $row['delivery_id']),
            'assessment_id' => Uuid::fromBinary((string) $row['assessment_id']),
            'student_membership_id' => Uuid::fromBinary((string) $row['student_membership_id']),
        ];
    }

    private function teacherAssignedToClassroom(
        Uuid $userId,
        Uuid $classroomId,
        Uuid $institutionId,
    ): bool {
        $homeroom = $this->connection->fetchOne(
            'SELECT 1
             FROM classroom_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             INNER JOIN classrooms c ON c.id = a.classroom_id
             INNER JOIN users u ON u.id = m.user_id
             WHERE a.classroom_id = :classroomId
               AND m.user_id = :userId
               AND m.institution_id = :institutionId
               AND m.status = :membershipStatus
               AND m.role = :teacherRole
               AND a.status = :assignmentStatus
               AND c.status = :classroomActive
               AND u.status = :userActive
               AND u.email_verified_at IS NOT NULL
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'institutionId' => $institutionId->toBinary(),
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'teacherRole' => InstitutionMembershipRole::Teacher->value,
                'assignmentStatus' => TeacherAssignmentStatus::Active->value,
                'classroomActive' => ClassroomStatus::Active->value,
                'userActive' => UserStatus::Active->value,
            ],
        );
        if (false !== $homeroom) {
            return true;
        }

        $course = $this->connection->fetchOne(
            'SELECT 1
             FROM course_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             INNER JOIN classroom_courses cc ON cc.id = a.classroom_course_id
             INNER JOIN classrooms c ON c.id = cc.classroom_id
             INNER JOIN users u ON u.id = m.user_id
             WHERE cc.classroom_id = :classroomId
               AND m.user_id = :userId
               AND m.institution_id = :institutionId
               AND m.status = :membershipStatus
               AND m.role = :teacherRole
               AND a.status = :assignmentStatus
               AND cc.status = :courseActive
               AND c.status = :classroomActive
               AND u.status = :userActive
               AND u.email_verified_at IS NOT NULL
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'institutionId' => $institutionId->toBinary(),
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'teacherRole' => InstitutionMembershipRole::Teacher->value,
                'assignmentStatus' => CourseTeacherAssignmentStatus::Active->value,
                'courseActive' => ClassroomCourseStatus::Active->value,
                'classroomActive' => ClassroomStatus::Active->value,
                'userActive' => UserStatus::Active->value,
            ],
        );

        return false !== $course;
    }

    private function teacherCoversStudent(Uuid $teacherUserId, Uuid $institutionId, Uuid $studentUserId): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT 1
             FROM classroom_student_enrollments e
             INNER JOIN institution_memberships sm ON sm.id = e.student_membership_id
             INNER JOIN classrooms c ON c.id = e.classroom_id
             WHERE e.institution_id = :institutionId
               AND e.status = :enrollmentStatus
               AND sm.user_id = :studentUserId
               AND sm.institution_id = :institutionId
               AND sm.status = :membershipStatus
               AND sm.role = :studentRole
               AND c.status = :classroomActive
               AND (
                    EXISTS (
                        SELECT 1 FROM classroom_teacher_assignments ta
                        INNER JOIN institution_memberships tm ON tm.id = ta.teacher_membership_id
                        INNER JOIN users tu ON tu.id = tm.user_id
                        WHERE ta.classroom_id = e.classroom_id
                          AND tm.user_id = :teacherUserId
                          AND tm.institution_id = :institutionId
                          AND tm.status = :membershipStatus
                          AND tm.role = :teacherRole
                          AND ta.status = :teacherStatus
                          AND tu.status = :userActive
                          AND tu.email_verified_at IS NOT NULL
                    )
                    OR EXISTS (
                        SELECT 1 FROM course_teacher_assignments cta
                        INNER JOIN institution_memberships ctm ON ctm.id = cta.teacher_membership_id
                        INNER JOIN classroom_courses cc ON cc.id = cta.classroom_course_id
                        INNER JOIN users ctu ON ctu.id = ctm.user_id
                        WHERE cc.classroom_id = e.classroom_id
                          AND ctm.user_id = :teacherUserId
                          AND ctm.institution_id = :institutionId
                          AND ctm.status = :membershipStatus
                          AND ctm.role = :teacherRole
                          AND cta.status = :courseTeacherStatus
                          AND cc.status = :courseActive
                          AND ctu.status = :userActive
                          AND ctu.email_verified_at IS NOT NULL
                    )
               )
             LIMIT 1',
            [
                'institutionId' => $institutionId->toBinary(),
                'enrollmentStatus' => StudentEnrollmentStatus::Active->value,
                'studentUserId' => $studentUserId->toBinary(),
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'studentRole' => InstitutionMembershipRole::Student->value,
                'teacherUserId' => $teacherUserId->toBinary(),
                'teacherRole' => InstitutionMembershipRole::Teacher->value,
                'teacherStatus' => TeacherAssignmentStatus::Active->value,
                'courseTeacherStatus' => CourseTeacherAssignmentStatus::Active->value,
                'classroomActive' => ClassroomStatus::Active->value,
                'courseActive' => ClassroomCourseStatus::Active->value,
                'userActive' => UserStatus::Active->value,
            ],
        );

        return false !== $row;
    }
}
