<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuestionRevisionPrimaryAlignmentGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures at most one primary alignment per question revision.
 */
#[ORM\Entity(repositoryClass: QuestionRevisionPrimaryAlignmentGuardRepository::class)]
#[ORM\Table(name: 'question_revision_primary_alignment_guards')]
#[ORM\UniqueConstraint(name: 'uniq_qrpag_alignment', columns: ['alignment_id'])]
class QuestionRevisionPrimaryAlignmentGuard
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionRevision $revision;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'alignment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionRevisionAlignment $alignment;

    private function __construct(QuestionRevision $revision, QuestionRevisionAlignment $alignment)
    {
        $this->revision = $revision;
        $this->alignment = $alignment;
    }

    /**
     * @internal prefer QuestionManager
     */
    public static function bind(QuestionRevision $revision, QuestionRevisionAlignment $alignment): self
    {
        return new self($revision, $alignment);
    }

    public function getRevisionId(): Uuid
    {
        return $this->revision->getId();
    }

    #[Ignore]
    public function getRevision(): QuestionRevision
    {
        return $this->revision;
    }

    #[Ignore]
    public function getAlignment(): QuestionRevisionAlignment
    {
        return $this->alignment;
    }
}
