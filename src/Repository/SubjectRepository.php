<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Subject>
 */
class SubjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subject::class);
    }

    public function findOneById(Uuid $id): ?Subject
    {
        return $this->find($id);
    }

    public function findOneByCode(string $code): ?Subject
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function existsWithCode(string $code): bool
    {
        return null !== $this->findOneByCode($code);
    }

    public function existsWithSlug(string $slug): bool
    {
        return null !== $this->findOneBy(['slug' => $slug]);
    }

    public function save(Subject $subject, bool $flush = true): void
    {
        $this->getEntityManager()->persist($subject);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
