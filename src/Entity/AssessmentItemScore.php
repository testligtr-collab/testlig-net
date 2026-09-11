<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ItemScoreOutcome;
use App\Enum\ScoringMethod;
use App\Exception\AssessmentScoringException;
use App\Repository\AssessmentItemScoreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Per-item score within one scoring run. Frozen when the parent run is completed.
 */
#[ORM\Entity(repositoryClass: AssessmentItemScoreRepository::class)]
#[ORM\Table(name: 'assessment_item_scores')]
#[ORM\UniqueConstraint(name: 'uniq_ais_run_item', columns: ['scoring_run_id', 'attempt_item_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ais_id_run', columns: ['id', 'scoring_run_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ais_id_run_item', columns: ['id', 'scoring_run_id', 'attempt_item_id'])]
#[ORM\Index(name: 'idx_ais_attempt', columns: ['attempt_id'])]
#[ORM\Index(name: 'idx_ais_run_outcome', columns: ['scoring_run_id', 'outcome'])]
class AssessmentItemScore
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'scoring_run_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentScoringRun $scoringRun;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttempt $attempt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attempt_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttemptItem $attemptItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentRevision $assessmentRevision;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Question $question;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'question_revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private QuestionRevision $questionRevision;

    #[ORM\Column(name: 'scoring_method', length: 32, enumType: ScoringMethod::class)]
    private ScoringMethod $scoringMethod;

    #[ORM\Column(length: 32, enumType: ItemScoreOutcome::class)]
    private ItemScoreOutcome $outcome;

    #[ORM\Column(name: 'maximum_points', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $maximumPoints;

    #[ORM\Column(name: 'awarded_points', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $awardedPoints;

    #[ORM\Column(name: 'penalty_points_applied', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $penaltyPointsApplied;

    #[ORM\Column(name: 'manual_pending')]
    private bool $manualPending;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'evaluator_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $evaluatorUser = null;

    #[ORM\Column(name: 'evaluated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $evaluatedAt = null;

    #[ORM\Column(name: 'reason_code', length: 64, nullable: true)]
    private ?string $reasonCode = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        AssessmentScoringRun $scoringRun,
        AssessmentAttemptItem $attemptItem,
        ScoringMethod $scoringMethod,
        ItemScoreOutcome $outcome,
        string $maximumPoints,
        string $awardedPoints,
        string $penaltyPointsApplied,
        bool $manualPending,
        \DateTimeImmutable $now,
        ?User $evaluatorUser = null,
        ?\DateTimeImmutable $evaluatedAt = null,
        ?string $reasonCode = null,
        ?Uuid $id = null,
    ) {
        if (!$scoringRun->getStatus()->allowsItemMutation()) {
            throw AssessmentScoringException::immutable();
        }
        if (!$attemptItem->getAttempt()->getId()->equals($scoringRun->getAttempt()->getId())) {
            throw AssessmentScoringException::scopeMismatch();
        }
        if (!$attemptItem->getAssessmentRevision()->getId()->equals($scoringRun->getAssessmentRevision()->getId())) {
            throw AssessmentScoringException::scopeMismatch();
        }
        $this->assertDecimal($maximumPoints, 'maximumPoints');
        $this->assertDecimal($awardedPoints, 'awardedPoints');
        $this->assertDecimal($penaltyPointsApplied, 'penaltyPointsApplied');
        if (null !== $reasonCode) {
            $reasonCode = trim($reasonCode);
            if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonCode)) {
                throw AssessmentScoringException::invalidInput('reasonCode must match snake_case allowlist pattern.');
            }
        }
        $this->assertOutcomeConsistency($scoringMethod, $outcome, $manualPending, $evaluatorUser, $evaluatedAt, $reasonCode);

        $this->id = $id ?? new UuidV7();
        $this->scoringRun = $scoringRun;
        $this->attempt = $scoringRun->getAttempt();
        $this->attemptItem = $attemptItem;
        $this->assessmentRevision = $scoringRun->getAssessmentRevision();
        $this->question = $attemptItem->getQuestion();
        $this->questionRevision = $attemptItem->getQuestionRevision();
        $this->scoringMethod = $scoringMethod;
        $this->outcome = $outcome;
        $this->maximumPoints = $maximumPoints;
        $this->awardedPoints = $awardedPoints;
        $this->penaltyPointsApplied = $penaltyPointsApplied;
        $this->manualPending = $manualPending;
        $this->evaluatorUser = $evaluatorUser;
        $this->evaluatedAt = $evaluatedAt;
        $this->reasonCode = $reasonCode;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AssessmentScoringManager
     */
    public static function create(
        AssessmentScoringRun $scoringRun,
        AssessmentAttemptItem $attemptItem,
        ScoringMethod $scoringMethod,
        ItemScoreOutcome $outcome,
        string $maximumPoints,
        string $awardedPoints,
        string $penaltyPointsApplied,
        bool $manualPending,
        \DateTimeImmutable $now,
        ?User $evaluatorUser = null,
        ?\DateTimeImmutable $evaluatedAt = null,
        ?string $reasonCode = null,
        ?Uuid $id = null,
    ): self {
        return new self(
            $scoringRun,
            $attemptItem,
            $scoringMethod,
            $outcome,
            $maximumPoints,
            $awardedPoints,
            $penaltyPointsApplied,
            $manualPending,
            $now,
            $evaluatorUser,
            $evaluatedAt,
            $reasonCode,
            $id,
        );
    }

    public function applyManualGrade(
        string $awardedPoints,
        User $evaluatorUser,
        string $reasonCode,
        \DateTimeImmutable $evaluatedAt,
    ): void {
        if (!$this->scoringRun->getStatus()->allowsItemMutation()) {
            throw AssessmentScoringException::immutable();
        }
        if (!$this->manualPending && ItemScoreOutcome::ManualPending !== $this->outcome) {
            throw AssessmentScoringException::manualGradeNotAllowed();
        }
        $this->assertDecimal($awardedPoints, 'awardedPoints');
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonCode)) {
            throw AssessmentScoringException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }

        $this->scoringMethod = ScoringMethod::Manual;
        $this->outcome = ItemScoreOutcome::ManuallyGraded;
        $this->awardedPoints = $awardedPoints;
        $this->penaltyPointsApplied = '0.00';
        $this->manualPending = false;
        $this->evaluatorUser = $evaluatorUser;
        $this->evaluatedAt = $evaluatedAt;
        $this->reasonCode = $reasonCode;
        $this->updatedAt = $evaluatedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getScoringRun(): AssessmentScoringRun
    {
        return $this->scoringRun;
    }

    #[Ignore]
    public function getAttempt(): AssessmentAttempt
    {
        return $this->attempt;
    }

    #[Ignore]
    public function getAttemptItem(): AssessmentAttemptItem
    {
        return $this->attemptItem;
    }

    #[Ignore]
    public function getAssessmentRevision(): AssessmentRevision
    {
        return $this->assessmentRevision;
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

    public function getScoringMethod(): ScoringMethod
    {
        return $this->scoringMethod;
    }

    public function getOutcome(): ItemScoreOutcome
    {
        return $this->outcome;
    }

    public function getMaximumPoints(): string
    {
        return $this->maximumPoints;
    }

    public function getAwardedPoints(): string
    {
        return $this->awardedPoints;
    }

    public function getPenaltyPointsApplied(): string
    {
        return $this->penaltyPointsApplied;
    }

    public function isManualPending(): bool
    {
        return $this->manualPending;
    }

    #[Ignore]
    public function getEvaluatorUser(): ?User
    {
        return $this->evaluatorUser;
    }

    public function getEvaluatedAt(): ?\DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assertDecimal(string $value, string $field): void
    {
        if (1 !== preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            throw AssessmentScoringException::invalidInput($field.' must be a canonical decimal string.');
        }
    }

    private function assertOutcomeConsistency(
        ScoringMethod $scoringMethod,
        ItemScoreOutcome $outcome,
        bool $manualPending,
        ?User $evaluatorUser,
        ?\DateTimeImmutable $evaluatedAt,
        ?string $reasonCode,
    ): void {
        if ($manualPending !== (ItemScoreOutcome::ManualPending === $outcome)) {
            throw AssessmentScoringException::invalidInput('manualPending must match manual_pending outcome.');
        }
        if (ItemScoreOutcome::ManuallyGraded === $outcome) {
            if (ScoringMethod::Manual !== $scoringMethod
                || null === $evaluatorUser
                || null === $evaluatedAt
                || null === $reasonCode
            ) {
                throw AssessmentScoringException::invalidInput('manually_graded requires evaluator, timestamp, and reason.');
            }
        }
    }
}
