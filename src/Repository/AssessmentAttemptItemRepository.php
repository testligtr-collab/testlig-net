<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentAttemptItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentAttemptItem>
 */
class AssessmentAttemptItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentAttemptItem::class);
    }

    public function findOneById(Uuid $id): ?AssessmentAttemptItem
    {
        return $this->find($id);
    }

    /**
     * @return list<AssessmentAttemptItem>
     */
    public function findItemsForAttemptOrdered(Uuid $attemptId): array
    {
        /** @var list<AssessmentAttemptItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->andWhere('i.attempt = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->orderBy('i.presentationPosition', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(AssessmentAttemptItem $item, bool $flush = true): void
    {
        $this->getEntityManager()->persist($item);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
