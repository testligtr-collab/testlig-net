<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GradeLevel;
use App\Exception\TeacherProfessionalProfileException;
use App\Repository\TeacherProfessionalProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One professional subject and grade selection per teacher.
 *
 * Name, email, phone, and password stay on User. Controllers must use
 * {@see \App\Service\TeacherProfessionalProfileManager}.
 */
#[ORM\Entity(repositoryClass: TeacherProfessionalProfileRepository::class)]
#[ORM\Table(name: 'teacher_professional_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_teacher_professional_profiles_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_teacher_professional_profiles_subject', columns: ['subject_id'])]
class TeacherProfessionalProfile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    /**
     * Sorted unique GradeLevel backing values.
     *
     * @var list<int>
     */
    #[ORM\Column(name: 'grade_levels', type: Types::JSON)]
    private array $gradeLevels;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<GradeLevel> $gradeLevels
     */
    private function __construct(
        User $user,
        Subject $subject,
        array $gradeLevels,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->user = $user;
        $this->subject = $subject;
        $this->gradeLevels = self::normalizeGradeValues($gradeLevels);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param list<GradeLevel> $gradeLevels
     *
     * @internal prefer TeacherProfessionalProfileManager
     */
    public static function create(
        User $user,
        Subject $subject,
        array $gradeLevels,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($user, $subject, $gradeLevels, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getUser(): User
    {
        return $this->user;
    }

    public function getSubject(): Subject
    {
        return $this->subject;
    }

    /**
     * @return list<GradeLevel>
     */
    public function getGradeLevels(): array
    {
        $levels = [];
        foreach ($this->gradeLevels as $value) {
            $level = GradeLevel::tryFrom((int) $value);
            if ($level instanceof GradeLevel) {
                $levels[$level->value] = $level;
            }
        }
        ksort($levels);

        return array_values($levels);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param list<GradeLevel> $gradeLevels
     *
     * @internal prefer TeacherProfessionalProfileManager
     */
    public function apply(Subject $subject, array $gradeLevels, \DateTimeImmutable $now): void
    {
        $this->subject = $subject;
        $this->gradeLevels = self::normalizeGradeValues($gradeLevels);
        $this->updatedAt = $now;
    }

    /**
     * @param list<GradeLevel> $gradeLevels
     *
     * @return list<int>
     */
    private static function normalizeGradeValues(array $gradeLevels): array
    {
        $values = [];
        foreach ($gradeLevels as $gradeLevel) {
            $values[$gradeLevel->value] = $gradeLevel->value;
        }
        if ([] === $values) {
            throw TeacherProfessionalProfileException::invalidGrades();
        }
        $unique = array_values($values);
        sort($unique);

        return $unique;
    }
}
