<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentPublication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssessmentPublication>
 */
class AssessmentPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentPublication::class);
    }

    public function save(AssessmentPublication $publication, bool $flush = true): void
    {
        $this->getEntityManager()->persist($publication);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
