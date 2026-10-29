<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Institution;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionAlignment;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Question>
 */
class QuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Question::class);
    }

    public function findOneById(Uuid $id): ?Question
    {
        return $this->find($id);
    }

    /**
     * @return list<Question>
     */
    public function findPublishedPlatform(): array
    {
        /** @var list<Question> $rows */
        $rows = $this->createQueryBuilder('q')
            ->andWhere('q.scope = :scope')
            ->andWhere('q.status = :status')
            ->setParameter('scope', QuestionScope::Platform)
            ->setParameter('status', QuestionStatus::Published)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<Question>
     */
    public function findPublishedForInstitution(Institution $institution): array
    {
        /** @var list<Question> $rows */
        $rows = $this->createQueryBuilder('q')
            ->andWhere('q.scope = :scope')
            ->andWhere('q.institution = :institution')
            ->andWhere('q.status = :status')
            ->setParameter('scope', QuestionScope::Institution)
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', QuestionStatus::Published)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<Question>
     */
    public function findPlatformVisible(User $actor, bool $seeAll): array
    {
        return $this->findPlatformVisibleFiltered($actor, $seeAll, null, null, null, '', false);
    }

    /**
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
            ->select('q.status AS status', 'COUNT(q.id) AS total')
            ->groupBy('q.status')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $status = $row['status'] ?? null;
            $key = $status instanceof QuestionStatus ? $status->value : (\is_string($status) ? $status : '');
            if (!isset($counts[$key])) {
                continue;
            }
            $counts[$key] = (int) $row['total'];
            $counts['total'] += $counts[$key];
        }

        return $counts;
    }

    /**
     * Newest visible platform questions. Subject is joined so the list does not query it per row.
     *
     * @return list<Question>
     */
    public function findPlatformVisibleFiltered(
        User $actor,
        bool $seeAll,
        ?QuestionStatus $status,
        ?GradeLevel $grade,
        ?Uuid $subjectId,
        string $text,
        bool $blocked,
    ): array {
        if ($blocked) {
            return [];
        }
        $qb = $this->platformVisibleQuery($actor, $seeAll)
            ->innerJoin('q.subject', 'subject')
            ->addSelect('subject')
            ->orderBy('q.updatedAt', 'DESC')
            ->addOrderBy('q.id', 'DESC')
            ->setMaxResults(100);
        if ($status instanceof QuestionStatus) {
            $qb->andWhere('q.status = :filterStatus')->setParameter('filterStatus', $status);
        }
        if ($grade instanceof GradeLevel) {
            $qb->andWhere('q.gradeLevel = :filterGrade')->setParameter('filterGrade', $grade);
        }
        if ($subjectId instanceof Uuid) {
            $qb->andWhere('q.subject = :filterSubject')->setParameter('filterSubject', $subjectId, 'uuid');
        }
        if ('' !== $text) {
            $qb->andWhere(
                'q.code LIKE :needle OR EXISTS (SELECT 1 FROM '.QuestionRevision::class.' revision WHERE revision.question = q AND revision.revisionNumber = q.currentRevisionNumber AND revision.stemContent LIKE :needle)'
            )->setParameter('needle', '%'.$this->like($text).'%');
        }

        /** @var list<Question> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    private function platformVisibleQuery(User $actor, bool $seeAll): QueryBuilder
    {
        $qb = $this->createQueryBuilder('q')
            ->andWhere('q.scope = :scope')
            ->setParameter('scope', QuestionScope::Platform);
        if (!$seeAll) {
            $qb->andWhere('q.status = :published OR IDENTITY(q.createdBy) = :actor')
                ->setParameter('published', QuestionStatus::Published)
                ->setParameter('actor', $actor->getId(), 'uuid');
        }

        return $qb;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<Question>
     */
    public function findByCodes(array $codes): array
    {
        if ([] === $codes) {
            return [];
        }

        /** @var list<Question> $rows */
        $rows = $this->createQueryBuilder('q')
            ->andWhere('q.code IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    public function findCodesPresent(array $codes): array
    {
        if ([] === $codes) {
            return [];
        }

        /** @var list<string> $rows */
        $rows = $this->createQueryBuilder('q')
            ->select('q.code')
            ->andWhere('q.code IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getSingleColumnResult();

        return $rows;
    }

    public function countCreatedBy(User $actor): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->andWhere('q.scope = :scope')
            ->andWhere('IDENTITY(q.createdBy) = :actor')
            ->setParameter('scope', QuestionScope::Platform)
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Published platform questions for one subject and grade. Answer keys are not loaded.
     *
     * @return list<Question>
     */
    public function searchPublishedPlatform(
        Subject $subject,
        GradeLevel $grade,
        string $code,
        string $text,
        ?QuestionDifficulty $difficulty,
        string $outcomeCode,
        int $limit,
        int $offset,
    ): array {
        $qb = $this->createQueryBuilder('q')
            ->innerJoin(QuestionRevision::class, 'r', 'WITH', 'r.question = q AND r.revisionNumber = q.currentRevisionNumber')
            ->andWhere('q.scope = :scope')
            ->andWhere('q.status = :status')
            ->andWhere('q.subject = :subject')
            ->andWhere('q.gradeLevel = :grade')
            ->setParameter('scope', QuestionScope::Platform)
            ->setParameter('status', QuestionStatus::Published)
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->setParameter('grade', $grade)
            ->orderBy('q.code', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ('' !== $code) {
            $qb->andWhere('q.code LIKE :code')->setParameter('code', '%'.$this->like($code).'%');
        }
        if ('' !== $text) {
            $qb->andWhere('r.stemContent LIKE :text')->setParameter('text', '%'.$this->like($text).'%');
        }
        if ($difficulty instanceof QuestionDifficulty) {
            $qb->andWhere('r.difficulty = :difficulty')->setParameter('difficulty', $difficulty);
        }
        if ('' !== $outcomeCode) {
            $qb->andWhere(
                'EXISTS (SELECT 1 FROM '.QuestionRevisionAlignment::class.' alignment JOIN alignment.learningOutcome outcome WHERE alignment.revision = r AND alignment.isPrimary = true AND outcome.code = :outcome)'
            )->setParameter('outcome', $outcomeCode);
        }

        /** @var list<Question> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function save(Question $question, bool $flush = true): void
    {
        $this->getEntityManager()->persist($question);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
