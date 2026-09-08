<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassroomHomeroomGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures at most one active homeroom teacher assignment per classroom.
 */
#[ORM\Entity(repositoryClass: ClassroomHomeroomGuardRepository::class)]
#[ORM\Table(name: 'classroom_homeroom_guards')]
#[ORM\UniqueConstraint(name: 'uniq_classroom_homeroom_assignment', columns: ['assignment_id'])]
class ClassroomHomeroomGuard
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Classroom $classroom;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'assignment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClassroomTeacherAssignment $assignment;

    private function __construct(Classroom $classroom, ClassroomTeacherAssignment $assignment)
    {
        $this->classroom = $classroom;
        $this->assignment = $assignment;
    }

    /**
     * @internal prefer ClassroomTeacherAssignmentManager
     */
    public static function bind(Classroom $classroom, ClassroomTeacherAssignment $assignment): self
    {
        return new self($classroom, $assignment);
    }

    public function getClassroomId(): Uuid
    {
        return $this->classroom->getId();
    }

    #[Ignore]
    public function getClassroom(): Classroom
    {
        return $this->classroom;
    }

    #[Ignore]
    public function getAssignment(): ClassroomTeacherAssignment
    {
        return $this->assignment;
    }

    #[Ignore]
    public function swapTo(ClassroomTeacherAssignment $assignment): void
    {
        $this->assignment = $assignment;
    }
}
