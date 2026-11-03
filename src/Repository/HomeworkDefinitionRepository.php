<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HomeworkDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<HomeworkDefinition>
 */
class HomeworkDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HomeworkDefinition::class);
    }

    public function findOneById(Uuid $id): ?HomeworkDefinition
    {
        $definition = $this->find($id);

        return $definition instanceof HomeworkDefinition ? $definition : null;
    }
}
