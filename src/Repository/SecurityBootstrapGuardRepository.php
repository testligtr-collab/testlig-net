<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SecurityBootstrapGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityBootstrapGuard>
 */
class SecurityBootstrapGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityBootstrapGuard::class);
    }

    public function findSuperAdminGuard(): ?SecurityBootstrapGuard
    {
        return $this->find(SecurityBootstrapGuard::SUPER_ADMIN);
    }

    public function save(SecurityBootstrapGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
