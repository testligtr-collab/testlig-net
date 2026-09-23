<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\StudentProfile;
use App\Enum\GradeLevel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Student onboarding / profile edit payload (no email, password, or roles).
 */
final class StudentProfileRequest
{
    #[Assert\NotNull(message: 'Sınıf seviyesi zorunludur.')]
    public ?GradeLevel $gradeLevel = null;

    #[Assert\Length(
        max: StudentProfile::SCHOOL_NAME_MAX,
        maxMessage: 'Okul adı en fazla {{ limit }} karakter olabilir.',
    )]
    public ?string $schoolName = null;

    #[Assert\Length(
        max: StudentProfile::CITY_MAX,
        maxMessage: 'Şehir en fazla {{ limit }} karakter olabilir.',
    )]
    public ?string $city = null;

    #[Assert\Length(
        max: StudentProfile::LEARNING_GOAL_MAX,
        maxMessage: 'Öğrenme hedefi en fazla {{ limit }} karakter olabilir.',
    )]
    public ?string $learningGoal = null;

    public static function fromProfile(StudentProfile $profile): self
    {
        $dto = new self();
        $dto->gradeLevel = $profile->getGradeLevel();
        $dto->schoolName = $profile->getSchoolName();
        $dto->city = $profile->getCity();
        $dto->learningGoal = $profile->getLearningGoal();

        return $dto;
    }
}
