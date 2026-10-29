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

        /** @var list<AssessmentItem> $rows */
        $rows = $this->createQueryBuilder('item')
            ->addSelect('assessmentRevision', 'assessment', 'questionRevision', 'question')
            ->innerJoin('item.assessmentRevision', 'assessmentRevision')
            ->innerJoin('assessmentRevision.assessment', 'assessment')
            ->innerJoin('item.questionRevision', 'questionRevision')
            ->innerJoin('item.question', 'question')
            ->andWhere('IDENTITY(item.question) IN (:questionIds)')
            ->andWhere('assessment.scope = :scope')
            ->andWhere('assessment.institution IS NULL')
            ->setParameter('questionIds', array_map(static fn (Question $question): Uuid => $question->getId(), $questions))
            ->setParameter('scope', AssessmentScope::Platform)
            ->orderBy('assessment.code', 'ASC')
            ->addOrderBy('assessmentRevision.revisionNumber', 'ASC')
            ->addOrderBy('item.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
