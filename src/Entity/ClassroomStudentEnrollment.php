<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StudentEnrollmentStatus;
use App\Exception\ClassroomStudentEnrollmentException;
use App\Repository\ClassroomStudentEnrollmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Student membership enrolled in a classroom. Transfer ends this row and creates a new enrollment.
 * Composite tenant FKs are enforced in DB (+ schema listener).
 */
#[ORM\Entity(repositoryClass: ClassroomStudentEnrollmentRepository::class)]
#[ORM\Table(name: 'classroom_student_enrollments')]
#[ORM\UniqueConstraint(name: 'uniq_cse_id_classroom_year', columns: ['id', 'classroom_id', 'academic_year_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cse_id_year_membership', columns: ['id', 'academic_year_id', 'student_membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cse_id_classroom_year_membership', columns: ['id', 'classroom_id', 'academic_year_id', 'student_membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cse_id_classroom_membership', columns: ['id', 'classroom_id', 'student_membership_id'])]
#[ORM\Index(name: 'idx_cse_classroom_status', columns: ['classroom_id', 'status'])]
#[ORM\Index(name: 'idx_cse_year_membership_status', columns: ['academic_year_id', 'student_membership_id', 'status'])]
#[ORM\Index(name: 'idx_cse_membership_status', columns: ['student_membership_id', 'status'])]
#[ORM\Index(name: 'idx_cse_institution', columns: ['institution_id'])]
class ClassroomStudentEnrollment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Classroom $classroom;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionMembership $studentMembership;

    #[ORM\Column(length: 32, enumType: StudentEnrollmentStatus::class)]
    private StudentEnrollmentStatus $status;

    #[ORM\Column(name: 'enrolled_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $enrolledAt;

    #[ORM\Column(name: 'transferred_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $transferredAt = null;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Classroom $classroom,
        AcademicYear $academicYear,
        InstitutionMembership $studentMembership,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->institution = $classroom->getInstitution();
        $this->classroom = $classroom;
        $this->academicYear = $academicYear;
        $this->studentMembership = $studentMembership;
        $this->status = StudentEnrollmentStatus::Active;
        $this->enrolledAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer ClassroomStudentEnrollmentManager
     */
    public static function enroll(
        Classroom $classroom,
        AcademicYear $academicYear,
        InstitutionMembership $studentMembership,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($classroom, $academicYear, $studentMembership, $now, $id);
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
    public function getStudentMembership(): InstitutionMembership
    {
        return $this->studentMembership;
    }

    public function getStatus(): StudentEnrollmentStatus
    {
        return $this->status;
    }

    public function getEnrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }

    public function getTransferredAt(): ?\DateTimeImmutable
    {
        return $this->transferredAt;
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
    public function markTransferred(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(StudentEnrollmentStatus::Ended)) {
            throw ClassroomStudentEnrollmentException::invalidTransition();
        }
        $this->status = StudentEnrollmentStatus::Ended;
        $this->transferredAt = $now;
        $this->endedAt = $now;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function end(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(StudentEnrollmentStatus::Ended)) {
            throw ClassroomStudentEnrollmentException::invalidTransition();
        }
        $this->status = StudentEnrollmentStatus::Ended;
        $this->endedAt = $now;
        $this->updatedAt = $now;
    }
}
