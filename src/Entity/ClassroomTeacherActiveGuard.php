<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassroomTeacherActiveGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures one active teacher assignment per (classroom, teacher membership).
 * Composite FK on assignment keys is enforced in DB (+ schema listener).
 */
#[ORM\Entity(repositoryClass: ClassroomTeacherActiveGuardRepository::class)]
#[ORM\Table(name: 'classroom_teacher_active_guards')]
#[ORM\UniqueConstraint(name: 'uniq_classroom_teacher_active_assignment', columns: ['assignment_id'])]
class ClassroomTeacherActiveGuard
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Classroom $classroom;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'teacher_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionMembership $teacherMembership;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'assignment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClassroomTeacherAssignment $assignment;

    private function __construct(
        Classroom $classroom,
        InstitutionMembership $teacherMembership,
        ClassroomTeacherAssignment $assignment,
    ) {
        $this->classroom = $classroom;
        $this->teacherMembership = $teacherMembership;
        $this->assignment = $assignment;
    }

    /**
     * @internal prefer ClassroomTeacherAssignmentManager
     */
    public static function bind(
        Classroom $classroom,
        InstitutionMembership $teacherMembership,
        ClassroomTeacherAssignment $assignment,
    ): self {
        return new self($classroom, $teacherMembership, $assignment);
    }

    public function getClassroomId(): Uuid
    {
        return $this->classroom->getId();
    }

    public function getTeacherMembershipId(): Uuid
    {
        return $this->teacherMembership->getId();
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

    #[Ignore]
    public function getAssignment(): ClassroomTeacherAssignment
    {
        return $this->assignment;
    }
}
