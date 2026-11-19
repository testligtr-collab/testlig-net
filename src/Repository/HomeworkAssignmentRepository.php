<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkSubmission;
use App\Enum\HomeworkSubmissionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HomeworkAssignment>
 */
final class HomeworkAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HomeworkAssignment::class);
    }

    /**
     * Only assignment metadata and aggregate submission counts are exposed to the admin view.
     *
     * @return list<array{title: string, classroom: string, recipients: int|string, submitted: int|string}>
     */
    public function adminOverview(int $offset, int $limit): array
    {
        /** @var list<array{title: string, classroom: string, recipients: int|string, submitted: int|string}> $rows */
        $rows = $this->createQueryBuilder('assignment')
            ->select('assignment.title AS title', 'assignedClassroom.name AS classroom', 'assignment.recipientCount AS recipients')
            ->addSelect('(SELECT COUNT(submission.id) FROM '.HomeworkSubmission::class.' submission WHERE IDENTITY(submission.assignment) = assignment.id AND submission.status = :submitted) AS submitted')
            ->join('assignment.classroom', 'assignedClassroom')
            ->setParameter('submitted', HomeworkSubmissionStatus::Submitted->value)
            ->orderBy('assignment.assignedAt', 'DESC')
            ->addOrderBy('assignment.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return $rows;
    }
}
