<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Enum\StudentEnrollmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Classroom>
 */
class ClassroomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Classroom::class);
    }

    public function findOneById(Uuid $id): ?Classroom
    {
        return $this->find($id);
    }

    public function existsWithNormalizedName(AcademicYear $year, string $normalizedName): bool
    {
        return null !== $this->createQueryBuilder('c')
            ->select('1')
            ->andWhere('c.academicYear = :year')
            ->andWhere('c.normalizedName = :normalizedName')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('normalizedName', $normalizedName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countActiveEnrollments(Classroom $classroom): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\ClassroomStudentEnrollment::class, 'e')
            ->andWhere('e.classroom = :classroom')
            ->andWhere('e.status = :status')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('status', StudentEnrollmentStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Classroom>
     */
    public function findByInstitution(Institution $institution): array
    {
        /** @var list<Classroom> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(Classroom $classroom, bool $flush = true): void
    {
        $this->getEntityManager()->persist($classroom);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
