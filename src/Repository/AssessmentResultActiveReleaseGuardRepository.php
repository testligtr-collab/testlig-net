<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentResultActiveReleaseGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentResultActiveReleaseGuard>
 */
class AssessmentResultActiveReleaseGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentResultActiveReleaseGuard::class);
    }

    public function findForAttempt(Uuid $attemptId): ?AssessmentResultActiveReleaseGuard
    {
        return $this->find($attemptId);
    }

    public function save(AssessmentResultActiveReleaseGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
