<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\LearningContentException;
use App\Repository\LearningContentRevisionPrimaryAlignmentGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures at most one primary alignment per learning content revision.
 */
#[ORM\Entity(repositoryClass: LearningContentRevisionPrimaryAlignmentGuardRepository::class)]
#[ORM\Table(name: 'learning_content_revision_primary_alignment_guards')]
#[ORM\UniqueConstraint(name: 'uniq_lcrpag_alignment', columns: ['alignment_id'])]
class LearningContentRevisionPrimaryAlignmentGuard
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LearningContentRevision $revision;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'alignment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LearningContentOutcomeAlignment $alignment;

    #[ORM\Column(name: 'must_be_primary', options: ['default' => 1])]
    private bool $mustBePrimary = true;

    private function __construct(LearningContentRevision $revision, LearningContentOutcomeAlignment $alignment)
    {
        if (!$alignment->isPrimary()) {
            throw LearningContentException::alignmentInvalid('Primary alignment guard requires is_primary alignment.');
        }
        if (!$alignment->getRevision()->getId()->equals($revision->getId())) {
            throw LearningContentException::alignmentInvalid('Primary alignment guard revision mismatch.');
        }

        $this->revision = $revision;
        $this->alignment = $alignment;
        $this->mustBePrimary = true;
    }

    /**
     * @internal prefer LearningContentManager
     */
    public static function bind(LearningContentRevision $revision, LearningContentOutcomeAlignment $alignment): self
    {
        return new self($revision, $alignment);
    }

    public function getRevisionId(): Uuid
    {
        return $this->revision->getId();
    }

    #[Ignore]
    public function getRevision(): LearningContentRevision
    {
        return $this->revision;
    }

    #[Ignore]
    public function getAlignment(): LearningContentOutcomeAlignment
    {
        return $this->alignment;
    }

    public function mustBePrimary(): bool
    {
        return $this->mustBePrimary;
    }
}
