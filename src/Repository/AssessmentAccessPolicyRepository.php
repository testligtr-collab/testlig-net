<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentAccessPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentAccessPolicy>
 */
class AssessmentAccessPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentAccessPolicy::class);
    }

    public function findOneById(Uuid $id): ?AssessmentAccessPolicy
    {
        $entity = $this->find($id);

        return $entity instanceof AssessmentAccessPolicy ? $entity : null;
    }

    public function save(AssessmentAccessPolicy $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AssessmentAccessPolicy $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findForAssessment(Uuid $assessmentId): ?AssessmentAccessPolicy
    {
        $entity = $this->find($assessmentId);

        return $entity instanceof AssessmentAccessPolicy ? $entity : null;
    }
}
