<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClassroomStatus;
use App\Enum\GradeLevel;
use App\Exception\ClassroomException;
use App\Repository\ClassroomRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Classroom/section within an academic year. Capacity null or 1..500.
 */
#[ORM\Entity(repositoryClass: ClassroomRepository::class)]
#[ORM\Table(name: 'classrooms')]
#[ORM\UniqueConstraint(name: 'uniq_classroom_year_normalized_name', columns: ['academic_year_id', 'normalized_name'])]
#[ORM\Index(name: 'idx_classroom_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_classroom_year_status', columns: ['academic_year_id', 'status'])]
#[ORM\Index(name: 'idx_classroom_institution_year', columns: ['institution_id', 'academic_year_id'])]
class Classroom
{
    public const CAPACITY_MAX = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 180)]
    private string $normalizedName;

    #[ORM\Column(name: 'grade_level', enumType: GradeLevel::class)]
    private GradeLevel $gradeLevel;

    #[ORM\Column(name: 'section_code', length: 32, nullable: true)]
    private ?string $sectionCode;

    #[ORM\Column(length: 32, enumType: ClassroomStatus::class)]
    private ClassroomStatus $status;

    #[ORM\Column(nullable: true)]
    private ?int $capacity;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Institution $institution,
        AcademicYear $academicYear,
        string $name,
        string $normalizedName,
        GradeLevel $gradeLevel,
        ?string $sectionCode,
        ?int $capacity,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (!$academicYear->getInstitution()->getId()->equals($institution->getId())) {
            throw ClassroomException::crossInstitution();
        }
        self::assertValidCapacity($capacity);

        $this->id = $id ?? new UuidV7();
        $this->institution = $institution;
        $this->academicYear = $academicYear;
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->gradeLevel = $gradeLevel;
        $this->sectionCode = $sectionCode;
        $this->status = ClassroomStatus::Active;
        $this->capacity = $capacity;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer ClassroomManager
     */
    public static function create(
        Institution $institution,
        AcademicYear $academicYear,
        string $name,
        string $normalizedName,
        GradeLevel $gradeLevel,
        ?string $sectionCode,
        ?int $capacity,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($institution, $academicYear, $name, $normalizedName, $gradeLevel, $sectionCode, $capacity, $now, $id);
    }

    public static function assertValidCapacity(?int $capacity): void
    {
        if (null === $capacity) {
            return;
        }
        if ($capacity < 1 || $capacity > self::CAPACITY_MAX) {
            throw ClassroomException::invalidInput('Classroom capacity must be null or between 1 and '.self::CAPACITY_MAX.'.');
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getGradeLevel(): GradeLevel
    {
        return $this->gradeLevel;
    }

    public function getSectionCode(): ?string
    {
        return $this->sectionCode;
    }

    public function getStatus(): ClassroomStatus
    {
        return $this->status;
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
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
    public function rename(string $name, string $normalizedName, \DateTimeImmutable $now): void
    {
        if (ClassroomStatus::Archived === $this->status) {
            throw ClassroomException::classroomNotOperable();
        }
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function changeCapacity(?int $capacity, \DateTimeImmutable $now): void
    {
        if (ClassroomStatus::Archived === $this->status) {
            throw ClassroomException::classroomNotOperable();
        }
        self::assertValidCapacity($capacity);
        $this->capacity = $capacity;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(ClassroomStatus::Archived)) {
            throw ClassroomException::invalidTransition();
        }
        $this->status = ClassroomStatus::Archived;
        $this->updatedAt = $now;
    }
}
