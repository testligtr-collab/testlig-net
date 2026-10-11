<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Subject;
use App\Entity\TeacherProfessionalProfile;
use App\Enum\GradeLevel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Teacher subject and grade selection. Identity fields stay on User.
 */
final class TeacherProfessionalProfileRequest
{
    #[Assert\NotNull(message: 'Geçerli bir aktif branş seçin.')]
    public ?Subject $subject = null;

    /**
     * @var list<GradeLevel>
     */
    #[Assert\Count(min: 1, minMessage: 'En az bir geçerli sınıf seçin.')]
    public array $gradeLevels = [];

    public ?string $provinceId = null;

    public ?string $districtId = null;

    public ?string $schoolId = null;

    /**
     * @param list<Subject> $activeSubjects
     */
    public static function fromProfile(TeacherProfessionalProfile $profile, array $activeSubjects): self
    {
        $dto = new self();
        $dto->gradeLevels = $profile->getGradeLevels();
        $subjectId = $profile->getSubject()->getId()->toRfc4122();
        foreach ($activeSubjects as $subject) {
            if ($subject->getId()->toRfc4122() === $subjectId) {
                $dto->subject = $subject;
                break;
            }
        }
        $school = $profile->getSchoolLocation();
        $district = $school?->getParent();
        $province = $district?->getParent();
        $dto->schoolId = $school?->getId()->toRfc4122();
        $dto->districtId = $district?->getId()->toRfc4122();
        $dto->provinceId = $province?->getId()->toRfc4122();

        return $dto;
    }
}
