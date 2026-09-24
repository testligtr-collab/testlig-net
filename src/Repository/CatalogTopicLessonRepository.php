<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicLesson;
use App\Entity\LearningContent;
use App\Enum\CatalogPublicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CatalogTopicLesson>
 */
class CatalogTopicLessonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogTopicLesson::class);
    }

    public function findOneById(Uuid $id): ?CatalogTopicLesson
    {
        $entity = $this->find($id);

        return $entity instanceof CatalogTopicLesson ? $entity : null;
    }

    public function save(CatalogTopicLesson $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function existsSlugForTopic(CatalogTopic $topic, string $slug, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('IDENTITY(l.catalogTopic) = :topicId')
            ->andWhere('l.slug = :slug')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->setParameter('slug', $slug);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('l.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function existsPositionForTopic(CatalogTopic $topic, int $position, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('IDENTITY(l.catalogTopic) = :topicId')
            ->andWhere('l.position = :position')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->setParameter('position', $position);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('l.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function existsContentForTopic(CatalogTopic $topic, LearningContent $content, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('IDENTITY(l.catalogTopic) = :topicId')
            ->andWhere('IDENTITY(l.learningContent) = :contentId')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->setParameter('contentId', $content->getId(), 'uuid');
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('l.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @return list<CatalogTopicLesson>
     */
    public function findOrderedByTopic(CatalogTopic $topic): array
    {
        /** @var list<CatalogTopicLesson> $rows */
        $rows = $this->createQueryBuilder('l')
            ->addSelect('c')
            ->innerJoin('l.learningContent', 'c')
            ->andWhere('IDENTITY(l.catalogTopic) = :topicId')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->orderBy('l.position', 'ASC')
            ->addOrderBy('l.displayTitle', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CatalogTopicLesson>
     */
    public function findOrderedByLearningContent(LearningContent $content): array
    {
        /** @var list<CatalogTopicLesson> $rows */
        $rows = $this->createQueryBuilder('l')
            ->addSelect('t', 'u', 's')
            ->innerJoin('l.catalogTopic', 't')
            ->innerJoin('t.unit', 'u')
            ->innerJoin('u.subject', 's')
            ->andWhere('IDENTITY(l.learningContent) = :contentId')
            ->setParameter('contentId', $content->getId(), 'uuid')
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('t.position', 'ASC')
            ->addOrderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CatalogTopicLesson>
     */
    public function findPublishedOrderedByTopic(CatalogTopic $topic): array
    {
        /** @var list<CatalogTopicLesson> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('IDENTITY(l.catalogTopic) = :topicId')
            ->andWhere('l.visibilityStatus = :status')
            ->setParameter('topicId', $topic->getId(), 'uuid')
            ->setParameter('status', CatalogPublicationStatus::Published)
            ->orderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function existsForCatalogSubject(Uuid $catalogSubjectId): bool
    {
        $count = (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->innerJoin('l.catalogTopic', 't')
            ->innerJoin('t.unit', 'u')
            ->andWhere('IDENTITY(u.subject) = :subjectId')
            ->setParameter('subjectId', $catalogSubjectId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
