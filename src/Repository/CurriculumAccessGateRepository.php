<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CurriculumAccessGate;
use App\Enum\CurriculumAccessState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CurriculumAccessGate>
 */
final class CurriculumAccessGateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CurriculumAccessGate::class);
    }

    public function isOpen(string $kind, string $gateKey): bool
    {
        $gate = $this->findOne($kind, $gateKey);

        return $gate instanceof CurriculumAccessGate && CurriculumAccessState::Active === $gate->getStatus();
    }

    public function findOne(string $kind, string $gateKey): ?CurriculumAccessGate
    {
        $gate = $this->findOneBy(['kind' => $kind, 'gateKey' => $gateKey]);

        return $gate instanceof CurriculumAccessGate ? $gate : null;
    }

    /**
     * @return list<CurriculumAccessGate>
     */
    public function findOrdered(): array
    {
        /** @var list<CurriculumAccessGate> $rows */
        $rows = $this->createQueryBuilder('g')
            ->getQuery()
            ->getResult();
        usort($rows, static function (CurriculumAccessGate $left, CurriculumAccessGate $right): int {
            $kind = ('stage' === $left->getKind() ? 0 : 1) <=> ('stage' === $right->getKind() ? 0 : 1);
            if (0 !== $kind) {
                return $kind;
            }
            if ('grade' === $left->getKind()) {
                return (int) $left->getGateKey() <=> (int) $right->getGateKey();
            }
            $order = ['ilkokul' => 1, 'ortaokul' => 2, 'lise' => 3];

            return ($order[$left->getGateKey()] ?? 9) <=> ($order[$right->getGateKey()] ?? 9);
        });

        return $rows;
    }

    public function save(CurriculumAccessGate $gate, bool $flush = true): void
    {
        $this->getEntityManager()->persist($gate);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
