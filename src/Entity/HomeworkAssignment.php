<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HomeworkAssignmentStatus;
use App\Repository\HomeworkAssignmentRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Classroom assignment of a homework definition. Title/description/dueAt are frozen at assign time.
 * Recipients are snapshotted separately; this row alone is not student access.
 *
 * @internal prefer HomeworkAssignmentManager
 */
#[ORM\Entity(repositoryClass: HomeworkAssignmentRepository::class)]
#[ORM\Table(name: 'homework_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_homework_assignment_definition', columns: ['definition_id'])]
#[ORM\Index(name: 'idx_homework_asg_classroom', columns: ['classroom_id'])]
#[ORM\Index(name: 'idx_homework_asg_institution', columns: ['institution_id'])]
#[ORM\Index(name: 'idx_homework_asg_assigner', columns: ['assigned_by_user_id'])]
class HomeworkAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'definition_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private HomeworkDefinition $definition;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Classroom $classroom;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assigned_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $assignedByUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assigned_by_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InstitutionMembership $assignedByMembership;

    #[ORM\Column(length: 150)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $description;

    #[ORM\Column(name: 'due_at', type: Types::DATETIME_IMMUTABLE, options: ['comment' => 'UTC'])]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(length: 32, enumType: HomeworkAssignmentStatus::class)]
    private HomeworkAssignmentStatus $status;

    #[ORM\Column(name: 'recipient_count', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $recipientCount;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIME_IMMUTABLE, options: ['comment' => 'UTC'])]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        HomeworkDefinition $definition,
        User $assignedByUser,
        InstitutionMembership $assignedByMembership,
        string $title,
        string $description,
        \DateTimeImmutable $dueAt,
        int $recipientCount,
        \DateTimeImmutable $now,
    ) {
        $classroom = $definition->getClassroom();
        $this->id = new UuidV7();
        $this->definition = $definition;
        $this->institution = $classroom->getInstitution();
        $this->classroom = $classroom;
        $this->academicYear = $classroom->getAcademicYear();
        $this->assignedByUser = $assignedByUser;
        $this->assignedByMembership = $assignedByMembership;
        $this->title = $title;
        $this->description = $description;
        $this->dueAt = UtcInstant::ensure($dueAt);
        $this->status = HomeworkAssignmentStatus::Assigned;
        $this->recipientCount = $recipientCount;
        $this->assignedAt = UtcInstant::ensure($now);
        $this->createdAt = UtcInstant::ensure($now);
    }

    /**
     * @internal prefer HomeworkAssignmentManager
     */
    public static function assign(
        HomeworkDefinition $definition,
        User $assignedByUser,
        InstitutionMembership $assignedByMembership,
        string $title,
        string $description,
        \DateTimeImmutable $dueAt,
        int $recipientCount,
        \DateTimeImmutable $now,
    ): self {
        return new self($definition, $assignedByUser, $assignedByMembership, $title, $description, $dueAt, $recipientCount, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getDefinition(): HomeworkDefinition
    {
        return $this->definition;
    }

    #[Ignore]
    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getClassroom(): Classroom
    {
        return $this->classroom;
    }

    #[Ignore]
    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    #[Ignore]
    public function getAssignedByUser(): User
    {
        return $this->assignedByUser;
    }

    #[Ignore]
    public function getAssignedByMembership(): InstitutionMembership
    {
        return $this->assignedByMembership;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->dueAt);
    }

    public function getStatus(): HomeworkAssignmentStatus
    {
        return $this->status;
    }

    public function getRecipientCount(): int
    {
        return $this->recipientCount;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->assignedAt);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->createdAt);
    }
}
