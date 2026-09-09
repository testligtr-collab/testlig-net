<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClassroomCourseStatus;
use App\Exception\ClassroomCourseException;
use App\Repository\ClassroomCourseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Active/archived course offering for a classroom + subject with a curriculum program.
 * Unique active (classroom, subject) enforced via ClassroomCourseActiveGuard.
 */
#[ORM\Entity(repositoryClass: ClassroomCourseRepository::class)]
#[ORM\Table(name: 'classroom_courses')]
#[ORM\UniqueConstraint(name: 'uniq_cc_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cc_id_academic_year', columns: ['id', 'academic_year_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cc_id_classroom', columns: ['id', 'classroom_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cc_id_classroom_subject', columns: ['id', 'classroom_id', 'subject_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cc_id_program_subject', columns: ['id', 'curriculum_program_id', 'subject_id'])]
#[ORM\Index(name: 'idx_cc_classroom_status', columns: ['classroom_id', 'status'])]
#[ORM\Index(name: 'idx_cc_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_cc_subject_status', columns: ['subject_id', 'status'])]
class ClassroomCourse
{
    public const WEEKLY_LESSON_HOURS_MAX = 40;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Classroom $classroom;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'curriculum_program_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CurriculumProgram $curriculumProgram;

    #[ORM\Column(length: 32, enumType: ClassroomCourseStatus::class)]
    private ClassroomCourseStatus $status;

    #[ORM\Column(name: 'weekly_lesson_hours', nullable: true)]
    private ?int $weeklyLessonHours;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Classroom $classroom,
        Subject $subject,
        CurriculumProgram $curriculumProgram,
        ?int $weeklyLessonHours,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (!$subject->getId()->equals($curriculumProgram->getSubject()->getId())) {
            throw ClassroomCourseException::subjectMismatch();
        }
        if ($classroom->getGradeLevel() !== $curriculumProgram->getGradeLevel()) {
            throw ClassroomCourseException::gradeMismatch();
        }
        self::assertValidWeeklyLessonHours($weeklyLessonHours);

        $this->id = $id ?? new UuidV7();
        $this->institution = $classroom->getInstitution();
        $this->academicYear = $classroom->getAcademicYear();
        $this->classroom = $classroom;
        $this->subject = $subject;
        $this->curriculumProgram = $curriculumProgram;
        $this->status = ClassroomCourseStatus::Active;
        $this->weeklyLessonHours = $weeklyLessonHours;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer ClassroomCourseManager
     */
    public static function create(
        Classroom $classroom,
        Subject $subject,
        CurriculumProgram $curriculumProgram,
        ?int $weeklyLessonHours,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($classroom, $subject, $curriculumProgram, $weeklyLessonHours, $now, $id);
    }

    public static function assertValidWeeklyLessonHours(?int $weeklyLessonHours): void
    {
        if (null === $weeklyLessonHours) {
            return;
        }
        if ($weeklyLessonHours < 1 || $weeklyLessonHours > self::WEEKLY_LESSON_HOURS_MAX) {
            throw ClassroomCourseException::invalidInput(
                'weekly_lesson_hours must be null or between 1 and '.self::WEEKLY_LESSON_HOURS_MAX.'.',
            );
        }
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
    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
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
    public function getCurriculumProgram(): CurriculumProgram
    {
        return $this->curriculumProgram;
    }

    public function getStatus(): ClassroomCourseStatus
    {
        return $this->status;
    }

    public function getWeeklyLessonHours(): ?int
    {
        return $this->weeklyLessonHours;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
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
    public function changeWeeklyLessonHours(?int $weeklyLessonHours, \DateTimeImmutable $now): void
    {
        $this->assertActive();
        self::assertValidWeeklyLessonHours($weeklyLessonHours);
        $this->weeklyLessonHours = $weeklyLessonHours;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function changeCurriculum(CurriculumProgram $curriculumProgram, \DateTimeImmutable $now): void
    {
        $this->assertActive();
        if (!$this->subject->getId()->equals($curriculumProgram->getSubject()->getId())) {
            throw ClassroomCourseException::subjectMismatch();
        }
        if ($this->classroom->getGradeLevel() !== $curriculumProgram->getGradeLevel()) {
            throw ClassroomCourseException::gradeMismatch();
        }
        $this->curriculumProgram = $curriculumProgram;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(ClassroomCourseStatus::Archived)) {
            throw ClassroomCourseException::invalidTransition();
        }
        $this->status = ClassroomCourseStatus::Archived;
        $this->archivedAt = $now;
        $this->updatedAt = $now;
    }

    private function assertActive(): void
    {
        if (ClassroomCourseStatus::Archived === $this->status) {
            throw ClassroomCourseException::courseNotOperable();
        }
    }
}
