<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\GradeLevel;
use App\Enum\LearningContentType;
use Symfony\Component\Validator\Constraints as Assert;

final class LearningContentCreateRequest
{
    #[Assert\NotBlank(message: 'Kod zorunludur.')]
    #[Assert\Length(max: 64)]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Başlık zorunludur.')]
    #[Assert\Length(max: 200)]
    public ?string $title = null;

    #[Assert\Length(max: 2000)]
    public ?string $summary = null;

    #[Assert\NotNull(message: 'İçerik türü zorunludur.')]
    public ?LearningContentType $contentType = null;

    #[Assert\NotNull(message: 'Sınıf seviyesi zorunludur.')]
    public ?GradeLevel $gradeLevel = null;

    #[Assert\NotBlank(message: 'Konu alanı zorunludur.')]
    #[Assert\Uuid]
    public ?string $subjectId = null;

    #[Assert\NotBlank(message: 'Öğrenme kazanımı zorunludur.')]
    #[Assert\Uuid]
    public ?string $learningOutcomeId = null;
}
