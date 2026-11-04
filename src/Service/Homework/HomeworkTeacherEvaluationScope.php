<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkSubmission;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\TeacherAssignmentStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Current assigning-teacher authority for submitted homework (no student enrollment).
 */
final class HomeworkTeacherEvaluationScope
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HomeworkTeacherScope $teacherScope,
    ) {
    }

    public function canManageSubmitted(User $actor, HomeworkSubmission $submission, bool $openScope): bool
    {
        $assignment = $submission->getAssignment();
        if (!$this->ownsAssignment($actor, $assignment)) {
            return false;
        }

        return null !== $this->teacherScope->findAssignment(
            $actor,
            $assignment->getClassroom()->getId(),
            $openScope,
            false,
        );
    }

    public function ownsAssignment(User $actor, HomeworkAssignment $assignment): bool
    {
        if (!$assignment->getAssignedByUser()->getId()->equals($actor->getId())) {
            return false;
        }
        $definition = $assignment->getDefinition();

        return $definition->getCreatedByUser()->getId()->equals($actor->getId());
    }

    /**
     * Fresh teacher membership under locks for evaluate writes. Null when ended/passive.
     */
    public function lockActiveTeacherMembership(
        User $actor,
        HomeworkAssignment $assignment,
        bool $openScope,
    ): ?InstitutionMembership {
        $classroomId = $assignment->getClassroom()->getId();
        $teacherAssignment = $this->teacherScope->findAssignment($actor, $classroomId, $openScope, true);
        if (null === $teacherAssignment) {
            return null;
        }
        if (TeacherAssignmentStatus::Active !== $teacherAssignment->getStatus()) {
            return null;
        }
        $membership = $teacherAssignment->getTeacherMembership();
        if (InstitutionMembershipStatus::Active !== $membership->getStatus()
            || InstitutionMembershipRole::Teacher !== $membership->getRole()
            || !$membership->getUser()->getId()->equals($actor->getId())
        ) {
            return null;
        }
        // Current membership must still be the ownership membership rows recorded on the homework.
        // A re-added teacher with a new membership id does not regain access (§4.2 / definition voter).
        if (!$membership->getId()->equals($assignment->getAssignedByMembership()->getId())
            || !$membership->getId()->equals($assignment->getDefinition()->getCreatedByMembership()->getId())
        ) {
            return null;
        }

        $classroom = $teacherAssignment->getClassroom();
        $institution = $classroom->getInstitution();
        $year = $classroom->getAcademicYear();
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            return null;
        }
        if ($openScope && (ClassroomStatus::Active !== $classroom->getStatus()
            || AcademicYearStatus::Active !== $year->getStatus())) {
            return null;
        }

        return $membership;
    }

    public function lockSubmission(HomeworkSubmission $peek): ?HomeworkSubmission
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('s', 'a', 'r', 'd', 'c', 'i', 'y', 'ce')
            ->from(HomeworkSubmission::class, 's')
            ->innerJoin('s.assignment', 'a')
            ->innerJoin('s.recipient', 'r')
            ->innerJoin('a.definition', 'd')
            ->innerJoin('a.classroom', 'c')
            ->innerJoin('a.institution', 'i')
            ->innerJoin('a.academicYear', 'y')
            ->leftJoin('s.currentEvaluation', 'ce')
            ->andWhere('s.id = :id')
            ->setParameter('id', $peek->getId(), 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row instanceof HomeworkSubmission ? $row : null;
    }
}
