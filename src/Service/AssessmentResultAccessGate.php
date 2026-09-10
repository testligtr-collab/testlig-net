<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssessmentAttempt;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Exception\AssessmentScoringException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Uid\Uuid;

/**
 * Fresh authorization for result view, manual grading, and release management.
 *
 * Teacher coverage SQL mirrors AssessmentAttemptVoter (classroom + course assignments).
 */
final class AssessmentResultAccessGate
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly Connection $connection,
    ) {
    }

    public function assertCanViewResult(User $actor, AssessmentAttempt $attempt): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentScoringException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor)) {
            return;
        }

        $institution = $this->requireActiveInstitution($attempt->getInstitution()->getId());
        $membership = $this->requireActiveMembership($actor->getId(), $institution->getId());

        if ($actor->getId()->equals($attempt->getUser()->getId())
            && InstitutionMembershipRole::Student === $membership->getRole()
        ) {
            return;
        }

        match ($membership->getRole()) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => null,
            InstitutionMembershipRole::Teacher => $this->assertTeacherCoversAttempt($actor->getId(), $attempt),
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => throw AssessmentScoringException::unauthorized(),
        };
    }

    public function assertCanManuallyGrade(User $actor, AssessmentAttempt $attempt): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentScoringException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor)) {
            return;
        }

        $institution = $this->requireActiveInstitution($attempt->getInstitution()->getId());
        $membership = $this->requireActiveMembership($actor->getId(), $institution->getId());

        match ($membership->getRole()) {
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager => null,
            InstitutionMembershipRole::Teacher => $this->assertTeacherCoversAttempt($actor->getId(), $attempt),
            InstitutionMembershipRole::Staff,
            InstitutionMembershipRole::Student => throw AssessmentScoringException::unauthorized(),
        };
    }

    public function assertCanManageRelease(User $actor, AssessmentAttempt $attempt): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentScoringException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor)) {
            return;
        }

        $institution = $this->requireActiveInstitution($attempt->getInstitution()->getId());
        $membership = $this->requireActiveMembership($actor->getId(), $institution->getId());

        if (!\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
        ], true)) {
            throw AssessmentScoringException::unauthorized();
        }
    }

    private function requireActiveInstitution(Uuid $institutionId): Institution
    {
        $institution = $this->freshEntities->findFreshLockedInstitution(
            $institutionId,
            LockMode::PESSIMISTIC_READ,
        );
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentScoringException::unauthorized();
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
            throw AssessmentScoringException::unauthorized();
        }

        return $membership;
    }

    private function assertTeacherCoversAttempt(Uuid $teacherUserId, AssessmentAttempt $attempt): void
    {
        $scope = $this->fetchAttemptScope($attempt->getId());
        if (null === $scope) {
            throw AssessmentScoringException::unauthorized();
        }

        $audience = AssessmentDeliveryAudienceType::tryFrom($scope['audience_type']);
        if (AssessmentDeliveryAudienceType::Institution === $audience) {
            throw AssessmentScoringException::unauthorized();
        }
        if (AssessmentDeliveryAudienceType::Classroom === $audience) {
            if (null === $scope['classroom_id']
                || !$this->teacherAssignedToClassroom($teacherUserId, $scope['classroom_id'])
            ) {
                throw AssessmentScoringException::unauthorized();
            }

            return;
        }

        if (!$this->teacherCoversStudent(
            $teacherUserId,
            $scope['institution_id'],
            $scope['user_id'],
        )) {
            throw AssessmentScoringException::unauthorized();
        }
    }

    /**
     * @return array{
     *     institution_id: Uuid,
     *     user_id: Uuid,
     *     audience_type: string,
     *     classroom_id: ?Uuid
     * }|null
     */
    private function fetchAttemptScope(Uuid $attemptId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.institution_id, a.user_id, d.audience_type, d.classroom_id
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
        ];
    }

    private function teacherAssignedToClassroom(Uuid $userId, Uuid $classroomId): bool
    {
        $homeroom = $this->connection->fetchOne(
            'SELECT 1
             FROM classroom_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             WHERE a.classroom_id = :classroomId
               AND m.user_id = :userId
               AND a.status = :status
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'status' => TeacherAssignmentStatus::Active->value,
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

        return false !== $course;
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
                'enrollmentStatus' => StudentEnrollmentStatus::Active->value,
                'studentUserId' => $studentUserId->toBinary(),
                'teacherUserId' => $teacherUserId->toBinary(),
                'teacherStatus' => TeacherAssignmentStatus::Active->value,
                'courseTeacherStatus' => CourseTeacherAssignmentStatus::Active->value,
            ],
        );

        return false !== $row;
    }
}
