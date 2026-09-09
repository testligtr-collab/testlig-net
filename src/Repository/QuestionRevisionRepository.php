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

    public function save(QuestionRevision $revision, bool $flush = true): void
    {
        $this->getEntityManager()->persist($revision);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
