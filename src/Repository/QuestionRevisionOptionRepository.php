<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestionRevisionOption>
 */
class QuestionRevisionOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionRevisionOption::class);
    }

    /**
     * @return list<QuestionRevisionOption>
     */
    public function findByRevision(QuestionRevision $revision): array
    {
        /** @var list<QuestionRevisionOption> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(QuestionRevisionOption $option, bool $flush = true): void
    {
        $this->getEntityManager()->persist($option);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
