<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassroomStudentEnrollment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ClassroomStudentEnrollment>
 */
class ClassroomStudentEnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassroomStudentEnrollment::class);
    }

    public function findOneById(Uuid $id): ?ClassroomStudentEnrollment
    {
        return $this->find($id);
    }

    public function save(ClassroomStudentEnrollment $enrollment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($enrollment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
