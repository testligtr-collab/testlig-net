<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<QuestionRevisionOption>
 */
class QuestionRevisionOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionRevisionOption::class);
    }

    /**
     * @return list<QuestionRevisionOption>
     */
    public function findByRevision(QuestionRevision $revision): array
    {
        /** @var list<QuestionRevisionOption> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Option documents for the pinned revisions, ordered by position.
     * stableKey is not selected.
     *
     * @param list<string> $revisionIds
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function findPreviewContents(array $revisionIds): array
    {
        $ids = [];
        foreach ($revisionIds as $revisionId) {
            if (Uuid::isValid($revisionId)) {
                $ids[$revisionId] = Uuid::fromString($revisionId);
            }
        }
        if ([] === $ids) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('option')
            ->select('IDENTITY(option.revision) AS revisionId')
            ->addSelect('option.position AS position')
            ->addSelect('option.content AS content')
            ->andWhere('option.revision IN (:ids)')
            ->setParameter('ids', array_values($ids))
            ->orderBy('option.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            $key = self::revisionKey($row['revisionId'] ?? null);
            if (null === $key) {
                continue;
            }
            $content = $row['content'] ?? null;
            if (\is_string($content)) {
                $decoded = json_decode($content, true);
                $content = \is_array($decoded) ? $decoded : null;
            }
            if (!\is_array($content)) {
                continue;
            }
            $document = [];
            foreach ($content as $field => $value) {
                if (\is_string($field)) {
                    $document[$field] = $value;
                }
            }
            $grouped[$key][] = $document;
        }

        return $grouped;
    }

    private static function revisionKey(mixed $value): ?string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        if (Uuid::isValid($value)) {
            return Uuid::fromString($value)->toRfc4122();
        }
        if (1 === preg_match('/^[0-9a-f]{32}$/i', $value)) {
            $hex = strtolower($value);
            $canonical = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);

            return Uuid::isValid($canonical) ? $canonical : null;
        }
        if (16 === \strlen($value)) {
            try {
                return Uuid::fromBinary($value)->toRfc4122();
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }

    public function existsForRevisionAndStableKey(Uuid $revisionId, string $stableKey): bool
    {
        return null !== $this->createQueryBuilder('o')
            ->select('1')
            ->andWhere('o.revision = :revisionId')
            ->andWhere('o.stableKey = :stableKey')
            ->setParameter('revisionId', $revisionId, 'uuid')
            ->setParameter('stableKey', $stableKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<string>
     */
    public function listStableKeysForRevisionOrdered(Uuid $revisionId): array
    {
        /** @var list<string> $keys */
        $keys = $this->createQueryBuilder('o')
            ->select('o.stableKey')
            ->andWhere('o.revision = :revisionId')
            ->setParameter('revisionId', $revisionId, 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $keys;
    }

    public function save(QuestionRevisionOption $option, bool $flush = true): void
    {
        $this->getEntityManager()->persist($option);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
