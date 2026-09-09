<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CourseTeacherActiveGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CourseTeacherActiveGuard>
 */
class CourseTeacherActiveGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseTeacherActiveGuard::class);
    }

    public function findFor(Uuid $classroomCourseId, Uuid $teacherMembershipId): ?CourseTeacherActiveGuard
    {
        return $this->find([
            'classroomCourse' => $classroomCourseId,
            'teacherMembership' => $teacherMembershipId,
        ]);
    }

    public function save(CourseTeacherActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CourseTeacherActiveGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->remove($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
