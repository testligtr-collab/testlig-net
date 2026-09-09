<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CourseTeacherAssignmentStatus;
use App\Exception\CourseTeacherAssignmentException;
use App\Repository\CourseTeacherAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Teacher membership assigned to a classroom course. No reactivate — end then assign again.
 */
#[ORM\Entity(repositoryClass: CourseTeacherAssignmentRepository::class)]
#[ORM\Table(name: 'course_teacher_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_cteach_id_course', columns: ['id', 'classroom_course_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cteach_id_course_membership', columns: ['id', 'classroom_course_id', 'teacher_membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cteach_id_institution', columns: ['id', 'institution_id'])]
#[ORM\Index(name: 'idx_cteach_course_status', columns: ['classroom_course_id', 'status'])]
#[ORM\Index(name: 'idx_cteach_membership_status', columns: ['teacher_membership_id', 'status'])]
#[ORM\Index(name: 'idx_cteach_institution', columns: ['institution_id'])]
class CourseTeacherAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_course_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClassroomCourse $classroomCourse;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'teacher_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionMembership $teacherMembership;

    #[ORM\Column(length: 32, enumType: CourseTeacherAssignmentStatus::class)]
    private CourseTeacherAssignmentStatus $status;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        ClassroomCourse $classroomCourse,
        InstitutionMembership $teacherMembership,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->institution = $classroomCourse->getInstitution();
        $this->classroomCourse = $classroomCourse;
        $this->teacherMembership = $teacherMembership;
        $this->status = CourseTeacherAssignmentStatus::Active;
        $this->assignedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CourseTeacherAssignmentManager
     */
    public static function assign(
        ClassroomCourse $classroomCourse,
        InstitutionMembership $teacherMembership,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($classroomCourse, $teacherMembership, $now, $id);
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
    public function getClassroomCourse(): ClassroomCourse
    {
        return $this->classroomCourse;
    }

    #[Ignore]
    public function getTeacherMembership(): InstitutionMembership
    {
        return $this->teacherMembership;
    }

    public function getStatus(): CourseTeacherAssignmentStatus
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
    public function end(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(CourseTeacherAssignmentStatus::Ended)) {
            throw CourseTeacherAssignmentException::invalidTransition();
        }
        $this->status = CourseTeacherAssignmentStatus::Ended;
        $this->endedAt = $now;
        $this->updatedAt = $now;
    }
}
