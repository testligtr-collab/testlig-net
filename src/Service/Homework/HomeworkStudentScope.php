<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Entity\ClassroomStudentEnrollment;
use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkRecipient;
use App\Entity\HomeworkSubmission;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\HomeworkAssignmentStatus;
use App\Enum\HomeworkSubmissionStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\StudentEnrollmentStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Current (not historical) student access for homework.
 *
 * A recipient snapshot alone is never enough. Global ROLE_* / Admin / SuperAdmin
 * hierarchy is not consulted. Teachers and parents gain nothing here.
 */
final class HomeworkStudentScope
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Active student membership + active enrollment on the assignment classroom,
     * with active institution (and for writes: active classroom + academic year).
     */
    public function findOpenEnrollment(
        User $actor,
        HomeworkAssignment $assignment,
        bool $openScope,
        bool $lock = false,
    ): ?ClassroomStudentEnrollment {
        if (HomeworkAssignmentStatus::Assigned !== $assignment->getStatus()) {
            return null;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('e', 'm', 'c', 'i', 'y')
            ->from(ClassroomStudentEnrollment::class, 'e')
            ->innerJoin('e.studentMembership', 'm')
            ->innerJoin('e.classroom', 'c')
            ->innerJoin('c.institution', 'i')
            ->innerJoin('c.academicYear', 'y')
            ->andWhere('e.classroom = :classroom')
            ->andWhere('e.status = :enrollmentStatus')
            ->andWhere('m.user = :actor')
            ->andWhere('m.institution = i')
            ->andWhere('m.status = :membershipStatus')
            ->andWhere('m.role = :membershipRole')
            ->andWhere('i.status = :institutionStatus')
            ->setParameter('classroom', $assignment->getClassroom()->getId(), 'uuid')
            ->setParameter('enrollmentStatus', StudentEnrollmentStatus::Active)
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('membershipRole', InstitutionMembershipRole::Student)
            ->setParameter('institutionStatus', InstitutionStatus::Active)
            ->setMaxResults(1);
        if ($openScope) {
            $qb->andWhere('c.status = :classroomStatus')
                ->andWhere('y.status = :yearStatus')
                ->setParameter('classroomStatus', ClassroomStatus::Active)
                ->setParameter('yearStatus', AcademicYearStatus::Active);
        }
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $row = $query->getOneOrNullResult();

        return $row instanceof ClassroomStudentEnrollment ? $row : null;
    }

    public function findRecipient(User $actor, Uuid $assignmentId, bool $lock = false): ?HomeworkRecipient
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r', 'a', 'c')
            ->from(HomeworkRecipient::class, 'r')
            ->innerJoin('r.assignment', 'a')
            ->innerJoin('a.classroom', 'c')
            ->andWhere('a.id = :assignment')
            ->andWhere('r.studentUser = :actor')
            ->andWhere('a.status = :status')
            ->setParameter('assignment', $assignmentId, 'uuid')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('status', HomeworkAssignmentStatus::Assigned)
            ->setMaxResults(1);
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $row = $query->getOneOrNullResult();

        return $row instanceof HomeworkRecipient ? $row : null;
    }

    public function findSubmissionForRecipient(HomeworkRecipient $recipient, bool $lock = false): ?HomeworkSubmission
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(HomeworkSubmission::class, 's')
            ->andWhere('s.recipient = :recipient')
            ->setParameter('recipient', $recipient->getId(), 'uuid')
            ->setMaxResults(1);
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $row = $query->getOneOrNullResult();

        return $row instanceof HomeworkSubmission ? $row : null;
    }

    public function findOwnSubmitted(User $actor, Uuid $assignmentId): ?HomeworkSubmission
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('s', 'a', 'r')
            ->from(HomeworkSubmission::class, 's')
            ->innerJoin('s.assignment', 'a')
            ->innerJoin('s.recipient', 'r')
            ->andWhere('a.id = :assignment')
            ->andWhere('s.studentUser = :actor')
            ->andWhere('s.status = :status')
            ->setParameter('assignment', $assignmentId, 'uuid')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('status', HomeworkSubmissionStatus::Submitted)
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row instanceof HomeworkSubmission ? $row : null;
    }

    public function membershipStillActive(InstitutionMembership $membership): bool
    {
        return InstitutionMembershipStatus::Active === $membership->getStatus()
            && InstitutionMembershipRole::Student === $membership->getRole();
    }
}
