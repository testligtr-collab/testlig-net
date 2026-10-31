<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentItem;
use App\Entity\Question;
use App\Enum\AssessmentScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentItem>
 */
class AssessmentItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentItem::class);
    }

    /**
     * Item totals for the revisions shown on the list. Older revisions are not included.
     *
     * @param list<Uuid> $revisionIds
     *
     * @return array<string, int>
     */
    public function countByRevisionIds(array $revisionIds): array
    {
        if ([] === $revisionIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('rev.id AS revisionId', 'COUNT(i.id) AS total')
            ->join('i.assessmentRevision', 'rev')
            ->andWhere('rev.id IN (:ids)')
            ->setParameter('ids', $revisionIds)
            ->groupBy('rev.id')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $id = $row['revisionId'] ?? '';
            $raw = $id instanceof Uuid ? $id->toRfc4122() : (string) $id;
            try {
                $key = Uuid::fromString($raw)->toRfc4122();
            } catch (\InvalidArgumentException) {
                $key = $raw;
            }
            if ('' === $key) {
                continue;
            }
            $counts[$key] = (int) $row['total'];
        }

        return $counts;
    }

    public function save(AssessmentItem $item, bool $flush = true): void
    {
        $this->getEntityManager()->persist($item);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Platform test rows for the given questions. Institution assessments are not loaded.
     *
     * @param list<Question> $questions
     *
     * @return list<AssessmentItem>
     */
    public function findPlatformUsages(array $questions): array
    {
        if ([] === $questions) {
            return [];
        }

        $qb = $this->createQueryBuilder('item')
            ->addSelect('assessmentRevision', 'assessment', 'questionRevision', 'question')
            ->innerJoin('item.assessmentRevision', 'assessmentRevision')
            ->innerJoin('assessmentRevision.assessment', 'assessment')
            ->innerJoin('item.questionRevision', 'questionRevision')
            ->innerJoin('item.question', 'question')
            ->andWhere('assessment.scope = :scope')
            ->andWhere('assessment.institution IS NULL')
            ->setParameter('scope', AssessmentScope::Platform)
            ->orderBy('assessment.code', 'ASC')
            ->addOrderBy('assessmentRevision.revisionNumber', 'ASC')
            ->addOrderBy('item.position', 'ASC');
        $match = $qb->expr()->orX();
        foreach ($questions as $index => $question) {
            $match->add('IDENTITY(item.question) = :question'.$index);
            $qb->setParameter('question'.$index, $question->getId(), 'uuid');
        }

        /** @var list<AssessmentItem> $rows */
        $rows = $qb->andWhere($match)->getQuery()->getResult();

        return $rows;
    }
}
