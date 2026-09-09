<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionType;
use App\Repository\QuestionAnswerKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Isolated answer key for a revision. Loaded only via QuestionAnswerKeyRepository.
 * Never exposed on QuestionRevision public API / serializer graphs.
 */
#[ORM\Entity(repositoryClass: QuestionAnswerKeyRepository::class)]
#[ORM\Table(name: 'question_answer_keys')]
#[ORM\UniqueConstraint(name: 'uniq_qak_revision', columns: ['revision_id'])]
class QuestionAnswerKey
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionRevision $revision;

    #[ORM\Column(name: 'answer_type', length: 32, enumType: QuestionType::class)]
    private QuestionType $answerType;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'answer_payload', type: Types::JSON)]
    #[Ignore]
    private array $answerPayload;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $answerPayload
     */
    private function __construct(
        QuestionRevision $revision,
        QuestionType $answerType,
        array $answerPayload,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->revision = $revision;
        $this->answerType = $answerType;
        $this->answerPayload = $answerPayload;
        $this->createdAt = $now;
    }

    /**
     * @param array<string, mixed> $answerPayload
     *
     * @internal prefer QuestionManager
     */
    public static function create(
        QuestionRevision $revision,
        QuestionType $answerType,
        array $answerPayload,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($revision, $answerType, $answerPayload, $now, $id);
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

    public function getAnswerType(): QuestionType
    {
        return $this->answerType;
    }

    /**
     * @return array<string, mixed>
     */
    #[Ignore]
    public function getAnswerPayload(): array
    {
        return $this->answerPayload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
