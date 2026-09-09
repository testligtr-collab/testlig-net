<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Classroom;
use App\Entity\ClassroomCourse;
use App\Enum\CourseTeacherAssignmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ClassroomCourse>
 */
class ClassroomCourseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassroomCourse::class);
    }

    public function findOneById(Uuid $id): ?ClassroomCourse
    {
        return $this->find($id);
    }

    public function countActiveTeacherAssignments(ClassroomCourse $course): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(\App\Entity\CourseTeacherAssignment::class, 'a')
            ->andWhere('a.classroomCourse = :course')
            ->andWhere('a.status = :status')
            ->setParameter('course', $course->getId(), 'uuid')
            ->setParameter('status', CourseTeacherAssignmentStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ClassroomCourse>
     */
    public function findByClassroom(Classroom $classroom): array
    {
        /** @var list<ClassroomCourse> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.classroom = :classroom')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(ClassroomCourse $course, bool $flush = true): void
    {
        $this->getEntityManager()->persist($course);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
