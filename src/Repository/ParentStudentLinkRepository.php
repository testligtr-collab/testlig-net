<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ParentStudentLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ParentStudentLink>
 */
class ParentStudentLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParentStudentLink::class);
    }

    public function findOneById(Uuid $id): ?ParentStudentLink
    {
        return $this->find($id);
    }

    public function save(ParentStudentLink $link, bool $flush = true): void
    {
        $this->getEntityManager()->persist($link);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
