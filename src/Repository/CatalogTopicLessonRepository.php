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
            ->andWhere('l.catalogTopic = :topic')
            ->andWhere('l.slug = :slug')
            ->setParameter('topic', $topic)
            ->setParameter('slug', $slug);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('l.id != :except')->setParameter('except', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function existsPositionForTopic(CatalogTopic $topic, int $position, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.catalogTopic = :topic')
            ->andWhere('l.position = :position')
            ->setParameter('topic', $topic)
            ->setParameter('position', $position);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('l.id != :except')->setParameter('except', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function existsContentForTopic(CatalogTopic $topic, LearningContent $content, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.catalogTopic = :topic')
            ->andWhere('l.learningContent = :content')
            ->setParameter('topic', $topic)
            ->setParameter('content', $content);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('l.id != :except')->setParameter('except', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @return list<CatalogTopicLesson>
     */
    public function findPublishedOrderedByTopic(CatalogTopic $topic): array
    {
        /** @var list<CatalogTopicLesson> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('l.catalogTopic = :topic')
            ->andWhere('l.visibilityStatus = :status')
            ->setParameter('topic', $topic)
            ->setParameter('status', CatalogPublicationStatus::Published)
            ->orderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
