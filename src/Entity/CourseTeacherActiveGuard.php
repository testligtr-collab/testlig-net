<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CourseTeacherActiveGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures one active course teacher assignment per (course, teacher membership).
 */
#[ORM\Entity(repositoryClass: CourseTeacherActiveGuardRepository::class)]
#[ORM\Table(name: 'course_teacher_active_guards')]
#[ORM\UniqueConstraint(name: 'uniq_course_teacher_active_assignment', columns: ['assignment_id'])]
class CourseTeacherActiveGuard
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_course_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClassroomCourse $classroomCourse;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'teacher_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionMembership $teacherMembership;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'assignment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CourseTeacherAssignment $assignment;

    private function __construct(
        ClassroomCourse $classroomCourse,
        InstitutionMembership $teacherMembership,
        CourseTeacherAssignment $assignment,
    ) {
        $this->classroomCourse = $classroomCourse;
        $this->teacherMembership = $teacherMembership;
        $this->assignment = $assignment;
    }

    /**
     * @internal prefer CourseTeacherAssignmentManager
     */
    public static function bind(
        ClassroomCourse $classroomCourse,
        InstitutionMembership $teacherMembership,
        CourseTeacherAssignment $assignment,
    ): self {
        return new self($classroomCourse, $teacherMembership, $assignment);
    }

    public function getClassroomCourseId(): Uuid
    {
        return $this->classroomCourse->getId();
    }

    public function getTeacherMembershipId(): Uuid
    {
        return $this->teacherMembership->getId();
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

    #[Ignore]
    public function getAssignment(): CourseTeacherAssignment
    {
        return $this->assignment;
    }
}
