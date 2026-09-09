<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CourseTeacherAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CourseTeacherAssignment>
 */
class CourseTeacherAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseTeacherAssignment::class);
    }

    public function findOneById(Uuid $id): ?CourseTeacherAssignment
    {
        return $this->find($id);
    }

    public function save(CourseTeacherAssignment $assignment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($assignment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
