<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assessment;
use App\Entity\AssessmentItem;
use App\Entity\AssessmentRevision;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\GradeLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Assessment>
 */
class AssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assessment::class);
    }

    public function findOneById(Uuid $id): ?Assessment
    {
        return $this->find($id);
    }

    /**
     * @return list<Assessment>
     */
    public function findPlatformVisible(User $actor, bool $seeAll): array
    {
        $rows = [];
        foreach ($this->findPlatformVisibleFiltered($actor, $seeAll, null, null, null, '', false) as $row) {
            $rows[] = $row['assessment'];
        }

        return $rows;
    }

    /**
     * Counts the actor's whole visible platform set. Filters and the 100-row page are not applied.
     *
     * @return array{total: int, draft: int, in_review: int, published: int, archived: int}
     */
    public function countPlatformVisibleByStatus(User $actor, bool $seeAll): array
    {
        $counts = [
            'total' => 0,
            'draft' => 0,
            'in_review' => 0,
            'published' => 0,
            'archived' => 0,
        ];
        $rows = $this->platformVisibleQuery($actor, $seeAll)
            ->select('a.status AS status', 'COUNT(a.id) AS total')
            ->groupBy('a.status')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $status = $row['status'] ?? null;
            $key = $status instanceof AssessmentStatus ? $status->value : (\is_string($status) ? $status : '');
            if (!isset($counts[$key])) {
                continue;
            }
            $counts[$key] = (int) $row['total'];
            $counts['total'] += $counts[$key];
        }

        return $counts;
    }

    /**
     * Newest visible platform tests. The question total counts only the current revision.
     *
     * @return list<array{assessment: Assessment, questions: int}>
     */
    public function findPlatformVisibleFiltered(
        User $actor,
        bool $seeAll,
        ?AssessmentStatus $status,
        ?GradeLevel $grade,
        ?Uuid $subjectId,
        string $text,
        bool $blocked,
    ): array {
        if ($blocked) {
            return [];
        }
        $qb = $this->filteredQuery($actor, $seeAll, $status, $grade, $subjectId, $text)
            ->leftJoin('a.subject', 'subject')
            ->addSelect('subject')
            ->leftJoin('a.currentRevision', 'currentRevision')
            ->addSelect('currentRevision')
            ->addSelect('(SELECT COUNT(item.id) FROM '.AssessmentItem::class.' item JOIN item.assessmentRevision itemRevision WHERE itemRevision.assessment = a AND itemRevision.revisionNumber = a.currentRevisionNumber) AS questionCount')
            ->orderBy('a.updatedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(100);

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            if (!\is_array($row) || !isset($row[0]) || !$row[0] instanceof Assessment) {
                continue;
            }
            $rows[] = [
                'assessment' => $row[0],
                'questions' => (int) ($row['questionCount'] ?? 0),
            ];
        }

        return $rows;
    }

    public function countPlatformVisibleFiltered(
        User $actor,
        bool $seeAll,
        ?AssessmentStatus $status,
        ?GradeLevel $grade,
        ?Uuid $subjectId,
        string $text,
        bool $blocked,
    ): int {
        if ($blocked) {
            return 0;
        }

        return (int) $this->filteredQuery($actor, $seeAll, $status, $grade, $subjectId, $text)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCreatedBy(User $actor): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.scope = :scope')
            ->andWhere('IDENTITY(a.createdBy) = :actor')
            ->setParameter('scope', AssessmentScope::Platform)
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Assessment>
     */
    public function findPublishedPlatformForGrade(GradeLevel $grade): array
    {
        /** @var list<Assessment> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.scope = :scope')
            ->andWhere('a.status = :status')
            ->andWhere('a.gradeLevel = :grade')
            ->setParameter('scope', AssessmentScope::Platform)
            ->setParameter('status', AssessmentStatus::Published)
            ->setParameter('grade', $grade)
            ->orderBy('a.publishedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findPublishedPlatformByCode(string $code, GradeLevel $grade): ?Assessment
    {
        $assessment = $this->createQueryBuilder('a')
            ->andWhere('a.code = :code')
            ->andWhere('a.scope = :scope')
            ->andWhere('a.status = :status')
            ->andWhere('a.gradeLevel = :grade')
            ->setParameter('code', $code)
            ->setParameter('scope', AssessmentScope::Platform)
            ->setParameter('status', AssessmentStatus::Published)
            ->setParameter('grade', $grade)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $assessment instanceof Assessment ? $assessment : null;
    }

    public function save(Assessment $assessment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($assessment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    private function platformVisibleQuery(User $actor, bool $seeAll): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.scope = :scope')
            ->setParameter('scope', AssessmentScope::Platform);
        if (!$seeAll) {
            $qb->andWhere('a.status = :published OR IDENTITY(a.createdBy) = :actor')
                ->setParameter('published', AssessmentStatus::Published)
                ->setParameter('actor', $actor->getId(), 'uuid');
        }

        return $qb;
    }

    private function filteredQuery(
        User $actor,
        bool $seeAll,
        ?AssessmentStatus $status,
        ?GradeLevel $grade,
        ?Uuid $subjectId,
        string $text,
    ): QueryBuilder {
        $qb = $this->platformVisibleQuery($actor, $seeAll);
        if ($status instanceof AssessmentStatus) {
            $qb->andWhere('a.status = :filterStatus')->setParameter('filterStatus', $status);
        }
        if ($grade instanceof GradeLevel) {
            $qb->andWhere('a.gradeLevel = :filterGrade')->setParameter('filterGrade', $grade);
        }
        if ($subjectId instanceof Uuid) {
            $qb->andWhere('a.subject = :filterSubject')->setParameter('filterSubject', $subjectId, 'uuid');
        }
        if ('' !== $text) {
            $qb->andWhere(
                'a.code LIKE :needle OR EXISTS (SELECT 1 FROM '.AssessmentRevision::class.' revision WHERE revision.assessment = a AND revision.revisionNumber = a.currentRevisionNumber AND revision.title LIKE :needle)'
            )->setParameter('needle', '%'.$this->like($text).'%');
        }

        return $qb;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
