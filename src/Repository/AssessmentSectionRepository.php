<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssessmentSection>
 */
class AssessmentSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentSection::class);
    }

    public function save(AssessmentSection $section, bool $flush = true): void
    {
        $this->getEntityManager()->persist($section);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
