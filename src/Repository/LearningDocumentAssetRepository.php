<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContent;
use App\Entity\LearningDocumentAsset;
use App\Entity\User;
use App\Enum\LearningContentScope;
use App\Enum\LearningDocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningDocumentAsset>
 */
class LearningDocumentAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningDocumentAsset::class);
    }

    public function save(LearningDocumentAsset $asset, bool $flush = true): void
    {
        $this->getEntityManager()->persist($asset);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, LearningDocumentAsset>
     */
    public function findMappedByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('d');
        $clauses = [];
        foreach ($ids as $index => $id) {
            $name = 'id'.$index;
            $clauses[] = 'd.id = :'.$name;
            $qb->setParameter($name, $id, 'uuid');
        }
        /** @var list<LearningDocumentAsset> $rows */
        $rows = $qb->andWhere(implode(' OR ', $clauses))->getQuery()->getResult();
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[$row->getId()->toRfc4122()] = $row;
        }

        return $mapped;
    }

    /**
     * @return list<LearningDocumentAsset>
     */
    public function findCandidates(User $actor, bool $includePlatformPending): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('statuses', [LearningDocumentStatus::Pending, LearningDocumentStatus::Ready])
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(100);
        if (!$includePlatformPending) {
            $qb->andWhere('d.createdBy = :actor')->setParameter('actor', $actor->getId(), 'uuid');
        }

        /** @var list<LearningDocumentAsset> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function countPlatformLinkedForAdminDocuments(): int
    {
        return \count($this->platformLinkedInventoryRows());
    }

    /**
     * Platform LearningContent current-revision document blocks only.
     *
     * @return list<array{
     *     content_id: string,
     *     content_title: string,
     *     media_type: string,
     *     byte_size: int,
     *     status: string
     * }>
     */
    public function listPlatformLinkedForAdminDocuments(int $offset, int $limit): array
    {
        $rows = $this->platformLinkedInventoryRows();
        if ($offset < 0) {
            $offset = 0;
        }
        if ($limit < 1) {
            return [];
        }

        return \array_slice($rows, $offset, $limit);
    }

    /**
     * Two queries: platform contents with current revision, then batch asset hydrate.
     *
     * @return list<array{
     *     content_id: string,
     *     content_title: string,
     *     media_type: string,
     *     byte_size: int,
     *     status: string
     * }>
     */
    private function platformLinkedInventoryRows(): array
    {
        /** @var list<LearningContent> $contents */
        $contents = $this->getEntityManager()->createQueryBuilder()
            ->select('content', 'revision')
            ->from(LearningContent::class, 'content')
            ->innerJoin('content.currentRevision', 'revision')
            ->andWhere('content.scope = :scope')
            ->setParameter('scope', LearningContentScope::Platform)
            ->orderBy('content.updatedAt', 'DESC')
            ->addOrderBy('content.id', 'DESC')
            ->getQuery()
            ->getResult();

        /** @var list<array{content: LearningContent, asset_id: string}> $pairs */
        $pairs = [];
        /** @var list<Uuid> $assetIds */
        $assetIds = [];
        foreach ($contents as $content) {
            $revision = $content->getCurrentRevision();
            if (null === $revision) {
                continue;
            }
            $blocks = $revision->getStructuredContent()['blocks'] ?? [];
            if (!\is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $block) {
                if (!\is_array($block) || 'document' !== ($block['type'] ?? null)) {
                    continue;
                }
                $assetId = $block['assetId'] ?? null;
                if (!\is_string($assetId) || !Uuid::isValid($assetId)) {
                    continue;
                }
                $pairs[] = ['content' => $content, 'asset_id' => $assetId];
                $assetIds[] = Uuid::fromString($assetId);
            }
        }

        $assets = $this->findMappedByIds($assetIds);
        $rows = [];
        foreach ($pairs as $pair) {
            $asset = $assets[$pair['asset_id']] ?? null;
            if (!$asset instanceof LearningDocumentAsset) {
                continue;
            }
            $title = trim($pair['content']->getTitle());
            $rows[] = [
                'content_id' => $pair['content']->getId()->toRfc4122(),
                'content_title' => '' !== $title ? $title : 'Başlıksız içerik',
                'media_type' => $asset->getMediaType(),
                'byte_size' => $asset->getByteSize(),
                'status' => $asset->getStatus()->value,
            ];
        }

        return $rows;
    }
}
