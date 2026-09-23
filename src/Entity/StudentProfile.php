<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GradeLevel;
use App\Exception\StudentProfileException;
use App\Repository\StudentProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Lightweight student self-profile for first-login onboarding (no child PII beyond grade/school).
 *
 * Controllers must not mutate this entity directly — use {@see \App\Service\StudentProfileManager}.
 */
#[ORM\Entity(repositoryClass: StudentProfileRepository::class)]
#[ORM\Table(name: 'student_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_student_profiles_user', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class StudentProfile
{
    public const SCHOOL_NAME_MAX = 160;
    public const CITY_MAX = 100;
    public const LEARNING_GOAL_MAX = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'grade_level', type: Types::SMALLINT, enumType: GradeLevel::class)]
    private GradeLevel $gradeLevel;

    #[ORM\Column(name: 'school_name', length: self::SCHOOL_NAME_MAX, nullable: true)]
    private ?string $schoolName = null;

    #[ORM\Column(length: self::CITY_MAX, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'learning_goal', length: self::LEARNING_GOAL_MAX, nullable: true)]
    private ?string $learningGoal = null;

    #[ORM\Column(name: 'onboarding_completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $onboardingCompletedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(User $user, GradeLevel $gradeLevel, \DateTimeImmutable $now, ?Uuid $id = null)
    {
        $this->id = $id ?? new UuidV7();
        $this->user = $user;
        $this->gradeLevel = $gradeLevel;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer StudentProfileManager
     */
    public static function createForStudent(
        User $user,
        GradeLevel $gradeLevel,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($user, $gradeLevel, $now, $id);
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

    public function getGradeLevel(): GradeLevel
    {
        return $this->gradeLevel;
    }

    public function getSchoolName(): ?string
    {
        return $this->schoolName;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getLearningGoal(): ?string
    {
        return $this->learningGoal;
    }

    public function getOnboardingCompletedAt(): ?\DateTimeImmutable
    {
        return $this->onboardingCompletedAt;
    }

    public function isOnboardingCompleted(): bool
    {
        return null !== $this->onboardingCompletedAt;
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
     * @internal prefer StudentProfileManager
     */
    public function applyProfileDetails(
        GradeLevel $gradeLevel,
        ?string $schoolName,
        ?string $city,
        ?string $learningGoal,
        \DateTimeImmutable $now,
        bool $markOnboardingComplete,
    ): void {
        $this->gradeLevel = $gradeLevel;
        $this->schoolName = self::normalizeOptionalText($schoolName, self::SCHOOL_NAME_MAX, 'Okul adı');
        $this->city = self::normalizeOptionalText($city, self::CITY_MAX, 'Şehir');
        $this->learningGoal = self::normalizeOptionalText($learningGoal, self::LEARNING_GOAL_MAX, 'Öğrenme hedefi');
        if ($markOnboardingComplete && null === $this->onboardingCompletedAt) {
            $this->onboardingCompletedAt = $now;
        }
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private static function normalizeOptionalText(?string $value, int $max, string $label): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') > $max) {
            throw StudentProfileException::invalidInput(\sprintf('%s en fazla %d karakter olabilir.', $label, $max));
        }

        return $trimmed;
    }
}
