<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassroomCourseActiveGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures one active classroom course per (classroom, subject).
 */
#[ORM\Entity(repositoryClass: ClassroomCourseActiveGuardRepository::class)]
#[ORM\Table(name: 'classroom_course_active_guards')]
#[ORM\UniqueConstraint(name: 'uniq_classroom_course_active_course', columns: ['course_id'])]
class ClassroomCourseActiveGuard
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Classroom $classroom;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClassroomCourse $course;

    private function __construct(
        Classroom $classroom,
        Subject $subject,
        ClassroomCourse $course,
    ) {
        $this->classroom = $classroom;
        $this->subject = $subject;
        $this->course = $course;
    }

    /**
     * @internal prefer ClassroomCourseManager
     */
    public static function bind(
        Classroom $classroom,
        Subject $subject,
        ClassroomCourse $course,
    ): self {
        return new self($classroom, $subject, $course);
    }

    public function getClassroomId(): Uuid
    {
        return $this->classroom->getId();
    }

    public function getSubjectId(): Uuid
    {
        return $this->subject->getId();
    }

    #[Ignore]
    public function getClassroom(): Classroom
    {
        return $this->classroom;
    }

    #[Ignore]
    public function getSubject(): Subject
    {
        return $this->subject;
    }

    #[Ignore]
    public function getCourse(): ClassroomCourse
    {
        return $this->course;
    }
}
