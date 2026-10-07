<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assessment;
use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicAssessment;
use App\Enum\CatalogPublicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CatalogTopicAssessment>
 */
class CatalogTopicAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogTopicAssessment::class);
    }

    public function findOneById(Uuid $id): ?CatalogTopicAssessment
    {
        $entity = $this->find($id);

        return $entity instanceof CatalogTopicAssessment ? $entity : null;
    }

    public function save(CatalogTopicAssessment $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function existsSlugForTopic(CatalogTopic $topic, string $slug, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('IDENTITY(a.catalogTopic) = :topicId')
            ->andWhere('a.slug = :slug')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->setParameter('slug', $slug);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('a.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function existsPositionForTopic(CatalogTopic $topic, int $position, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('IDENTITY(a.catalogTopic) = :topicId')
            ->andWhere('a.position = :position')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->setParameter('position', $position);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('a.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function highestPositionForTopic(Uuid $topicId): ?int
    {
        $max = $this->createQueryBuilder('a')
            ->select('MAX(a.position)')
            ->andWhere('IDENTITY(a.catalogTopic) = :topicId')
            ->setParameter('topicId', $topicId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? null : (int) $max;
    }

    public function existsAssessmentForTopic(CatalogTopic $topic, Assessment $assessment, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('IDENTITY(a.catalogTopic) = :topicId')
            ->andWhere('IDENTITY(a.assessment) = :assessmentId')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->setParameter('assessmentId', $assessment->getId(), 'uuid');
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('a.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @return list<CatalogTopicAssessment>
     */
    public function findOrderedByTopic(CatalogTopic $topic): array
    {
        /** @var list<CatalogTopicAssessment> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('asmt')
            ->innerJoin('a.assessment', 'asmt')
            ->andWhere('IDENTITY(a.catalogTopic) = :topicId')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->orderBy('a.position', 'ASC')
            ->addOrderBy('a.displayTitle', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CatalogTopicAssessment>
     */
    public function findOrderedByAssessment(Assessment $assessment): array
    {
        /** @var list<CatalogTopicAssessment> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('t', 'u', 's')
            ->innerJoin('a.catalogTopic', 't')
            ->innerJoin('t.unit', 'u')
            ->innerJoin('u.subject', 's')
            ->andWhere('IDENTITY(a.assessment) = :assessmentId')
            ->setParameter('assessmentId', $assessment->getId(), 'uuid')
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('t.position', 'ASC')
            ->addOrderBy('a.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CatalogTopicAssessment>
     */
    public function findPublishedOrderedByTopic(CatalogTopic $topic): array
    {
        /** @var list<CatalogTopicAssessment> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('asmt', 'pubRev', 'subj')
            ->innerJoin('a.assessment', 'asmt')
            ->leftJoin('asmt.publishedRevision', 'pubRev')
            ->leftJoin('asmt.subject', 'subj')
            ->andWhere('IDENTITY(a.catalogTopic) = :topicId')
            ->andWhere('a.visibilityStatus = :status')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->setParameter('status', CatalogPublicationStatus::Published)
            ->orderBy('a.position', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function existsForCatalogSubject(Uuid $catalogSubjectId): bool
    {
        $count = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->innerJoin('a.catalogTopic', 't')
            ->innerJoin('t.unit', 'u')
            ->andWhere('IDENTITY(u.subject) = :subjectId')
            ->setParameter('subjectId', $catalogSubjectId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
