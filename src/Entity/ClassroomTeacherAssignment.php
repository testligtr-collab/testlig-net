<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Exception\ClassroomTeacherAssignmentException;
use App\Repository\ClassroomTeacherAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Teacher membership assigned to a classroom. Ended rows are history; re-assign creates a new row.
 */
#[ORM\Entity(repositoryClass: ClassroomTeacherAssignmentRepository::class)]
#[ORM\Table(name: 'classroom_teacher_assignments')]
#[ORM\Index(name: 'idx_cta_classroom_status', columns: ['classroom_id', 'status'])]
#[ORM\Index(name: 'idx_cta_membership_status', columns: ['teacher_membership_id', 'status'])]
#[ORM\Index(name: 'idx_cta_classroom_role_status', columns: ['classroom_id', 'role', 'status'])]
class ClassroomTeacherAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Classroom $classroom;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'teacher_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionMembership $teacherMembership;

    #[ORM\Column(length: 32, enumType: TeacherAssignmentRole::class)]
    private TeacherAssignmentRole $role;

    #[ORM\Column(length: 32, enumType: TeacherAssignmentStatus::class)]
    private TeacherAssignmentStatus $status;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Classroom $classroom,
        InstitutionMembership $teacherMembership,
        TeacherAssignmentRole $role,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->classroom = $classroom;
        $this->teacherMembership = $teacherMembership;
        $this->role = $role;
        $this->status = TeacherAssignmentStatus::Active;
        $this->assignedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer ClassroomTeacherAssignmentManager
     */
    public static function assign(
        Classroom $classroom,
        InstitutionMembership $teacherMembership,
        TeacherAssignmentRole $role,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($classroom, $teacherMembership, $role, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getClassroom(): Classroom
    {
        return $this->classroom;
    }

    #[Ignore]
    public function getTeacherMembership(): InstitutionMembership
    {
        return $this->teacherMembership;
    }

    public function getRole(): TeacherAssignmentRole
    {
        return $this->role;
    }

    public function getStatus(): TeacherAssignmentStatus
    {
        return $this->status;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[Ignore]
    public function changeRole(TeacherAssignmentRole $role, \DateTimeImmutable $now): void
    {
        if (TeacherAssignmentStatus::Active !== $this->status) {
            throw ClassroomTeacherAssignmentException::invalidTransition();
        }
        $this->role = $role;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function end(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(TeacherAssignmentStatus::Ended)) {
            throw ClassroomTeacherAssignmentException::invalidTransition();
        }
        $this->status = TeacherAssignmentStatus::Ended;
        $this->endedAt = $now;
        $this->updatedAt = $now;
    }
}
