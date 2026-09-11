<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ResultReleaseStatus;
use App\Enum\ScoringRunStatus;
use App\Exception\AssessmentScoringException;
use App\Repository\AssessmentResultReleaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Versioned result release bound to one completed scoring run.
 *
 * Active released uniqueness is enforced by assessment_result_active_release_guards.
 */
#[ORM\Entity(repositoryClass: AssessmentResultReleaseRepository::class)]
#[ORM\Table(name: 'assessment_result_releases')]
#[ORM\UniqueConstraint(name: 'uniq_arr_attempt_release', columns: ['attempt_id', 'release_number'])]
#[ORM\UniqueConstraint(name: 'uniq_arr_id_attempt', columns: ['id', 'attempt_id'])]
#[ORM\UniqueConstraint(name: 'uniq_arr_id_run', columns: ['id', 'scoring_run_id'])]
#[ORM\UniqueConstraint(name: 'uniq_arr_id_attempt_run', columns: ['id', 'attempt_id', 'scoring_run_id'])]
#[ORM\Index(name: 'idx_arr_attempt_status', columns: ['attempt_id', 'status'])]
#[ORM\Index(name: 'idx_arr_run', columns: ['scoring_run_id'])]
class AssessmentResultRelease
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttempt $attempt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'scoring_run_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentScoringRun $scoringRun;

    #[ORM\Column(name: 'release_number')]
    private int $releaseNumber;

    #[ORM\Column(length: 32, enumType: ResultReleaseStatus::class)]
    private ResultReleaseStatus $status;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'released_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $releasedBy = null;

    #[ORM\Column(name: 'released_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'withdrawn_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $withdrawnBy = null;

    #[ORM\Column(name: 'withdrawn_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column(name: 'reason_code', length: 64)]
    private string $reasonCode;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        AssessmentScoringRun $scoringRun,
        int $releaseNumber,
        string $reasonCode,
        \DateTimeImmutable $now,
        ResultReleaseStatus $status = ResultReleaseStatus::Draft,
        ?User $releasedBy = null,
        ?\DateTimeImmutable $releasedAt = null,
        ?Uuid $id = null,
    ) {
        if (ScoringRunStatus::Completed !== $scoringRun->getStatus()) {
            throw AssessmentScoringException::releaseNotAllowed();
        }
        if ($releaseNumber < 1) {
            throw AssessmentScoringException::invalidInput('releaseNumber must be >= 1.');
        }
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonCode)) {
            throw AssessmentScoringException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }
        if (ResultReleaseStatus::Released === $status) {
            if (null === $releasedBy || null === $releasedAt) {
                throw AssessmentScoringException::invalidInput('released status requires releasedBy and releasedAt.');
            }
        } elseif (null !== $releasedBy || null !== $releasedAt) {
            throw AssessmentScoringException::invalidInput('draft release forbids releasedBy/releasedAt.');
        }

        $this->id = $id ?? new UuidV7();
        $this->attempt = $scoringRun->getAttempt();
        $this->scoringRun = $scoringRun;
        $this->releaseNumber = $releaseNumber;
        $this->status = $status;
        $this->releasedBy = $releasedBy;
        $this->releasedAt = $releasedAt;
        $this->reasonCode = $reasonCode;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AssessmentResultReleaseManager
     */
    public static function createDraft(
        AssessmentScoringRun $scoringRun,
        int $releaseNumber,
        string $reasonCode,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($scoringRun, $releaseNumber, $reasonCode, $now, ResultReleaseStatus::Draft, null, null, $id);
    }

    /**
     * @internal prefer AssessmentResultReleaseManager
     */
    public static function createReleased(
        AssessmentScoringRun $scoringRun,
        int $releaseNumber,
        User $releasedBy,
        string $reasonCode,
        \DateTimeImmutable $releasedAt,
        ?Uuid $id = null,
    ): self {
        return new self(
            $scoringRun,
            $releaseNumber,
            $reasonCode,
            $releasedAt,
            ResultReleaseStatus::Released,
            $releasedBy,
            $releasedAt,
            $id,
        );
    }

    public function release(User $actor, \DateTimeImmutable $releasedAt): void
    {
        $this->assertTransition(ResultReleaseStatus::Released);
        $this->status = ResultReleaseStatus::Released;
        $this->releasedBy = $actor;
        $this->releasedAt = $releasedAt;
        $this->updatedAt = $releasedAt;
    }

    public function supersede(\DateTimeImmutable $now): void
    {
        $this->assertTransition(ResultReleaseStatus::Superseded);
        $this->status = ResultReleaseStatus::Superseded;
        $this->updatedAt = $now;
    }

    public function withdraw(User $actor, \DateTimeImmutable $withdrawnAt): void
    {
        $this->assertTransition(ResultReleaseStatus::Withdrawn);
        $this->status = ResultReleaseStatus::Withdrawn;
        $this->withdrawnBy = $actor;
        $this->withdrawnAt = $withdrawnAt;
        $this->updatedAt = $withdrawnAt;
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
    public function getScoringRun(): AssessmentScoringRun
    {
        return $this->scoringRun;
    }

    public function getReleaseNumber(): int
    {
        return $this->releaseNumber;
    }

    public function getStatus(): ResultReleaseStatus
    {
        return $this->status;
    }

    #[Ignore]
    public function getReleasedBy(): ?User
    {
        return $this->releasedBy;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    #[Ignore]
    public function getWithdrawnBy(): ?User
    {
        return $this->withdrawnBy;
    }

    public function getWithdrawnAt(): ?\DateTimeImmutable
    {
        return $this->withdrawnAt;
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

    private function assertTransition(ResultReleaseStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw AssessmentScoringException::invalidTransition();
        }
    }
}
