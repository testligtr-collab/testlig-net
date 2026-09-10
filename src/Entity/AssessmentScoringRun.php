<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssessmentAttemptStatus;
use App\Enum\ScoringRunStatus;
use App\Exception\AssessmentScoringException;
use App\Repository\AssessmentScoringRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable scoring snapshot for one attempt run. Completed runs are frozen.
 *
 * Identity and denormalized attempt graph fields never change after create.
 */
#[ORM\Entity(repositoryClass: AssessmentScoringRunRepository::class)]
#[ORM\Table(name: 'assessment_scoring_runs')]
#[ORM\UniqueConstraint(name: 'uniq_asr_attempt_run', columns: ['attempt_id', 'run_number'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_attempt', columns: ['id', 'attempt_id'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_delivery', columns: ['id', 'delivery_id'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_recipient', columns: ['id', 'recipient_id'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_user', columns: ['id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_assessment', columns: ['id', 'assessment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_publication', columns: ['id', 'assessment_publication_id'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_revision', columns: ['id', 'assessment_revision_id'])]
#[ORM\UniqueConstraint(name: 'uniq_asr_id_attempt_revision', columns: ['id', 'attempt_id', 'assessment_revision_id'])]
#[ORM\Index(name: 'idx_asr_attempt_status', columns: ['attempt_id', 'status'])]
#[ORM\Index(name: 'idx_asr_institution_status', columns: ['institution_id', 'status'])]
class AssessmentScoringRun
{
    public const DEFAULT_SCORING_POLICY_ID = 'testlig_default_v1';

    public const DEFAULT_SCORING_VERSION = 1;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttempt $attempt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentDelivery $delivery;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recipient_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentDeliveryRecipient $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Assessment $assessment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_publication_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentPublication $assessmentPublication;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentRevision $assessmentRevision;

    #[ORM\Column(name: 'publication_number')]
    private int $publicationNumber;

    #[ORM\Column(name: 'scoring_policy_id', length: 64)]
    private string $scoringPolicyId;

    #[ORM\Column(name: 'scoring_version')]
    private int $scoringVersion;

    #[ORM\Column(name: 'run_number')]
    private int $runNumber;

    #[ORM\Column(length: 32, enumType: ScoringRunStatus::class)]
    private ScoringRunStatus $status;

    #[ORM\Column(name: 'raw_points', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $rawPoints;

    #[ORM\Column(name: 'final_points', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $finalPoints;

