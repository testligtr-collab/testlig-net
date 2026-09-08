<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AcademicYearStudentEnrollmentGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures one active enrollment per student membership per academic year.
 * Composite FK on enrollment keys is enforced in DB (+ schema listener).
 */
#[ORM\Entity(repositoryClass: AcademicYearStudentEnrollmentGuardRepository::class)]
#[ORM\Table(name: 'academic_year_student_enrollment_guards')]
#[ORM\UniqueConstraint(name: 'uniq_ay_student_enrollment', columns: ['enrollment_id'])]
class AcademicYearStudentEnrollmentGuard
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionMembership $studentMembership;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'enrollment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClassroomStudentEnrollment $enrollment;

    private function __construct(
        AcademicYear $academicYear,
        InstitutionMembership $studentMembership,
        ClassroomStudentEnrollment $enrollment,
    ) {
        $this->academicYear = $academicYear;
        $this->studentMembership = $studentMembership;
        $this->enrollment = $enrollment;
    }

    /**
     * @internal prefer ClassroomStudentEnrollmentManager
     */
    public static function bind(
        AcademicYear $academicYear,
        InstitutionMembership $studentMembership,
        ClassroomStudentEnrollment $enrollment,
    ): self {
        return new self($academicYear, $studentMembership, $enrollment);
    }

    public function getAcademicYearId(): Uuid
    {
        return $this->academicYear->getId();
    }

    public function getStudentMembershipId(): Uuid
    {
        return $this->studentMembership->getId();
    }

    #[Ignore]
    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    #[Ignore]
    public function getStudentMembership(): InstitutionMembership
    {
        return $this->studentMembership;
    }

    #[Ignore]
    public function getEnrollment(): ClassroomStudentEnrollment
    {
        return $this->enrollment;
    }

    #[Ignore]
    public function swapTo(ClassroomStudentEnrollment $enrollment): void
    {
        $this->enrollment = $enrollment;
    }
}
