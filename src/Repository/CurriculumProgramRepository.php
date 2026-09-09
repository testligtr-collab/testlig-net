<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CurriculumProgram;
use App\Entity\Subject;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CurriculumProgram>
 */
class CurriculumProgramRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CurriculumProgram::class);
    }

    public function findOneById(Uuid $id): ?CurriculumProgram
    {
        return $this->find($id);
    }

    public function existsWithIdentity(
        Subject $subject,
        GradeLevel $gradeLevel,
        string $code,
        string $version,
    ): bool {
        return null !== $this->createQueryBuilder('p')
            ->select('1')
            ->andWhere('p.subject = :subject')
            ->andWhere('p.gradeLevel = :gradeLevel')
            ->andWhere('p.code = :code')
            ->andWhere('p.version = :version')
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->setParameter('gradeLevel', $gradeLevel)
            ->setParameter('code', $code)
            ->setParameter('version', $version)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<CurriculumProgram>
     */
    public function findPublishedForSubjectAndGrade(Subject $subject, GradeLevel $gradeLevel): array
    {
        /** @var list<CurriculumProgram> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.subject = :subject')
            ->andWhere('p.gradeLevel = :gradeLevel')
            ->andWhere('p.status = :status')
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->setParameter('gradeLevel', $gradeLevel)
            ->setParameter('status', CurriculumStatus::Published)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(CurriculumProgram $program, bool $flush = true): void
    {
        $this->getEntityManager()->persist($program);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
