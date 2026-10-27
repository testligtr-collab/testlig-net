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
        return null !== $this->findOneByIdentity($subject, $gradeLevel, $code, $version);
    }

    public function findOneByIdentity(
        Subject $subject,
        GradeLevel $gradeLevel,
        string $code,
        string $version,
    ): ?CurriculumProgram {
        /** @var CurriculumProgram|null $program */
        $program = $this->createQueryBuilder('p')
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

        return $program;
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

    /**
     * @return array{draft: int, published: int, retired: int, total: int}
     */
    public function countByStatus(): array
    {
        /** @var list<array{status: CurriculumStatus, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS total')
            ->groupBy('p.status')
            ->getQuery()
            ->getArrayResult();
        $counts = ['draft' => 0, 'published' => 0, 'retired' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
            $counts['total'] += (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array{rows: list<CurriculumProgram>, total: int}
     */
    public function searchAdmin(string $q, ?GradeLevel $grade, ?CurriculumStatus $status, ?Uuid $subjectId, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('p')->join('p.subject', 's');
        $this->applyAdminFilters($qb, $q, $grade, $status, $subjectId);
        $countQb = $this->createQueryBuilder('p')->select('COUNT(p.id)')->join('p.subject', 's');
        $this->applyAdminFilters($countQb, $q, $grade, $status, $subjectId);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        /** @var list<CurriculumProgram> $rows */
        $rows = $qb
            ->orderBy('p.updatedAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    private function applyAdminFilters(\Doctrine\ORM\QueryBuilder $qb, string $q, ?GradeLevel $grade, ?CurriculumStatus $status, ?Uuid $subjectId): void
    {
        if ('' !== $q) {
            $qb->andWhere('p.code LIKE :q OR p.normalizedName LIKE :q')
                ->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_').'%');
        }
        if ($grade instanceof GradeLevel) {
            $qb->andWhere('p.gradeLevel = :grade')->setParameter('grade', $grade);
        }
        if ($status instanceof CurriculumStatus) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }
        if ($subjectId instanceof Uuid) {
            $qb->andWhere('IDENTITY(p.subject) = :subjectId')->setParameter('subjectId', $subjectId, 'uuid');
        }
    }

    public function connection(): \Doctrine\DBAL\Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    public function save(CurriculumProgram $program, bool $flush = true): void
    {
        $this->getEntityManager()->persist($program);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
