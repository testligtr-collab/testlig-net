<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StoredMediaAsset;
use App\Enum\StoredMediaAssetScope;
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
     * @return list<array{kind: string, byte_size: int|string, storage_provider: string, status: string}>
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
            $kind = $row['kind'] ?? null;
            $provider = $row['storage_provider'] ?? null;
            $status = $row['status'] ?? null;
            $bytes = $row['byte_size'] ?? 0;
            $out[] = [
                'kind' => $kind instanceof \BackedEnum ? $kind->value : (\is_string($kind) ? $kind : ''),
                'byte_size' => \is_int($bytes) ? $bytes : (int) $bytes,
                'storage_provider' => $provider instanceof \BackedEnum ? $provider->value : (\is_string($provider) ? $provider : ''),
                'status' => $status instanceof \BackedEnum ? $status->value : (\is_string($status) ? $status : ''),
            ];
        }

        return $out;
    }
}
