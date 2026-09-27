<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Institution;
use App\Entity\InstitutionStudentInvitePendingGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstitutionStudentInvitePendingGuard>
 */
class InstitutionStudentInvitePendingGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionStudentInvitePendingGuard::class);
    }

    public function save(InstitutionStudentInvitePendingGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneForUpdate(
        Institution $institution,
        AcademicYear $academicYear,
        string $normalizedEmail,
    ): ?InstitutionStudentInvitePendingGuard {
        $guard = $this->getEntityManager()->find(
            InstitutionStudentInvitePendingGuard::class,
            [
                'institution' => $institution,
                'academicYear' => $academicYear,
                'normalizedEmail' => $normalizedEmail,
            ],
            LockMode::PESSIMISTIC_WRITE,
        );

        return $guard instanceof InstitutionStudentInvitePendingGuard ? $guard : null;
    }
}
