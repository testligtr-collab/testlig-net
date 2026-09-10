<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssessmentAttemptStatus;
use App\Exception\AssessmentAttemptException;
use App\Repository\AssessmentAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Tenant-scoped student attempt bound to an immutable delivery/recipient/publication snapshot.
 *
 * Identity fields are immutable after create. Status transitions are terminal one-way.
 */
#[ORM\Entity(repositoryClass: AssessmentAttemptRepository::class)]
#[ORM\Table(name: 'assessment_attempts')]
#[ORM\UniqueConstraint(name: 'uniq_aa_delivery_recipient_number', columns: ['delivery_id', 'recipient_id', 'attempt_number'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_delivery', columns: ['id', 'delivery_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_recipient', columns: ['id', 'recipient_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_assessment', columns: ['id', 'assessment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_publication', columns: ['id', 'assessment_publication_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_user', columns: ['id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_membership', columns: ['id', 'student_membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_delivery_recipient', columns: ['id', 'delivery_id', 'recipient_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_id_revision', columns: ['id', 'assessment_revision_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aa_active_recipient_scope', columns: ['active_recipient_scope_id'])]
#[ORM\Index(name: 'idx_aa_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_aa_delivery_status', columns: ['delivery_id', 'status'])]
#[ORM\Index(name: 'idx_aa_recipient_status', columns: ['recipient_id', 'status'])]
#[ORM\Index(name: 'idx_aa_user_status', columns: ['user_id', 'status'])]
class AssessmentAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentDelivery $delivery;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recipient_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentDeliveryRecipient $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InstitutionMembership $studentMembership;

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

    /**
     * STORED generated: IF(status = 'in_progress', recipient_id, NULL).
     * Primary single-active guarantee via uniq_aa_active_recipient_scope.
     */
    #[ORM\Column(
        name: 'active_recipient_scope_id',
        type: UuidType::NAME,
        nullable: true,
        insertable: false,
        updatable: false,
        generated: 'ALWAYS',
    )]
    private ?Uuid $activeRecipientScopeId = null;

    #[ORM\Column(name: 'publication_number')]
    private int $publicationNumber;

    #[ORM\Column(name: 'attempt_number')]
    private int $attemptNumber;

    #[ORM\Column(length: 32, enumType: AssessmentAttemptStatus::class)]
    private AssessmentAttemptStatus $status;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'expired_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'cancelled_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $cancelledBy = null;

    #[ORM\Column(name: 'cancellation_reason_code', length: 64, nullable: true)]
    private ?string $cancellationReasonCode = null;

    #[ORM\Column(name: 'last_activity_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastActivityAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        AssessmentDelivery $delivery,
        AssessmentDeliveryRecipient $recipient,
        int $attemptNumber,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $expiresAt,
        ?Uuid $id = null,
    ) {
        if (!$recipient->getDelivery()->getId()->equals($delivery->getId())) {
            throw AssessmentAttemptException::scopeMismatch();
        }
        if (!$recipient->getInstitution()->getId()->equals($delivery->getInstitution()->getId())) {
            throw AssessmentAttemptException::scopeMismatch();
        }
        if ($attemptNumber < 1) {
            throw AssessmentAttemptException::invalidInput('attemptNumber must be >= 1.');
        }
        if ($startedAt >= $expiresAt) {
            throw AssessmentAttemptException::invalidInput('startedAt must be before expiresAt.');
        }

        $this->id = $id ?? new UuidV7();
        $this->delivery = $delivery;
        $this->recipient = $recipient;
        $this->institution = $delivery->getInstitution();
        $this->studentMembership = $recipient->getStudentMembership();
        $this->user = $recipient->getUser();
        $this->assessment = $delivery->getAssessment();
        $this->assessmentPublication = $delivery->getAssessmentPublication();
        $this->assessmentRevision = $delivery->getAssessmentPublication()->getAssessmentRevision();
        if (!$this->assessmentPublication->getAssessmentRevision()->getId()->equals($this->assessmentRevision->getId())) {
            throw AssessmentAttemptException::scopeMismatch();
        }
        $this->publicationNumber = $delivery->getPublicationNumber();
        $this->attemptNumber = $attemptNumber;
        $this->status = AssessmentAttemptStatus::InProgress;
        $this->startedAt = $startedAt;
        $this->expiresAt = $expiresAt;
        $this->lastActivityAt = $startedAt;
        $this->createdAt = $startedAt;
        $this->updatedAt = $startedAt;
    }

    /**
     * @internal prefer AssessmentAttemptManager
     */
    public static function createInProgress(
        AssessmentDelivery $delivery,
        AssessmentDeliveryRecipient $recipient,
        int $attemptNumber,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $expiresAt,
        ?Uuid $id = null,
    ): self {
        return new self($delivery, $recipient, $attemptNumber, $startedAt, $expiresAt, $id);
    }

    public function submit(\DateTimeImmutable $now): void
    {
        $this->assertTransition(AssessmentAttemptStatus::Submitted);
        $this->status = AssessmentAttemptStatus::Submitted;
        $this->submittedAt = $now;
        $this->lastActivityAt = $now;
        $this->updatedAt = $now;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        $this->assertTransition(AssessmentAttemptStatus::Expired);
        $this->status = AssessmentAttemptStatus::Expired;
        $this->expiredAt = $now;
        $this->lastActivityAt = $now;
        $this->updatedAt = $now;
    }

    public function cancel(User $actor, string $cancellationReasonCode, \DateTimeImmutable $now): void
    {
        $this->assertTransition(AssessmentAttemptStatus::Cancelled);
        $this->status = AssessmentAttemptStatus::Cancelled;
        $this->cancelledBy = $actor;
        $this->cancelledAt = $now;
        $this->cancellationReasonCode = $cancellationReasonCode;
        $this->lastActivityAt = $now;
        $this->updatedAt = $now;
    }

    public function touchActivity(\DateTimeImmutable $now): void
    {
        if (AssessmentAttemptStatus::InProgress !== $this->status) {
            throw AssessmentAttemptException::attemptNotInProgress();
        }
        $this->lastActivityAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
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
    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getStudentMembership(): InstitutionMembership
    {
        return $this->studentMembership;
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

    public function getActiveRecipientScopeId(): ?Uuid
    {
        return $this->activeRecipientScopeId;
    }

    public function getPublicationNumber(): int
    {
        return $this->publicationNumber;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function getStatus(): AssessmentAttemptStatus
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getExpiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    #[Ignore]
    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function getCancellationReasonCode(): ?string
    {
        return $this->cancellationReasonCode;
    }

    public function getLastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assertTransition(AssessmentAttemptStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw AssessmentAttemptException::invalidTransition();
        }
    }
}
