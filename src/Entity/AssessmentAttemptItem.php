<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\AssessmentAttemptException;
use App\Repository\AssessmentAttemptItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable per-attempt presentation snapshot of a published assessment item.
 *
 * Never stores answer keys or answer-key HMAC. Order and public hash are fixed at create.
 */
#[ORM\Entity(repositoryClass: AssessmentAttemptItemRepository::class)]
#[ORM\Table(name: 'assessment_attempt_items')]
#[ORM\UniqueConstraint(name: 'uniq_aai_attempt_presentation', columns: ['attempt_id', 'presentation_position'])]
#[ORM\UniqueConstraint(name: 'uniq_aai_attempt_assessment_item', columns: ['attempt_id', 'assessment_item_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aai_id_attempt', columns: ['id', 'attempt_id'])]
#[ORM\Index(name: 'idx_aai_attempt', columns: ['attempt_id'])]
#[ORM\Index(name: 'idx_aai_question_revision', columns: ['question_revision_id'])]
class AssessmentAttemptItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttempt $attempt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_section_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentSection $assessmentSection;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentItem $assessmentItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Question $question;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'question_revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private QuestionRevision $questionRevision;

    #[ORM\Column(name: 'section_position')]
    private int $sectionPosition;

    #[ORM\Column(name: 'item_position')]
    private int $itemPosition;

    #[ORM\Column(name: 'presentation_position')]
    private int $presentationPosition;

    /** @var list<string>|null */
    #[ORM\Column(name: 'option_order_json', type: Types::JSON, nullable: true)]
    private ?array $optionOrderJson;

    #[ORM\Column]
    private bool $required;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $points;

    #[ORM\Column(name: 'penalty_points', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $penaltyPoints;

    #[ORM\Column(name: 'public_content_hash', length: 64)]
    private string $publicContentHash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string>|null $optionOrderJson
     */
    private function __construct(
        AssessmentAttempt $attempt,
        AssessmentSection $assessmentSection,
        AssessmentItem $assessmentItem,
        Question $question,
        QuestionRevision $questionRevision,
        int $sectionPosition,
        int $itemPosition,
        int $presentationPosition,
        ?array $optionOrderJson,
        bool $required,
        string $points,
        string $penaltyPoints,
        string $publicContentHash,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($sectionPosition < 1 || $itemPosition < 1 || $presentationPosition < 1) {
            throw AssessmentAttemptException::invalidInput('Attempt item positions must be >= 1.');
        }
        if (64 !== \strlen($publicContentHash)) {
            throw AssessmentAttemptException::invalidInput('publicContentHash must be 64 characters.');
        }
        if (!$questionRevision->getQuestion()->getId()->equals($question->getId())) {
            throw AssessmentAttemptException::scopeMismatch();
        }
        if (!$assessmentItem->getQuestion()->getId()->equals($question->getId())
            || !$assessmentItem->getQuestionRevision()->getId()->equals($questionRevision->getId())
        ) {
            throw AssessmentAttemptException::scopeMismatch();
        }
        if (!$assessmentItem->getSection()->getId()->equals($assessmentSection->getId())) {
            throw AssessmentAttemptException::scopeMismatch();
        }
        $this->assertOptionOrderJson($optionOrderJson);

        $this->id = $id ?? new UuidV7();
        $this->attempt = $attempt;
        $this->assessmentSection = $assessmentSection;
        $this->assessmentItem = $assessmentItem;
        $this->question = $question;
        $this->questionRevision = $questionRevision;
        $this->sectionPosition = $sectionPosition;
        $this->itemPosition = $itemPosition;
        $this->presentationPosition = $presentationPosition;
        $this->optionOrderJson = $optionOrderJson;
        $this->required = $required;
        $this->points = $points;
        $this->penaltyPoints = $penaltyPoints;
        $this->publicContentHash = $publicContentHash;
        $this->createdAt = $now;
    }

    /**
     * @param list<string>|null $optionOrderJson
     *
     * @internal prefer AssessmentAttemptManager
     */
    public static function create(
        AssessmentAttempt $attempt,
        AssessmentSection $assessmentSection,
        AssessmentItem $assessmentItem,
        Question $question,
        QuestionRevision $questionRevision,
        int $sectionPosition,
        int $itemPosition,
        int $presentationPosition,
        ?array $optionOrderJson,
        bool $required,
        string $points,
        string $penaltyPoints,
        string $publicContentHash,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $attempt,
            $assessmentSection,
            $assessmentItem,
            $question,
            $questionRevision,
            $sectionPosition,
            $itemPosition,
            $presentationPosition,
            $optionOrderJson,
            $required,
            $points,
            $penaltyPoints,
            $publicContentHash,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getAttempt(): AssessmentAttempt
    {
        return $this->attempt;
    }

    #[Ignore]
    public function getAssessmentSection(): AssessmentSection
    {
        return $this->assessmentSection;
    }

    #[Ignore]
    public function getAssessmentItem(): AssessmentItem
    {
        return $this->assessmentItem;
    }

    #[Ignore]
    public function getQuestion(): Question
    {
        return $this->question;
    }

    #[Ignore]
    public function getQuestionRevision(): QuestionRevision
    {
        return $this->questionRevision;
    }

    public function getSectionPosition(): int
    {
        return $this->sectionPosition;
    }

    public function getItemPosition(): int
    {
        return $this->itemPosition;
    }

    public function getPresentationPosition(): int
    {
        return $this->presentationPosition;
    }

    /**
     * @return list<string>|null
     */
    public function getOptionOrderJson(): ?array
    {
        return $this->optionOrderJson;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getPoints(): string
    {
        return $this->points;
    }

    public function getPenaltyPoints(): string
    {
        return $this->penaltyPoints;
    }

    public function getPublicContentHash(): string
    {
        return $this->publicContentHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param list<string>|null $optionOrderJson
     */
    private function assertOptionOrderJson(?array $optionOrderJson): void
    {
        if (null === $optionOrderJson) {
            return;
        }

        $i = 0;
        foreach ($optionOrderJson as $key => $stableKey) {
            if ($key !== $i || '' === $stableKey) {
                throw AssessmentAttemptException::invalidInput('optionOrderJson must be a list of non-empty stable keys.');
            }
            ++$i;
        }
    }
}
