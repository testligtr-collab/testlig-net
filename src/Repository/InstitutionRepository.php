<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Institution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Institution>
 */
class InstitutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Institution::class);
    }

    public function findOneById(Uuid $id): ?Institution
    {
        return $this->find($id);
    }

    public function findOneBySlug(string $slug): ?Institution
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function existsWithSlug(string $slug): bool
    {
        return null !== $this->findOneBySlug($slug);
    }

    public function save(Institution $institution, bool $flush = true): void
    {
        $this->getEntityManager()->persist($institution);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
