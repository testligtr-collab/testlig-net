<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ResultReviewAvailabilityMode;
use App\Enum\ResultReviewPolicyStatus;
use App\Exception\AssessmentResultReviewException;
use App\Repository\AssessmentResultReviewPolicyRepository;
use App\ResultReview\AssessmentResultReviewPolicyHasher;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Versioned result-review policy bound to one AssessmentDelivery.
 *
 * Active uniqueness is enforced by assessment_result_active_review_policy_guards.
 */
#[ORM\Entity(repositoryClass: AssessmentResultReviewPolicyRepository::class)]
#[ORM\Table(name: 'assessment_result_review_policies')]
#[ORM\UniqueConstraint(name: 'uniq_arrp_delivery_version', columns: ['delivery_id', 'version'])]
#[ORM\UniqueConstraint(name: 'uniq_arrp_id_delivery', columns: ['id', 'delivery_id'])]
#[ORM\UniqueConstraint(name: 'uniq_arrp_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_arrp_id_delivery_institution', columns: ['id', 'delivery_id', 'institution_id'])]
#[ORM\Index(name: 'idx_arrp_delivery_status', columns: ['delivery_id', 'status'])]
#[ORM\Index(name: 'idx_arrp_institution', columns: ['institution_id'])]
class AssessmentResultReviewPolicy
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentDelivery $delivery;

    #[ORM\Column]
    private int $version;

    #[ORM\Column(length: 32, enumType: ResultReviewPolicyStatus::class)]
    private ResultReviewPolicyStatus $status;

    #[ORM\Column(name: 'availability_mode', length: 32, enumType: ResultReviewAvailabilityMode::class)]
    private ResultReviewAvailabilityMode $availabilityMode;

    #[ORM\Column(name: 'scheduled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt;

    #[ORM\Column(name: 'show_score_summary')]
    private bool $showScoreSummary;

    #[ORM\Column(name: 'show_item_outcomes')]
    private bool $showItemOutcomes;

    #[ORM\Column(name: 'show_student_answer')]
    private bool $showStudentAnswer;

    #[ORM\Column(name: 'show_correct_answer')]
    private bool $showCorrectAnswer;

    #[ORM\Column(name: 'show_explanation')]
    private bool $showExplanation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'activated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $activatedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'activated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(name: 'superseded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $supersededAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'reason_code', length: 64)]
    private string $reasonCode;

    #[ORM\Column(name: 'policy_hash', length: 64)]
    private string $policyHash;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    private function __construct(
        AssessmentDelivery $delivery,
        int $version,
        User $createdBy,
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
        string $reasonCode,
        string $policyHash,
        \DateTimeImmutable $now,
        int $schemaVersion = AssessmentResultReviewPolicyHasher::SCHEMA_VERSION,
        ?Uuid $id = null,
    ) {
        if ($version < 1) {
            throw AssessmentResultReviewException::invalidInput('version must be >= 1.');
        }
        if ($schemaVersion < 1) {
            throw AssessmentResultReviewException::invalidInput('schemaVersion must be >= 1.');
        }
        $reasonCode = self::normalizeReasonCode($reasonCode);
        self::assertFlagRules(
            $availabilityMode,
            $scheduledAt,
            $showScoreSummary,
            $showItemOutcomes,
            $showStudentAnswer,
            $showCorrectAnswer,
            $showExplanation,
        );
        self::assertPolicyHash($policyHash);

        $this->id = $id ?? new UuidV7();
        $this->institution = $delivery->getInstitution();
        $this->delivery = $delivery;
        $this->version = $version;
        $this->status = ResultReviewPolicyStatus::Draft;
        $this->availabilityMode = $availabilityMode;
        $this->scheduledAt = $scheduledAt;
        $this->showScoreSummary = $showScoreSummary;
        $this->showItemOutcomes = $showItemOutcomes;
        $this->showStudentAnswer = $showStudentAnswer;
        $this->showCorrectAnswer = $showCorrectAnswer;
        $this->showExplanation = $showExplanation;
        $this->createdBy = $createdBy;
        $this->reasonCode = $reasonCode;
        $this->policyHash = $policyHash;
        $this->schemaVersion = $schemaVersion;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AssessmentResultReviewPolicyManager
     */
    public static function createDraft(
        AssessmentDelivery $delivery,
        int $version,
        User $createdBy,
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
        string $reasonCode,
        string $policyHash,
        \DateTimeImmutable $now,
        int $schemaVersion = AssessmentResultReviewPolicyHasher::SCHEMA_VERSION,
        ?Uuid $id = null,
    ): self {
        return new self(
            $delivery,
            $version,
            $createdBy,
            $availabilityMode,
            $scheduledAt,
            $showScoreSummary,
            $showItemOutcomes,
            $showStudentAnswer,
            $showCorrectAnswer,
            $showExplanation,
            $reasonCode,
            $policyHash,
            $now,
            $schemaVersion,
            $id,
        );
    }

    public function updateDraft(
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
        string $reasonCode,
        string $policyHash,
        \DateTimeImmutable $now,
    ): void {
        if (!$this->status->allowsDraftMutation()) {
            throw AssessmentResultReviewException::invalidTransition();
        }
        $reasonCode = self::normalizeReasonCode($reasonCode);
        self::assertFlagRules(
            $availabilityMode,
            $scheduledAt,
            $showScoreSummary,
            $showItemOutcomes,
            $showStudentAnswer,
            $showCorrectAnswer,
            $showExplanation,
        );
        self::assertPolicyHash($policyHash);

        $this->availabilityMode = $availabilityMode;
        $this->scheduledAt = $scheduledAt;
        $this->showScoreSummary = $showScoreSummary;
        $this->showItemOutcomes = $showItemOutcomes;
        $this->showStudentAnswer = $showStudentAnswer;
        $this->showCorrectAnswer = $showCorrectAnswer;
        $this->showExplanation = $showExplanation;
        $this->reasonCode = $reasonCode;
        $this->policyHash = $policyHash;
        $this->updatedAt = $now;
    }

    public function activate(User $actor, string $reasonCode, \DateTimeImmutable $activatedAt): void
    {
        $this->assertTransition(ResultReviewPolicyStatus::Active);
        $this->status = ResultReviewPolicyStatus::Active;
        $this->activatedBy = $actor;
        $this->activatedAt = $activatedAt;
        $this->reasonCode = self::normalizeReasonCode($reasonCode);
        $this->updatedAt = $activatedAt;
    }

    public function supersede(\DateTimeImmutable $supersededAt): void
    {
        $this->assertTransition(ResultReviewPolicyStatus::Superseded);
        $this->status = ResultReviewPolicyStatus::Superseded;
        $this->supersededAt = $supersededAt;
        $this->updatedAt = $supersededAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStatus(): ResultReviewPolicyStatus
    {
        return $this->status;
    }

    public function getAvailabilityMode(): ResultReviewAvailabilityMode
    {
        return $this->availabilityMode;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function showScoreSummary(): bool
    {
        return $this->showScoreSummary;
    }

    public function showItemOutcomes(): bool
    {
        return $this->showItemOutcomes;
    }

    public function showStudentAnswer(): bool
    {
        return $this->showStudentAnswer;
    }

    public function showCorrectAnswer(): bool
    {
        return $this->showCorrectAnswer;
    }

    public function showExplanation(): bool
    {
        return $this->showExplanation;
    }

    #[Ignore]
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    #[Ignore]
    public function getActivatedBy(): ?User
    {
        return $this->activatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getSupersededAt(): ?\DateTimeImmutable
    {
        return $this->supersededAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }

    public function getPolicyHash(): string
    {
        return $this->policyHash;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    private function assertTransition(ResultReviewPolicyStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw AssessmentResultReviewException::invalidTransition();
        }
    }

    private static function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonCode)) {
            throw AssessmentResultReviewException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }

        return $reasonCode;
    }

    private static function assertPolicyHash(string $policyHash): void
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $policyHash)) {
            throw AssessmentResultReviewException::invalidInput('policyHash must be 64 lowercase hex chars.');
        }
    }

    private static function assertFlagRules(
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
    ): void {
        unset($showScoreSummary); // score summary may be true under any mode including never

        if (ResultReviewAvailabilityMode::Never === $availabilityMode) {
            if ($showItemOutcomes || $showStudentAnswer || $showCorrectAnswer || $showExplanation) {
                throw AssessmentResultReviewException::invalidInput(
                    'availabilityMode never forbids item outcomes, student answer, correct answer, and explanation.',
                );
            }
            if (null !== $scheduledAt) {
                throw AssessmentResultReviewException::invalidInput('scheduledAt must be null unless scheduled_after_close.');
            }

            return;
        }

        if ($showCorrectAnswer || $showExplanation) {
            if (!$showItemOutcomes) {
                throw AssessmentResultReviewException::invalidInput(
                    'showCorrectAnswer or showExplanation requires showItemOutcomes.',
                );
            }
        }

        if (ResultReviewAvailabilityMode::ScheduledAfterClose === $availabilityMode) {
            if (null === $scheduledAt) {
                throw AssessmentResultReviewException::invalidInput(
                    'scheduled_after_close requires scheduledAt.',
                );
            }
        } elseif (null !== $scheduledAt) {
            throw AssessmentResultReviewException::invalidInput(
                'scheduledAt must be null unless scheduled_after_close.',
            );
        }
    }
}
