<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ParentStudentLinkActiveGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ParentStudentLinkActiveGuard>
 */
class ParentStudentLinkActiveGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParentStudentLinkActiveGuard::class);
    }

    public function findForPair(Uuid $parentUserId, Uuid $studentUserId): ?ParentStudentLinkActiveGuard
    {
        return $this->find(['parent' => $parentUserId, 'student' => $studentUserId]);
    }

    public function save(ParentStudentLinkActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ParentStudentLinkActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->remove($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
