<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OnboardingApplicationStatus;
use App\Exception\OnboardingApplicationException;
use App\Repository\TeacherApplicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Pending teacher onboarding application. Does not grant ROLE_TEACHER (Stage 2.22.3).
 */
#[ORM\Entity(repositoryClass: TeacherApplicationRepository::class)]
#[ORM\Table(name: 'teacher_applications')]
#[ORM\Index(name: 'idx_teacher_app_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_teacher_app_status_created', columns: ['status', 'created_at'])]
class TeacherApplication
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: OnboardingApplicationStatus::class)]
    private OnboardingApplicationStatus $status;

    #[ORM\Column(name: 'decision_reason_code', length: 64, nullable: true)]
    private ?string $decisionReasonCode = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(User $user, \DateTimeImmutable $now, ?Uuid $id = null)
    {
        $this->id = $id ?? new UuidV7();
        $this->user = $user;
        $this->status = OnboardingApplicationStatus::Pending;
        $this->submittedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer TeacherApplicationManager
     */
    public static function createPending(User $user, \DateTimeImmutable $now, ?Uuid $id = null): self
    {
        return new self($user, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): OnboardingApplicationStatus
    {
        return $this->status;
    }

    public function getDecisionReasonCode(): ?string
    {
        return $this->decisionReasonCode;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @internal prefer TeacherApplicationManager
     */
    public function markApproved(\DateTimeImmutable $now, ?string $reasonCode = null): void
    {
        $this->transition(OnboardingApplicationStatus::Approved, $now, $reasonCode);
    }

    /**
     * @internal prefer TeacherApplicationManager
     */
    public function markRejected(\DateTimeImmutable $now, string $reasonCode): void
    {
        $this->transition(OnboardingApplicationStatus::Rejected, $now, $reasonCode);
    }

    /**
     * @internal prefer TeacherApplicationManager
     */
    public function markWithdrawn(\DateTimeImmutable $now): void
    {
        $this->transition(OnboardingApplicationStatus::Withdrawn, $now, null);
    }

    /**
     * @internal prefer TeacherApplicationManager
     */
    public function markSuperseded(\DateTimeImmutable $now): void
    {
        $this->transition(OnboardingApplicationStatus::Superseded, $now, 'superseded_by_new_application');
    }

    private function transition(OnboardingApplicationStatus $target, \DateTimeImmutable $now, ?string $reasonCode): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw OnboardingApplicationException::invalidTransition();
        }

        $this->status = $target;
        $this->decidedAt = $now;
        $this->decisionReasonCode = $reasonCode;
        $this->updatedAt = $now;
    }
}
