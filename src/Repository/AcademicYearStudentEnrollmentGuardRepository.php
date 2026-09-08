<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYearStudentEnrollmentGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AcademicYearStudentEnrollmentGuard>
 */
class AcademicYearStudentEnrollmentGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcademicYearStudentEnrollmentGuard::class);
    }

    public function findFor(Uuid $academicYearId, Uuid $studentMembershipId): ?AcademicYearStudentEnrollmentGuard
    {
        return $this->find(['academicYear' => $academicYearId, 'studentMembership' => $studentMembershipId]);
    }

    public function save(AcademicYearStudentEnrollmentGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AcademicYearStudentEnrollmentGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->remove($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
