<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionRevisionPrimaryAlignmentGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestionRevisionPrimaryAlignmentGuard>
 */
class QuestionRevisionPrimaryAlignmentGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionRevisionPrimaryAlignmentGuard::class);
    }

    public function save(QuestionRevisionPrimaryAlignmentGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
