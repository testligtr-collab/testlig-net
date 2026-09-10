<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ItemScoreOutcome;
use App\Exception\AssessmentScoringException;
use App\Repository\AssessmentManualGradeDecisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only manual grading history for one attempt item within a scoring run.
 */
#[ORM\Entity(repositoryClass: AssessmentManualGradeDecisionRepository::class)]
#[ORM\Table(name: 'assessment_manual_grade_decisions')]
#[ORM\UniqueConstraint(name: 'uniq_amgd_item_decision', columns: ['attempt_item_id', 'decision_number'])]
#[ORM\UniqueConstraint(name: 'uniq_amgd_run_item_decision', columns: ['scoring_run_id', 'attempt_item_id', 'decision_number'])]
#[ORM\Index(name: 'idx_amgd_attempt', columns: ['attempt_id'])]
#[ORM\Index(name: 'idx_amgd_run', columns: ['scoring_run_id'])]
class AssessmentManualGradeDecision
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

    #[ORM\Column(name: 'decision_number')]
    private int $decisionNumber;

    #[ORM\Column(name: 'awarded_points', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $awardedPoints;

    #[ORM\Column(name: 'maximum_points', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $maximumPoints;

    #[ORM\Column(length: 32, enumType: ItemScoreOutcome::class)]
    private ItemScoreOutcome $outcome;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'evaluator_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $evaluatorUser;

    #[ORM\Column(name: 'evaluated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $evaluatedAt;

    #[ORM\Column(name: 'reason_code', length: 64)]
    private string $reasonCode;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        AssessmentScoringRun $scoringRun,
        AssessmentAttemptItem $attemptItem,
        int $decisionNumber,
        string $awardedPoints,
        string $maximumPoints,
        User $evaluatorUser,
        string $reasonCode,
        \DateTimeImmutable $evaluatedAt,
        ?Uuid $id = null,
    ) {
        if ($decisionNumber < 1) {
            throw AssessmentScoringException::invalidInput('decisionNumber must be >= 1.');
        }
        if (!$attemptItem->getAttempt()->getId()->equals($scoringRun->getAttempt()->getId())) {
            throw AssessmentScoringException::scopeMismatch();
        }
        if (1 !== preg_match('/^-?\d+(\.\d{1,2})?$/', $awardedPoints)) {
            throw AssessmentScoringException::invalidInput('awardedPoints must be a canonical decimal string.');
        }
        if (1 !== preg_match('/^-?\d+(\.\d{1,2})?$/', $maximumPoints)) {
            throw AssessmentScoringException::invalidInput('maximumPoints must be a canonical decimal string.');
        }
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonCode)) {
            throw AssessmentScoringException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }

        $this->id = $id ?? new UuidV7();
        $this->scoringRun = $scoringRun;
        $this->attempt = $scoringRun->getAttempt();
        $this->attemptItem = $attemptItem;
        $this->decisionNumber = $decisionNumber;
        $this->awardedPoints = $awardedPoints;
        $this->maximumPoints = $maximumPoints;
        $this->outcome = ItemScoreOutcome::ManuallyGraded;
        $this->evaluatorUser = $evaluatorUser;
        $this->evaluatedAt = $evaluatedAt;
        $this->reasonCode = $reasonCode;
        $this->createdAt = $evaluatedAt;
    }

    /**
     * @internal prefer ManualAssessmentGradingManager
     */
    public static function record(
        AssessmentScoringRun $scoringRun,
        AssessmentAttemptItem $attemptItem,
        int $decisionNumber,
        string $awardedPoints,
        string $maximumPoints,
        User $evaluatorUser,
        string $reasonCode,
        \DateTimeImmutable $evaluatedAt,
        ?Uuid $id = null,
    ): self {
        return new self(
            $scoringRun,
            $attemptItem,
            $decisionNumber,
            $awardedPoints,
            $maximumPoints,
            $evaluatorUser,
            $reasonCode,
            $evaluatedAt,
            $id,
        );
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

    public function getDecisionNumber(): int
    {
        return $this->decisionNumber;
    }

    public function getAwardedPoints(): string
    {
        return $this->awardedPoints;
    }

    public function getMaximumPoints(): string
    {
        return $this->maximumPoints;
    }

    public function getOutcome(): ItemScoreOutcome
    {
        return $this->outcome;
    }

    #[Ignore]
    public function getEvaluatorUser(): User
    {
        return $this->evaluatorUser;
    }

    public function getEvaluatedAt(): \DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
