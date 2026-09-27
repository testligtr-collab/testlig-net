<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Institution;
use App\Entity\InstitutionTeacherInvitePendingGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstitutionTeacherInvitePendingGuard>
 */
class InstitutionTeacherInvitePendingGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionTeacherInvitePendingGuard::class);
    }

    public function save(InstitutionTeacherInvitePendingGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneForUpdate(Institution $institution, string $normalizedEmail): ?InstitutionTeacherInvitePendingGuard
    {
        $guard = $this->getEntityManager()->find(
            InstitutionTeacherInvitePendingGuard::class,
            ['institution' => $institution, 'normalizedEmail' => $normalizedEmail],
            LockMode::PESSIMISTIC_WRITE,
        );

        return $guard instanceof InstitutionTeacherInvitePendingGuard ? $guard : null;
    }
}
