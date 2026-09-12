<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningContentRevision>
 */
class LearningContentRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContentRevision::class);
    }

    public function findOneById(Uuid $id): ?LearningContentRevision
    {
        return $this->find($id);
    }

    public function findOneByContentAndNumber(LearningContent $content, int $revisionNumber): ?LearningContentRevision
    {
        /** @var LearningContentRevision|null $row */
        $row = $this->findOneBy([
            'content' => $content,
            'revisionNumber' => $revisionNumber,
        ]);

        return $row;
    }

    public function save(LearningContentRevision $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
