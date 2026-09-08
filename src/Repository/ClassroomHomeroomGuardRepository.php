<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassroomHomeroomGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ClassroomHomeroomGuard>
 */
class ClassroomHomeroomGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassroomHomeroomGuard::class);
    }

    public function findForClassroom(Uuid $classroomId): ?ClassroomHomeroomGuard
    {
        return $this->find($classroomId);
    }

    public function save(ClassroomHomeroomGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ClassroomHomeroomGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->remove($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
