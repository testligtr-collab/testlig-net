<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstitutionActiveAcademicYearGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstitutionActiveAcademicYearGuard>
 */
class InstitutionActiveAcademicYearGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionActiveAcademicYearGuard::class);
    }

    public function findForInstitution(Uuid $institutionId): ?InstitutionActiveAcademicYearGuard
    {
        return $this->find($institutionId);
    }

    public function save(InstitutionActiveAcademicYearGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(InstitutionActiveAcademicYearGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->remove($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
