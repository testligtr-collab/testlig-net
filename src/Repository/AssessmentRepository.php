<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assessment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Assessment>
 */
class AssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assessment::class);
    }

    public function findOneById(Uuid $id): ?Assessment
    {
        return $this->find($id);
    }

    public function save(Assessment $assessment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($assessment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
