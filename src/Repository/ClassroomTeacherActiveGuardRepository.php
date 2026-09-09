<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassroomTeacherActiveGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ClassroomTeacherActiveGuard>
 */
class ClassroomTeacherActiveGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassroomTeacherActiveGuard::class);
    }

    public function findFor(Uuid $classroomId, Uuid $teacherMembershipId): ?ClassroomTeacherActiveGuard
    {
        return $this->find(['classroom' => $classroomId, 'teacherMembership' => $teacherMembershipId]);
    }

    public function save(ClassroomTeacherActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ClassroomTeacherActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->remove($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
