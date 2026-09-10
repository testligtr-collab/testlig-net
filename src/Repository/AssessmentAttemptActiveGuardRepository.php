<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentAttemptActiveGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentAttemptActiveGuard>
 */
class AssessmentAttemptActiveGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentAttemptActiveGuard::class);
    }

    public function findForRecipient(Uuid $recipientId): ?AssessmentAttemptActiveGuard
    {
        return $this->find($recipientId);
    }

    public function save(AssessmentAttemptActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AssessmentAttemptActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->remove($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
