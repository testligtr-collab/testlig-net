<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HomeworkRecipient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HomeworkRecipient>
 */
final class HomeworkRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HomeworkRecipient::class);
    }
}
