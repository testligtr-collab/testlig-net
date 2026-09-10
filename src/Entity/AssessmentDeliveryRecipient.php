<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Exception\AssessmentDeliveryException;
use App\Repository\AssessmentDeliveryRecipientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable recipient snapshot created at delivery activation (or controlled add).
 * Identity (delivery/membership/user/institution/source) never changes; only revoke fields mutate.
 */
#[ORM\Entity(repositoryClass: AssessmentDeliveryRecipientRepository::class)]
#[ORM\Table(name: 'assessment_delivery_recipients')]
#[ORM\UniqueConstraint(name: 'uniq_adr_delivery_membership', columns: ['delivery_id', 'student_membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_adr_delivery_user', columns: ['delivery_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_adr_id_delivery', columns: ['id', 'delivery_id'])]
#[ORM\Index(name: 'idx_adr_delivery_status', columns: ['delivery_id', 'status'])]
#[ORM\Index(name: 'idx_adr_membership_status', columns: ['student_membership_id', 'status'])]
#[ORM\Index(name: 'idx_adr_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_adr_institution', columns: ['institution_id'])]
class AssessmentDeliveryRecipient
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentDelivery $delivery;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InstitutionMembership $studentMembership;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 32, enumType: AssessmentDeliveryRecipientStatus::class)]
    private AssessmentDeliveryRecipientStatus $status;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_classroom_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Classroom $sourceClassroom;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_enrollment_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?ClassroomStudentEnrollment $sourceEnrollment;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revoked_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $revokedBy = null;

    #[ORM\Column(name: 'revocation_reason_code', length: 64, nullable: true)]
    private ?string $revocationReasonCode = null;

    private function __construct(
        AssessmentDelivery $delivery,
        InstitutionMembership $studentMembership,
        ?Classroom $sourceClassroom,
        ?ClassroomStudentEnrollment $sourceEnrollment,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (!$studentMembership->getInstitution()->getId()->equals($delivery->getInstitution()->getId())) {
            throw AssessmentDeliveryException::scopeMismatch();
        }
        if (null !== $sourceClassroom
            && !$sourceClassroom->getInstitution()->getId()->equals($delivery->getInstitution()->getId())
        ) {
            throw AssessmentDeliveryException::scopeMismatch();
        }
        if (null !== $sourceEnrollment) {
            if (null === $sourceClassroom
                || !$sourceEnrollment->getClassroom()->getId()->equals($sourceClassroom->getId())
                || !$sourceEnrollment->getStudentMembership()->getId()->equals($studentMembership->getId())
            ) {
                throw AssessmentDeliveryException::invalidInput(
                    'sourceEnrollment must match sourceClassroom and studentMembership.',
                );
            }
        }

        $this->id = $id ?? new UuidV7();
        $this->delivery = $delivery;
        $this->institution = $delivery->getInstitution();
        $this->studentMembership = $studentMembership;
        $this->user = $studentMembership->getUser();
        $this->status = AssessmentDeliveryRecipientStatus::Eligible;
        $this->sourceClassroom = $sourceClassroom;
        $this->sourceEnrollment = $sourceEnrollment;
        $this->assignedAt = $now;
    }

    /**
     * @internal prefer AssessmentDeliveryManager
     */
    public static function createEligible(
        AssessmentDelivery $delivery,
        InstitutionMembership $studentMembership,
        ?Classroom $sourceClassroom,
        ?ClassroomStudentEnrollment $sourceEnrollment,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($delivery, $studentMembership, $sourceClassroom, $sourceEnrollment, $now, $id);
    }

    public function revoke(User $actor, string $revocationReasonCode, \DateTimeImmutable $now): void
    {
        if (AssessmentDeliveryRecipientStatus::Eligible !== $this->status) {
            throw AssessmentDeliveryException::invalidTransition();
        }
        $this->status = AssessmentDeliveryRecipientStatus::Revoked;
        $this->revokedAt = $now;
        $this->revokedBy = $actor;
        $this->revocationReasonCode = $revocationReasonCode;
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

    public function getStatus(): AssessmentDeliveryRecipientStatus
    {
        return $this->status;
    }

    #[Ignore]
    public function getSourceClassroom(): ?Classroom
    {
        return $this->sourceClassroom;
    }

    #[Ignore]
    public function getSourceEnrollment(): ?ClassroomStudentEnrollment
    {
        return $this->sourceEnrollment;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    #[Ignore]
    public function getRevokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function getRevocationReasonCode(): ?string
    {
        return $this->revocationReasonCode;
    }
}
