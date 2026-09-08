<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassroomTeacherAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ClassroomTeacherAssignment>
 */
class ClassroomTeacherAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassroomTeacherAssignment::class);
    }

    public function findOneById(Uuid $id): ?ClassroomTeacherAssignment
    {
        return $this->find($id);
    }

    public function save(ClassroomTeacherAssignment $assignment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($assignment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
