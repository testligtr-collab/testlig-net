<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HomeworkAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HomeworkAssignment>
 */
final class HomeworkAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HomeworkAssignment::class);
    }
}
