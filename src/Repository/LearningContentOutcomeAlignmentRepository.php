<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContentOutcomeAlignment;
use App\Entity\LearningContentRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningContentOutcomeAlignment>
 */
class LearningContentOutcomeAlignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContentOutcomeAlignment::class);
    }

    public function findOneById(Uuid $id): ?LearningContentOutcomeAlignment
    {
        return $this->find($id);
    }

    /**
     * @return list<LearningContentOutcomeAlignment>
     */
    public function findByRevision(LearningContentRevision $revision): array
    {
        /** @var list<LearningContentOutcomeAlignment> $rows */
        $rows = $this->findBy(['revision' => $revision], ['position' => 'ASC']);

        return $rows;
    }

    /**
     * One query for the admin detail panel. Hierarchy is joined so the page does not query per row.
     *
     * @return list<LearningContentOutcomeAlignment>
     */
    public function findDetailedByRevision(LearningContentRevision $revision): array
    {
        /** @var list<LearningContentOutcomeAlignment> $rows */
        $rows = $this->createQueryBuilder('alignment')
            ->addSelect('program', 'programSubject', 'outcome', 'topic', 'unit')
            ->innerJoin('alignment.curriculumProgram', 'program')
            ->innerJoin('program.subject', 'programSubject')
            ->innerJoin('alignment.learningOutcome', 'outcome')
            ->innerJoin('alignment.curriculumTopic', 'topic')
            ->innerJoin('topic.unit', 'unit')
            ->andWhere('alignment.revision = :revision')
            ->setParameter('revision', $revision)
            ->orderBy('alignment.position', 'ASC')
            ->addOrderBy('alignment.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(LearningContentOutcomeAlignment $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(LearningContentOutcomeAlignment $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
