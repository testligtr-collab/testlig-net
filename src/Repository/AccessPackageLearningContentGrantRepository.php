<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessPackageLearningContentGrant;
use App\Entity\AccessPackageVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessPackageLearningContentGrant>
 */
class AccessPackageLearningContentGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessPackageLearningContentGrant::class);
    }

    public function findOneById(Uuid $id): ?AccessPackageLearningContentGrant
    {
        $entity = $this->find($id);

        return $entity instanceof AccessPackageLearningContentGrant ? $entity : null;
    }

    public function save(AccessPackageLearningContentGrant $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AccessPackageLearningContentGrant $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<AccessPackageLearningContentGrant> */
    public function findByVersion(AccessPackageVersion $version): array
    {
        /** @var list<AccessPackageLearningContentGrant> $rows */
        $rows = $this->findBy(['version' => $version], ['createdAt' => 'ASC']);

        return $rows;
    }
}
