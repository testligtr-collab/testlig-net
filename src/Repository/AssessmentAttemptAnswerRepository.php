<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentAttemptAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentAttemptAnswer>
 */
class AssessmentAttemptAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentAttemptAnswer::class);
    }

    public function findOneById(Uuid $id): ?AssessmentAttemptAnswer
    {
        return $this->find($id);
    }

    public function findAnswer(Uuid $attemptId, Uuid $attemptItemId): ?AssessmentAttemptAnswer
    {
        /** @var AssessmentAttemptAnswer|null $answer */
        $answer = $this->createQueryBuilder('a')
            ->andWhere('a.attempt = :attemptId')
            ->andWhere('a.attemptItem = :attemptItemId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->setParameter('attemptItemId', $attemptItemId, 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $answer;
    }

    /**
     * @return list<AssessmentAttemptAnswer>
     */
    public function findAllForAttempt(Uuid $attemptId): array
    {
        /** @var list<AssessmentAttemptAnswer> $answers */
        $answers = $this->createQueryBuilder('a')
            ->andWhere('a.attempt = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->getQuery()
            ->getResult();

        return $answers;
    }

    public function countAnsweredItems(Uuid $attemptId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.attempt = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnansweredRequired(Uuid $attemptId): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(i.id)
            FROM assessment_attempt_items i
            LEFT JOIN assessment_attempt_answers a
                ON a.attempt_item_id = i.id
               AND a.attempt_id = i.attempt_id
            WHERE i.attempt_id = :attemptId
              AND i.required = 1
              AND a.id IS NULL
            SQL;

        $result = $this->getEntityManager()->getConnection()->fetchOne(
            $sql,
            ['attemptId' => $attemptId->toBinary()],
        );

        return (int) $result;
    }

    public function save(AssessmentAttemptAnswer $answer, bool $flush = true): void
    {
        $this->getEntityManager()->persist($answer);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
