<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionAlignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestionRevisionAlignment>
 */
class QuestionRevisionAlignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionRevisionAlignment::class);
    }

    /**
     * @return list<QuestionRevisionAlignment>
     */
    public function findByRevision(QuestionRevision $revision): array
    {
        /** @var list<QuestionRevisionAlignment> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countByRevision(QuestionRevision $revision): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(QuestionRevisionAlignment $alignment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($alignment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
