<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminCurriculumProgramCreateRequest
{
    public const REASON_SETUP = 'curriculum_setup';
    public const REASON_CORRECTION = 'curriculum_correction';

    #[Assert\NotBlank(message: 'Ders gerekli.')]
    public string $subjectId = '';

    #[Assert\NotBlank(message: 'Sınıf düzeyi gerekli.')]
    public string $gradeLevel = '';

    #[Assert\NotBlank(message: 'Kod gerekli.')]
    public string $code = '';

    #[Assert\NotBlank(message: 'Ad gerekli.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Sürüm gerekli.')]
    public string $version = '';

    #[Assert\NotBlank(message: 'İşlem gerekçesi gerekli.')]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay kutusu gerekli.')]
    public bool $confirm = false;
}
