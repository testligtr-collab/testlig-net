<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassroomCourseActiveGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ClassroomCourseActiveGuard>
 */
class ClassroomCourseActiveGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassroomCourseActiveGuard::class);
    }

    public function findFor(Uuid $classroomId, Uuid $subjectId): ?ClassroomCourseActiveGuard
    {
        return $this->find(['classroom' => $classroomId, 'subject' => $subjectId]);
    }

    public function save(ClassroomCourseActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ClassroomCourseActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->remove($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
