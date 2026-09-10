<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssessmentItem>
 */
class AssessmentItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentItem::class);
    }

    public function save(AssessmentItem $item, bool $flush = true): void
    {
        $this->getEntityManager()->persist($item);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