    #[ORM\Column(name: 'maximum_points', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $maximumPoints;

    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 4)]
    private string $percentage;

    #[ORM\Column(name: 'correct_count')]
    private int $correctCount;

    #[ORM\Column(name: 'incorrect_count')]
    private int $incorrectCount;

    #[ORM\Column(name: 'unanswered_count')]
    private int $unansweredCount;

    #[ORM\Column(name: 'manual_pending_count')]
    private int $manualPendingCount;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $createdBy;

    #[ORM\Column(name: 'reason_code', length: 64)]
    private string $reasonCode;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        AssessmentAttempt $attempt,
        int $runNumber,
        string $reasonCode,
        \DateTimeImmutable $startedAt,
        ?User $createdBy = null,
        string $scoringPolicyId = self::DEFAULT_SCORING_POLICY_ID,
        int $scoringVersion = self::DEFAULT_SCORING_VERSION,
        ?Uuid $id = null,
    ) {
        $attemptStatus = $attempt->getStatus();
        if (AssessmentAttemptStatus::Submitted !== $attemptStatus
            && AssessmentAttemptStatus::Expired !== $attemptStatus
        ) {
            throw AssessmentScoringException::attemptNotScorable();
        }
        if ($runNumber < 1) {
            throw AssessmentScoringException::invalidInput('runNumber must be >= 1.');
        }
        if ($scoringVersion < 1) {
            throw AssessmentScoringException::invalidInput('scoringVersion must be >= 1.');
        }
        $scoringPolicyId = trim($scoringPolicyId);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $scoringPolicyId)) {
            throw AssessmentScoringException::invalidInput('scoringPolicyId must match snake_case allowlist pattern.');
        }
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonCode)) {
            throw AssessmentScoringException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }

        $this->id = $id ?? new UuidV7();
        $this->attempt = $attempt;
        $this->institution = $attempt->getInstitution();
        $this->delivery = $attempt->getDelivery();
        $this->recipient = $attempt->getRecipient();
        $this->user = $attempt->getUser();
        $this->assessment = $attempt->getAssessment();
        $this->assessmentPublication = $attempt->getAssessmentPublication();
        $this->assessmentRevision = $attempt->getAssessmentRevision();
        $this->publicationNumber = $attempt->getPublicationNumber();
        $this->scoringPolicyId = $scoringPolicyId;
        $this->scoringVersion = $scoringVersion;
        $this->runNumber = $runNumber;
        $this->status = ScoringRunStatus::Processing;
        $this->rawPoints = '0.00';
        $this->finalPoints = '0.00';
        $this->maximumPoints = '0.00';
        $this->percentage = '0.0000';
        $this->correctCount = 0;
        $this->incorrectCount = 0;
        $this->unansweredCount = 0;
        $this->manualPendingCount = 0;
        $this->startedAt = $startedAt;
        $this->createdBy = $createdBy;
        $this->reasonCode = $reasonCode;
        $this->createdAt = $startedAt;
        $this->updatedAt = $startedAt;
    }

    /**
     * @internal prefer AssessmentScoringManager
     */
    public static function createProcessing(
        AssessmentAttempt $attempt,
        int $runNumber,
        string $reasonCode,
        \DateTimeImmutable $startedAt,
        ?User $createdBy = null,
        string $scoringPolicyId = self::DEFAULT_SCORING_POLICY_ID,
        int $scoringVersion = self::DEFAULT_SCORING_VERSION,
        ?Uuid $id = null,
    ): self {
        return new self(
            $attempt,
            $runNumber,
            $reasonCode,
            $startedAt,
            $createdBy,
            $scoringPolicyId,
            $scoringVersion,
            $id,
        );
    }

    public function markPendingManual(
        string $rawPoints,
        string $finalPoints,
        string $maximumPoints,
        string $percentage,
        int $correctCount,
        int $incorrectCount,
        int $unansweredCount,
        int $manualPendingCount,
        \DateTimeImmutable $now,
    ): void {
        $this->assertTransition(ScoringRunStatus::PendingManual);
        $this->assertAggregateInputs(
            $rawPoints,
            $finalPoints,
            $maximumPoints,
            $percentage,
            $correctCount,
            $incorrectCount,
            $unansweredCount,
            $manualPendingCount,
        );
        if ($manualPendingCount < 1) {
            throw AssessmentScoringException::invalidInput('pending_manual requires manualPendingCount >= 1.');
        }

        $this->status = ScoringRunStatus::PendingManual;
        $this->applyAggregates(
            $rawPoints,
            $finalPoints,
            $maximumPoints,
            $percentage,
            $correctCount,
            $incorrectCount,
            $unansweredCount,
            $manualPendingCount,
        );
        $this->completedAt = null;
        $this->updatedAt = $now;
    }

    public function complete(
        string $rawPoints,
        string $finalPoints,
        string $maximumPoints,
        string $percentage,
        int $correctCount,
        int $incorrectCount,
        int $unansweredCount,
        int $manualPendingCount,
        \DateTimeImmutable $completedAt,
    ): void {
        $this->assertTransition(ScoringRunStatus::Completed);
        $this->assertAggregateInputs(
            $rawPoints,
            $finalPoints,
            $maximumPoints,
            $percentage,
            $correctCount,
            $incorrectCount,
            $unansweredCount,
            $manualPendingCount,
        );
        if (0 !== $manualPendingCount) {
            throw AssessmentScoringException::invalidInput('completed scoring run requires manualPendingCount = 0.');
        }

        $this->status = ScoringRunStatus::Completed;
        $this->applyAggregates(
            $rawPoints,
            $finalPoints,
            $maximumPoints,
            $percentage,
            $correctCount,
            $incorrectCount,
            $unansweredCount,
            $manualPendingCount,
        );
        $this->completedAt = $completedAt;
        $this->updatedAt = $completedAt;
    }

    /**
     * Refresh aggregates while remaining pending_manual (manual grades still outstanding).
     */
    public function refreshPendingManualAggregates(
        string $rawPoints,
        string $finalPoints,
        string $maximumPoints,
        string $percentage,
        int $correctCount,
        int $incorrectCount,
        int $unansweredCount,
        int $manualPendingCount,
        \DateTimeImmutable $now,
    ): void {
        if (ScoringRunStatus::PendingManual !== $this->status) {
            throw AssessmentScoringException::invalidTransition();
        }
        $this->assertAggregateInputs(
            $rawPoints,
            $finalPoints,
            $maximumPoints,
            $percentage,
            $correctCount,
            $incorrectCount,
            $unansweredCount,
            $manualPendingCount,
        );
        if ($manualPendingCount < 1) {
            throw AssessmentScoringException::invalidInput('pending_manual requires manualPendingCount >= 1.');
        }

        $this->applyAggregates(
            $rawPoints,
            $finalPoints,
            $maximumPoints,
            $percentage,
            $correctCount,
            $incorrectCount,
            $unansweredCount,
            $manualPendingCount,
        );
        $this->updatedAt = $now;
    }

    public function fail(\DateTimeImmutable $now): void
    {
        $this->assertTransition(ScoringRunStatus::Failed);
        $this->status = ScoringRunStatus::Failed;
        $this->completedAt = null;
        $this->updatedAt = $now;
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
    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getDelivery(): AssessmentDelivery
    {
        return $this->delivery;
    }

    #[Ignore]
    public function getRecipient(): AssessmentDeliveryRecipient
    {
        return $this->recipient;
    }

    #[Ignore]
    public function getUser(): User
    {
        return $this->user;
    }

    #[Ignore]
    public function getAssessment(): Assessment
    {
        return $this->assessment;
    }

    #[Ignore]
    public function getAssessmentPublication(): AssessmentPublication
    {
        return $this->assessmentPublication;
    }

    #[Ignore]
    public function getAssessmentRevision(): AssessmentRevision
    {
        return $this->assessmentRevision;
    }

    public function getPublicationNumber(): int
    {
        return $this->publicationNumber;
    }

    public function getScoringPolicyId(): string
    {
        return $this->scoringPolicyId;
    }

    public function getScoringVersion(): int
    {
        return $this->scoringVersion;
    }

    public function getRunNumber(): int
    {
        return $this->runNumber;
    }

    public function getStatus(): ScoringRunStatus
    {
        return $this->status;
    }

    public function getRawPoints(): string
    {
        return $this->rawPoints;
    }

    public function getFinalPoints(): string
    {
        return $this->finalPoints;
    }

    public function getMaximumPoints(): string
    {
        return $this->maximumPoints;
    }

    public function getPercentage(): string
    {
        return $this->percentage;
    }

    public function getCorrectCount(): int
    {
        return $this->correctCount;
    }

    public function getIncorrectCount(): int
    {
        return $this->incorrectCount;
    }

    public function getUnansweredCount(): int
    {
        return $this->unansweredCount;
    }

    public function getManualPendingCount(): int
    {
        return $this->manualPendingCount;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    #[Ignore]
    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getReasonCode(): string
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

    private function assertTransition(ScoringRunStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw AssessmentScoringException::invalidTransition();
        }
    }

    private function assertAggregateInputs(
        string $rawPoints,
        string $finalPoints,
        string $maximumPoints,
        string $percentage,
        int $correctCount,
        int $incorrectCount,
        int $unansweredCount,
        int $manualPendingCount,
    ): void {
        foreach ([$rawPoints, $finalPoints, $maximumPoints, $percentage] as $decimal) {
            if (1 !== preg_match('/^-?\d+(\.\d{1,4})?$/', $decimal)) {
                throw AssessmentScoringException::invalidInput('Score decimal fields must be canonical numeric strings.');
            }
        }
        if ($correctCount < 0 || $incorrectCount < 0 || $unansweredCount < 0 || $manualPendingCount < 0) {
            throw AssessmentScoringException::invalidInput('Score counters must be >= 0.');
        }
    }

    private function applyAggregates(
        string $rawPoints,
        string $finalPoints,
        string $maximumPoints,
        string $percentage,
        int $correctCount,
        int $incorrectCount,
        int $unansweredCount,
        int $manualPendingCount,
    ): void {
        $this->rawPoints = $rawPoints;
        $this->finalPoints = $finalPoints;
        $this->maximumPoints = $maximumPoints;
        $this->percentage = $percentage;
        $this->correctCount = $correctCount;
        $this->incorrectCount = $incorrectCount;
        $this->unansweredCount = $unansweredCount;
        $this->manualPendingCount = $manualPendingCount;
    }
}
