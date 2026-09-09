<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\QuestionException;
use App\Repository\QuestionRevisionOptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable option row for a choice-type revision. stableKey is opaque (not correctness).
 */
#[ORM\Entity(repositoryClass: QuestionRevisionOptionRepository::class)]
#[ORM\Table(name: 'question_revision_options')]
#[ORM\UniqueConstraint(name: 'uniq_qro_revision_stable_key', columns: ['revision_id', 'stable_key'])]
#[ORM\UniqueConstraint(name: 'uniq_qro_revision_position', columns: ['revision_id', 'position'])]
#[ORM\Index(name: 'idx_qro_revision', columns: ['revision_id'])]
class QuestionRevisionOption
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionRevision $revision;

    #[ORM\Column(name: 'stable_key', length: 32)]
    private string $stableKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $content;

    #[ORM\Column]
    private int $position;

    /**
     * @param array<string, mixed> $content
     */
    private function __construct(
        QuestionRevision $revision,
        string $stableKey,
        array $content,
        int $position,
        ?Uuid $id = null,
    ) {
        if ($position < 1) {
            throw QuestionException::invalidInput('Option position must be >= 1.');
        }
        if (1 !== preg_match('/^[a-z0-9_]{2,32}$/', $stableKey)) {
            throw QuestionException::invalidInput('Option stableKey is invalid.');
        }

        $this->id = $id ?? new UuidV7();
        $this->revision = $revision;
        $this->stableKey = $stableKey;
        $this->content = $content;
        $this->position = $position;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @internal prefer QuestionManager
     */
    public static function create(
        QuestionRevision $revision,
        string $stableKey,
        array $content,
        int $position,
        ?Uuid $id = null,
    ): self {
        return new self($revision, $stableKey, $content, $position, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getRevision(): QuestionRevision
    {
        return $this->revision;
    }

    public function getStableKey(): string
    {
        return $this->stableKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
