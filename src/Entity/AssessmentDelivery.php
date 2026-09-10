<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryStatus;
use App\Exception\AssessmentDeliveryException;
use App\Repository\AssessmentDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Tenant-scoped assignment of an immutable AssessmentPublication to an audience.
 *
 * Window/content may change only while Draft. Publication, institution, audience,
 * and targets are immutable after create. Composite FKs enforced in DB + schema listener.
 */
#[ORM\Entity(repositoryClass: AssessmentDeliveryRepository::class)]
#[ORM\Table(name: 'assessment_deliveries')]
#[ORM\UniqueConstraint(name: 'uniq_ad_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ad_id_assessment', columns: ['id', 'assessment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ad_id_publication', columns: ['id', 'assessment_publication_id'])]
#[ORM\Index(name: 'idx_ad_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_ad_assessment_status', columns: ['assessment_id', 'status'])]
#[ORM\Index(name: 'idx_ad_publication', columns: ['assessment_publication_id'])]
#[ORM\Index(name: 'idx_ad_classroom_status', columns: ['classroom_id', 'status'])]
#[ORM\Index(name: 'idx_ad_opens_closes', columns: ['opens_at', 'closes_at'])]
class AssessmentDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Assessment $assessment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_publication_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentPublication $assessmentPublication;

    #[ORM\Column(name: 'publication_number')]
    private int $publicationNumber;

    #[ORM\Column(name: 'audience_type', length: 32, enumType: AssessmentDeliveryAudienceType::class)]
    private AssessmentDeliveryAudienceType $audienceType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Classroom $classroom;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_membership_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?InstitutionMembership $studentMembership;

    #[ORM\Column(length: 32, enumType: AssessmentDeliveryStatus::class)]
    private AssessmentDeliveryStatus $status;

    #[ORM\Column(name: 'opens_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $opensAt;

    #[ORM\Column(name: 'closes_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $closesAt;

    #[ORM\Column(name: 'max_attempts')]
    private int $maxAttempts;

    #[ORM\Column(name: 'title_override', length: 200, nullable: true)]
    private ?string $titleOverride;

    #[ORM\Column(name: 'instructions_override', type: Types::TEXT, nullable: true)]
    private ?string $instructionsOverride;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'activated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $activatedBy = null;

    #[ORM\Column(name: 'activated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'closed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $closedBy = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'cancelled_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $cancelledBy = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'cancellation_reason_code', length: 64, nullable: true)]
    private ?string $cancellationReasonCode = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Institution $institution,
        Assessment $assessment,
        AssessmentPublication $assessmentPublication,
        AssessmentDeliveryAudienceType $audienceType,
        ?Classroom $classroom,
        ?InstitutionMembership $studentMembership,
        \DateTimeImmutable $opensAt,
        \DateTimeImmutable $closesAt,
        int $maxAttempts,
        ?string $titleOverride,
        ?string $instructionsOverride,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->assertAudienceTargets($audienceType, $classroom, $studentMembership, $institution);
        $this->assertWindow($opensAt, $closesAt);
        $this->assertMaxAttempts($maxAttempts);
        if (!$assessmentPublication->getAssessment()->getId()->equals($assessment->getId())) {
            throw AssessmentDeliveryException::publicationInvalid(
                'Publication does not belong to the delivery assessment.',
            );
        }

        $this->id = $id ?? new UuidV7();
        $this->institution = $institution;
        $this->assessment = $assessment;
        $this->assessmentPublication = $assessmentPublication;
        $this->publicationNumber = $assessmentPublication->getPublicationNumber();
        $this->audienceType = $audienceType;
        $this->classroom = $classroom;
        $this->studentMembership = $studentMembership;
        $this->status = AssessmentDeliveryStatus::Draft;
        $this->opensAt = $opensAt;
        $this->closesAt = $closesAt;
        $this->maxAttempts = $maxAttempts;
        $this->titleOverride = $titleOverride;
        $this->instructionsOverride = $instructionsOverride;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AssessmentDeliveryManager
     */
    public static function createDraft(
        Institution $institution,
        Assessment $assessment,
        AssessmentPublication $assessmentPublication,
        AssessmentDeliveryAudienceType $audienceType,
        ?Classroom $classroom,
        ?InstitutionMembership $studentMembership,
        \DateTimeImmutable $opensAt,
        \DateTimeImmutable $closesAt,
        int $maxAttempts,
        ?string $titleOverride,
        ?string $instructionsOverride,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $institution,
            $assessment,
            $assessmentPublication,
            $audienceType,
            $classroom,
            $studentMembership,
            $opensAt,
            $closesAt,
            $maxAttempts,
            $titleOverride,
            $instructionsOverride,
            $createdBy,
            $now,
            $id,
        );
    }

    public function updateDraftWindow(
        \DateTimeImmutable $opensAt,
        \DateTimeImmutable $closesAt,
        int $maxAttempts,
        ?string $titleOverride,
        ?string $instructionsOverride,
        \DateTimeImmutable $now,
    ): void {
        if (AssessmentDeliveryStatus::Draft !== $this->status) {
            throw AssessmentDeliveryException::invalidTransition();
        }
        $this->assertWindow($opensAt, $closesAt);
        $this->assertMaxAttempts($maxAttempts);
        $this->opensAt = $opensAt;
        $this->closesAt = $closesAt;
        $this->maxAttempts = $maxAttempts;
        $this->titleOverride = $titleOverride;
        $this->instructionsOverride = $instructionsOverride;
        $this->updatedAt = $now;
    }

    public function activate(User $actor, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AssessmentDeliveryStatus::Active)) {
            throw AssessmentDeliveryException::invalidTransition();
        }
        if ($this->closesAt <= $now) {
            throw AssessmentDeliveryException::invalidInput('closesAt must be in the future at activation.');
        }
        $this->status = AssessmentDeliveryStatus::Active;
        $this->activatedBy = $actor;
        $this->activatedAt = $now;
        $this->updatedAt = $now;
    }

    public function close(User $actor, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AssessmentDeliveryStatus::Closed)) {
            throw AssessmentDeliveryException::invalidTransition();
        }
        $this->status = AssessmentDeliveryStatus::Closed;
        $this->closedBy = $actor;
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    public function cancel(User $actor, string $cancellationReasonCode, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AssessmentDeliveryStatus::Cancelled)) {
            throw AssessmentDeliveryException::invalidTransition();
        }
        $this->status = AssessmentDeliveryStatus::Cancelled;
        $this->cancelledBy = $actor;
        $this->cancelledAt = $now;
        $this->cancellationReasonCode = $cancellationReasonCode;
        $this->updatedAt = $now;
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
    public function getAssessment(): Assessment
    {
        return $this->assessment;
    }

    #[Ignore]
    public function getAssessmentPublication(): AssessmentPublication
    {
        return $this->assessmentPublication;
    }

    public function getPublicationNumber(): int
    {
        return $this->publicationNumber;
    }

    public function getAudienceType(): AssessmentDeliveryAudienceType
    {
        return $this->audienceType;
    }

    #[Ignore]
    public function getClassroom(): ?Classroom
    {
        return $this->classroom;
    }

    #[Ignore]
    public function getStudentMembership(): ?InstitutionMembership
    {
        return $this->studentMembership;
    }

    public function getStatus(): AssessmentDeliveryStatus
    {
        return $this->status;
    }

    public function getOpensAt(): \DateTimeImmutable
    {
        return $this->opensAt;
    }

    public function getClosesAt(): \DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getTitleOverride(): ?string
    {
        return $this->titleOverride;
    }

    public function getInstructionsOverride(): ?string
    {
        return $this->instructionsOverride;
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

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    #[Ignore]
    public function getClosedBy(): ?User
    {
        return $this->closedBy;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    #[Ignore]
    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCancellationReasonCode(): ?string
    {
        return $this->cancellationReasonCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assertAudienceTargets(
        AssessmentDeliveryAudienceType $audienceType,
        ?Classroom $classroom,
        ?InstitutionMembership $studentMembership,
        Institution $institution,
    ): void {
        match ($audienceType) {
            AssessmentDeliveryAudienceType::Institution => $this->assertInstitutionAudience(
                $classroom,
                $studentMembership,
            ),
            AssessmentDeliveryAudienceType::Classroom => $this->assertClassroomAudience(
                $classroom,
                $studentMembership,
                $institution,
            ),
            AssessmentDeliveryAudienceType::Student => $this->assertStudentAudience(
                $classroom,
                $studentMembership,
                $institution,
            ),
        };
    }

    private function assertInstitutionAudience(?Classroom $classroom, ?InstitutionMembership $studentMembership): void
    {
        if (null !== $classroom || null !== $studentMembership) {
            throw AssessmentDeliveryException::invalidInput(
                'Institution audience must not set classroom or studentMembership.',
            );
        }
    }

    private function assertClassroomAudience(
        ?Classroom $classroom,
        ?InstitutionMembership $studentMembership,
        Institution $institution,
    ): void {
        if (null === $classroom || null !== $studentMembership) {
            throw AssessmentDeliveryException::invalidInput(
                'Classroom audience requires classroom and must not set studentMembership.',
            );
        }
        if (!$classroom->getInstitution()->getId()->equals($institution->getId())) {
            throw AssessmentDeliveryException::scopeMismatch();
        }
    }

    private function assertStudentAudience(
        ?Classroom $classroom,
        ?InstitutionMembership $studentMembership,
        Institution $institution,
    ): void {
        if (null !== $classroom || null === $studentMembership) {
            throw AssessmentDeliveryException::invalidInput(
                'Student audience requires studentMembership and must not set classroom.',
            );
        }
        if (!$studentMembership->getInstitution()->getId()->equals($institution->getId())) {
            throw AssessmentDeliveryException::scopeMismatch();
        }
    }

    private function assertWindow(\DateTimeImmutable $opensAt, \DateTimeImmutable $closesAt): void
    {
        if ($opensAt >= $closesAt) {
            throw AssessmentDeliveryException::invalidInput('opensAt must be before closesAt.');
        }
    }

    private function assertMaxAttempts(int $maxAttempts): void
    {
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw AssessmentDeliveryException::invalidInput('maxAttempts must be between 1 and 10.');
        }
    }
}
