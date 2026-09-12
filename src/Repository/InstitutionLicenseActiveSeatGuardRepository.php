<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstitutionLicenseActiveSeatGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstitutionLicenseActiveSeatGuard>
 */
class InstitutionLicenseActiveSeatGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionLicenseActiveSeatGuard::class);
    }

    public function findOneById(Uuid $id): ?InstitutionLicenseActiveSeatGuard
    {
        $entity = $this->find($id);

        return $entity instanceof InstitutionLicenseActiveSeatGuard ? $entity : null;
    }

    public function save(InstitutionLicenseActiveSeatGuard $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(InstitutionLicenseActiveSeatGuard $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
