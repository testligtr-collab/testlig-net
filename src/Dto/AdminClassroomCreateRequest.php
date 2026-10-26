<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Classroom;
use App\Enum\GradeLevel;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class AdminClassroomCreateRequest
{
    public const REASON_OPERATOR_SETUP = 'operator_setup';
    public const REASON_OPERATOR_CORRECTION = 'operator_correction';
    public const REASON_LIFECYCLE = 'lifecycle_change';

    #[Assert\NotBlank(message: 'Bağlı kurum gerekli.')]
    #[Assert\Uuid(message: 'Bağlı kurum geçersiz.')]
    public string $institutionId = '';

    #[Assert\NotBlank(message: 'Akademik yıl gerekli.')]
    #[Assert\Uuid(message: 'Akademik yıl geçersiz.')]
    public string $academicYearId = '';

    #[Assert\NotBlank(message: 'Sınıf adı gerekli.')]
    #[Assert\Length(max: 180, maxMessage: 'Sınıf adı en fazla {{ limit }} karakter olabilir.')]
    public string $name = '';

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Müfredat sınıf düzeyi gerekli.'),
        new Assert\Choice(callback: [self::class, 'gradeChoices'], message: 'Müfredat sınıf düzeyi geçersiz.'),
    ])]
    public string $gradeLevel = '';

    #[Assert\Length(max: 32, maxMessage: 'Şube kodu en fazla {{ limit }} karakter olabilir.')]
    public string $sectionCode = '';

    public string $capacity = '';

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'İşlem gerekçesi gerekli.'),
        new Assert\Choice(callback: [self::class, 'reasonChoices'], message: 'İşlem gerekçesi geçersiz.'),
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Sınıf oluşturmayı onaylamanız gerekir.')]
    public bool $confirm = false;

    /**
     * @return list<string>
     */
    public static function gradeChoices(): array
    {
        return array_map(static fn (GradeLevel $grade): string => (string) $grade->value, GradeLevel::cases());
    }

    /**
     * @return list<string>
     */
    public static function reasonChoices(): array
    {
        return [self::REASON_OPERATOR_SETUP, self::REASON_OPERATOR_CORRECTION, self::REASON_LIFECYCLE];
    }

    #[Assert\Callback]
    public function validateCapacity(ExecutionContextInterface $context): void
    {
        $value = trim($this->capacity);
        if ('' === $value) {
            return;
        }
        if (1 !== preg_match('/^\d{1,3}$/', $value)) {
            $context->buildViolation('Kontenjan 1 ile '.Classroom::CAPACITY_MAX.' arasında olmalı.')->atPath('capacity')->addViolation();

            return;
        }
        $capacity = (int) $value;
        if ($capacity < 1 || $capacity > Classroom::CAPACITY_MAX) {
            $context->buildViolation('Kontenjan 1 ile '.Classroom::CAPACITY_MAX.' arasında olmalı.')->atPath('capacity')->addViolation();
        }
    }
}
