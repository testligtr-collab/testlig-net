<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HomeworkEvaluationLabel;
use App\Repository\HomeworkEvaluationRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable teacher evaluation of a submitted homework. Prefer HomeworkEvaluationManager.
 *
 * @internal
 */
#[ORM\Entity(repositoryClass: HomeworkEvaluationRepository::class)]
#[ORM\Table(name: 'homework_evaluations')]
#[ORM\UniqueConstraint(name: 'uniq_hw_eval_id_submission', columns: ['id', 'submission_id'])]
#[ORM\Index(name: 'idx_hw_eval_submission', columns: ['submission_id'])]
#[ORM\Index(name: 'idx_hw_eval_evaluator', columns: ['evaluated_by_user_id'])]
#[ORM\Index(name: 'idx_hw_eval_membership', columns: ['evaluated_by_membership_id'])]
#[ORM\Index(name: 'idx_hw_eval_supersedes', columns: ['supersedes_evaluation_id'])]
class HomeworkEvaluation
{
    public const FEEDBACK_MAX = 5000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'submission_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private HomeworkSubmission $submission;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'evaluated_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $evaluatedByUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'evaluated_by_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InstitutionMembership $evaluatedByMembership;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'supersedes_evaluation_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?HomeworkEvaluation $supersedes = null;

    #[ORM\Column(length: 32, enumType: HomeworkEvaluationLabel::class)]
    private HomeworkEvaluationLabel $label;

    #[ORM\Column(type: Types::TEXT, length: 16777215, nullable: true)]
    private ?string $feedback;

    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE, options: ['comment' => 'UTC'])]
    private \DateTimeImmutable $recordedAt;

    private function __construct(
        HomeworkSubmission $submission,
        User $evaluatedByUser,
        InstitutionMembership $evaluatedByMembership,
        HomeworkEvaluationLabel $label,
        ?string $feedback,
        ?HomeworkEvaluation $supersedes,
        \DateTimeImmutable $now,
    ) {
        $this->id = new UuidV7();
        $this->submission = $submission;
        $this->evaluatedByUser = $evaluatedByUser;
        $this->evaluatedByMembership = $evaluatedByMembership;
        $this->label = $label;
        $this->feedback = $feedback;
        $this->supersedes = $supersedes;
        $this->recordedAt = UtcInstant::ensure($now);
    }

    /**
     * @internal prefer HomeworkEvaluationManager
     */
    public static function record(
        HomeworkSubmission $submission,
        User $evaluatedByUser,
        InstitutionMembership $evaluatedByMembership,
        HomeworkEvaluationLabel $label,
        ?string $feedback,
        ?HomeworkEvaluation $supersedes,
        \DateTimeImmutable $now,
    ): self {
        return new self($submission, $evaluatedByUser, $evaluatedByMembership, $label, $feedback, $supersedes, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getSubmission(): HomeworkSubmission
    {
        return $this->submission;
    }

    #[Ignore]
    public function getEvaluatedByUser(): User
    {
        return $this->evaluatedByUser;
    }

    #[Ignore]
    public function getEvaluatedByMembership(): InstitutionMembership
    {
        return $this->evaluatedByMembership;
    }

    #[Ignore]
    public function getSupersedes(): ?HomeworkEvaluation
    {
        return $this->supersedes;
    }

    public function getLabel(): HomeworkEvaluationLabel
    {
        return $this->label;
    }

    public function getFeedback(): ?string
    {
        return $this->feedback;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->recordedAt);
    }
}
