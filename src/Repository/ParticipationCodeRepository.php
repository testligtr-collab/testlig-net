<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ParticipationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ParticipationCode>
 */
class ParticipationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipationCode::class);
    }

    public function findOneById(Uuid $id): ?ParticipationCode
    {
        return $this->find($id);
    }

    public function findOneByCodeDigest(string $codeDigest): ?ParticipationCode
    {
        return $this->findOneBy(['codeDigest' => $codeDigest]);
    }

    public function save(ParticipationCode $code, bool $flush = true): void
    {
        $this->getEntityManager()->persist($code);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
