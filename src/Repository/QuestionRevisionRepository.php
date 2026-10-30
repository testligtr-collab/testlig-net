<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Question;
use App\Entity\QuestionRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<QuestionRevision>
 */
class QuestionRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionRevision::class);
    }

    public function findOneById(Uuid $id): ?QuestionRevision
    {
        return $this->find($id);
    }

    /**
     * @param list<Question> $questions
     *
     * @return array<string, QuestionRevision>
     */
    public function findCurrentForQuestions(array $questions): array
    {
        if ([] === $questions) {
            return [];
        }
        $qb = $this->createQueryBuilder('revision');
        $or = $qb->expr()->orX();
        foreach ($questions as $index => $question) {
            $or->add($qb->expr()->andX(
                'IDENTITY(revision.question) = :question'.$index,
                'revision.revisionNumber = :number'.$index,
            ));
            $qb->setParameter('question'.$index, $question->getId(), 'uuid');
            $qb->setParameter('number'.$index, $question->getCurrentRevisionNumber());
        }

        /** @var list<QuestionRevision> $rows */
        $rows = $qb->innerJoin('revision.question', 'question')->addSelect('question')->andWhere($or)->getQuery()->getResult();
        $map = [];
        foreach ($rows as $revision) {
            $map[$revision->getQuestion()->getId()->toRfc4122()] = $revision;
        }

        return $map;
    }

    public function findForQuestionNumber(Question $question, int $revisionNumber): ?QuestionRevision
    {
        /** @var QuestionRevision|null $row */
        $row = $this->createQueryBuilder('r')
            ->andWhere('r.question = :question')
            ->andWhere('r.revisionNumber = :number')
            ->setParameter('question', $question->getId(), 'uuid')
            ->setParameter('number', $revisionNumber)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    public function findLatestForQuestion(Question $question): ?QuestionRevision
    {
        /** @var QuestionRevision|null $row */
        $row = $this->createQueryBuilder('r')
            ->andWhere('r.question = :question')
            ->setParameter('question', $question->getId(), 'uuid')
            ->orderBy('r.revisionNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    public function save(QuestionRevision $revision, bool $flush = true): void
    {
        $this->getEntityManager()->persist($revision);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
