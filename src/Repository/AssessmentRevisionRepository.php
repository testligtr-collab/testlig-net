<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assessment;
use App\Entity\AssessmentRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssessmentRevision>
 */
class AssessmentRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentRevision::class);
    }

    public function findForAssessmentNumber(Assessment $assessment, int $revisionNumber): ?AssessmentRevision
    {
        /** @var AssessmentRevision|null $row */
        $row = $this->findOneBy([
            'assessment' => $assessment,
            'revisionNumber' => $revisionNumber,
        ]);

        return $row;
    }

    public function save(AssessmentRevision $revision, bool $flush = true): void
    {
        $this->getEntityManager()->persist($revision);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
