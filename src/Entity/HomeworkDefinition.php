<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HomeworkDefinitionStatus;
use App\Exception\HomeworkDefinitionException;
use App\Repository\HomeworkDefinitionRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Teacher-owned homework definition (draft prep). Not an assignment: the classroom here is
 * preparation scope only and never grants student access. Publish/recipient snapshots are
 * future HomeworkAssignment / HomeworkRecipient rows.
 *
 * @internal prefer HomeworkDefinitionManager
 */
#[ORM\Entity(repositoryClass: HomeworkDefinitionRepository::class)]
#[ORM\Table(name: 'homework_definitions')]
#[ORM\Index(name: 'idx_homework_def_creator_status', columns: ['created_by_user_id', 'status'])]
#[ORM\Index(name: 'idx_homework_def_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_homework_def_classroom', columns: ['classroom_id'])]
#[ORM\Index(name: 'idx_homework_def_year', columns: ['academic_year_id'])]
#[ORM\Index(name: 'idx_homework_def_membership', columns: ['created_by_membership_id'])]
class HomeworkDefinition
{
    public const TITLE_MAX = 150;
    public const DESCRIPTION_MAX = 10000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

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
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdByUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InstitutionMembership $createdByMembership;

    #[ORM\Column(length: self::TITLE_MAX)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $description;

    #[ORM\Column(name: 'due_at', type: Types::DATETIME_IMMUTABLE, options: ['comment' => 'UTC'])]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(length: 32, enumType: HomeworkDefinitionStatus::class)]
    private HomeworkDefinitionStatus $status;

    #[ORM\Column(name: 'revision_number', type: Types::INTEGER, options: ['unsigned' => true, 'default' => 1])]
    private int $revisionNumber = 1;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        User $createdByUser,
        InstitutionMembership $createdByMembership,
        Classroom $classroom,
        string $title,
        string $description,
        \DateTimeImmutable $dueAt,
        \DateTimeImmutable $now,
    ) {
        $this->id = new UuidV7();
        $this->createdByUser = $createdByUser;
        $this->createdByMembership = $createdByMembership;
        $this->assignScope($classroom);
        $this->title = $title;
        $this->description = $description;
        $this->dueAt = UtcInstant::ensure($dueAt);
        $this->status = HomeworkDefinitionStatus::Draft;
        $this->createdAt = UtcInstant::ensure($now);
        $this->updatedAt = UtcInstant::ensure($now);
    }

    /**
     * @internal prefer HomeworkDefinitionManager
     */
    public static function createDraft(
        User $createdByUser,
        InstitutionMembership $createdByMembership,
        Classroom $classroom,
        string $title,
        string $description,
        \DateTimeImmutable $dueAt,
        \DateTimeImmutable $now,
    ): self {
        return new self($createdByUser, $createdByMembership, $classroom, $title, $description, $dueAt, $now);
    }

    /**
     * @internal prefer HomeworkDefinitionManager
     */
    public function reviseDraft(
        InstitutionMembership $membership,
        Classroom $classroom,
        string $title,
        string $description,
        \DateTimeImmutable $dueAt,
        \DateTimeImmutable $now,
    ): void {
        if (HomeworkDefinitionStatus::Draft !== $this->status) {
            throw HomeworkDefinitionException::notEditable();
        }
        $this->createdByMembership = $membership;
        $this->assignScope($classroom);
        $this->title = $title;
        $this->description = $description;
        $this->dueAt = UtcInstant::ensure($dueAt);
        $this->updatedAt = UtcInstant::ensure($now);
        ++$this->revisionNumber;
    }

    private function assignScope(Classroom $classroom): void
    {
        $this->classroom = $classroom;
        $this->institution = $classroom->getInstitution();
        $this->academicYear = $classroom->getAcademicYear();
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
    public function getCreatedByUser(): User
    {
        return $this->createdByUser;
    }

    #[Ignore]
    public function getCreatedByMembership(): InstitutionMembership
    {
        return $this->createdByMembership;
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

    public function getStatus(): HomeworkDefinitionStatus
    {
        return $this->status;
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
