<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HomeworkSubmission;
use App\Entity\HomeworkSubmissionAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HomeworkSubmissionAttachment>
 */
final class HomeworkSubmissionAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HomeworkSubmissionAttachment::class);
    }

    public function findForSubmission(HomeworkSubmission $submission): ?HomeworkSubmissionAttachment
    {
        return $this->findOneBy(['submission' => $submission]);
    }
}
