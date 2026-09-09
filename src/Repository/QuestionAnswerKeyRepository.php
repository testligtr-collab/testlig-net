<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionAnswerKey;
use App\Entity\QuestionRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Authorized access only — does not provide general listing APIs.
 *
 * @extends ServiceEntityRepository<QuestionAnswerKey>
 */
class QuestionAnswerKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionAnswerKey::class);
    }

    public function findOneById(Uuid $id): ?QuestionAnswerKey
    {
        return $this->find($id);
    }

    public function findOneByRevision(QuestionRevision $revision): ?QuestionAnswerKey
    {
        /** @var QuestionAnswerKey|null $row */
        $row = $this->createQueryBuilder('k')
            ->andWhere('k.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * @return list<QuestionAnswerKey>
     */
    public function findAll(): array
    {
        throw new \LogicException('Answer keys must not be listed.');
    }

    /**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     *
     * @return list<QuestionAnswerKey>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        throw new \LogicException('Answer keys must not be listed.');
    }

    public function save(QuestionAnswerKey $answerKey, bool $flush = true): void
    {
        $this->getEntityManager()->persist($answerKey);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
