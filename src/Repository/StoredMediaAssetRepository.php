<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StoredMediaAsset;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaStorageProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<StoredMediaAsset>
 */
class StoredMediaAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoredMediaAsset::class);
    }

    public function findOneById(Uuid $id): ?StoredMediaAsset
    {
        return $this->find($id);
    }

    public function save(StoredMediaAsset $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Platform inventory only. Institution rows stay out of COUNT and SELECT.
     */
    public function countPlatformInventory(): int
    {
        return (int) $this->createQueryBuilder('asset')
            ->select('COUNT(asset.id)')
            ->andWhere('asset.scope = :scope')
            ->setParameter('scope', StoredMediaAssetScope::Platform)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Safe scalar fields for the SuperAdmin inventory screen.
     *
     * @return list<array{kind: string, byte_size: int, storage_provider: string, status: string}>
     */
    public function listPlatformInventory(int $offset, int $limit): array
    {
        /** @var list<array{kind: mixed, byte_size: mixed, storage_provider: mixed, status: mixed}> $rows */
        $rows = $this->createQueryBuilder('asset')
            ->select('asset.kind AS kind')
            ->addSelect('asset.byteSize AS byte_size')
            ->addSelect('asset.storageProvider AS storage_provider')
            ->addSelect('asset.status AS status')
            ->andWhere('asset.scope = :scope')
            ->setParameter('scope', StoredMediaAssetScope::Platform)
            ->orderBy('asset.createdAt', 'DESC')
            ->addOrderBy('asset.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $kind = $this->normalizeKind($row['kind'] ?? null);
            $provider = $this->normalizeStorageProvider($row['storage_provider'] ?? null);
            $status = $this->normalizeStatus($row['status'] ?? null);
            $byteSize = $this->normalizeByteSize($row['byte_size'] ?? null);
            if (null === $kind || null === $provider || null === $status || null === $byteSize) {
                continue;
            }
            $out[] = [
                'kind' => $kind,
                'byte_size' => $byteSize,
                'storage_provider' => $provider,
                'status' => $status,
            ];
        }

        return $out;
    }

    private function normalizeKind(mixed $value): ?string
    {
        if ($value instanceof StoredMediaAssetKind) {
            return $value->value;
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return StoredMediaAssetKind::tryFrom($value)?->value;
    }

    private function normalizeStorageProvider(mixed $value): ?string
    {
        if ($value instanceof StoredMediaStorageProvider) {
            return $value->value;
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return StoredMediaStorageProvider::tryFrom($value)?->value;
    }

    private function normalizeStatus(mixed $value): ?string
    {
        if ($value instanceof StoredMediaAssetStatus) {
            return $value->value;
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return StoredMediaAssetStatus::tryFrom($value)?->value;
    }

    private function normalizeByteSize(mixed $value): ?int
    {
        if (\is_int($value)) {
            return max(0, $value);
        }
        if (\is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return null;
    }
}
