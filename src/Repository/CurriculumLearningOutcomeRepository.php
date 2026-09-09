<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CurriculumLearningOutcome>
 */
class CurriculumLearningOutcomeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CurriculumLearningOutcome::class);
    }

    public function findOneById(Uuid $id): ?CurriculumLearningOutcome
    {
        return $this->find($id);
    }

    public function existsWithCode(CurriculumProgram $program, string $code): bool
    {
        return null !== $this->createQueryBuilder('o')
            ->select('1')
            ->andWhere('o.curriculumProgram = :program')
            ->andWhere('o.code = :code')
            ->setParameter('program', $program->getId(), 'uuid')
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsWithPosition(CurriculumTopic $topic, int $position): bool
    {
        return null !== $this->createQueryBuilder('o')
            ->select('1')
            ->andWhere('o.topic = :topic')
            ->andWhere('o.position = :position')
            ->setParameter('topic', $topic->getId(), 'uuid')
            ->setParameter('position', $position)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<CurriculumLearningOutcome>
     */
    public function findByTopic(CurriculumTopic $topic): array
    {
        /** @var list<CurriculumLearningOutcome> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.topic = :topic')
            ->setParameter('topic', $topic->getId(), 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CurriculumLearningOutcome>
     */
    public function findByProgram(CurriculumProgram $program): array
    {
        /** @var list<CurriculumLearningOutcome> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.curriculumProgram = :program')
            ->setParameter('program', $program->getId(), 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(CurriculumLearningOutcome $outcome, bool $flush = true): void
    {
        $this->getEntityManager()->persist($outcome);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
