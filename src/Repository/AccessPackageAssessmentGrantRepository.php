<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessPackageAssessmentGrant;
use App\Entity\AccessPackageVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessPackageAssessmentGrant>
 */
class AccessPackageAssessmentGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessPackageAssessmentGrant::class);
    }

    public function findOneById(Uuid $id): ?AccessPackageAssessmentGrant
    {
        $entity = $this->find($id);

        return $entity instanceof AccessPackageAssessmentGrant ? $entity : null;
    }

    public function save(AccessPackageAssessmentGrant $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AccessPackageAssessmentGrant $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<AccessPackageAssessmentGrant> */
    public function findByVersion(AccessPackageVersion $version): array
    {
        /** @var list<AccessPackageAssessmentGrant> $rows */
        $rows = $this->findBy(['version' => $version], ['createdAt' => 'ASC']);

        return $rows;
    }
}
