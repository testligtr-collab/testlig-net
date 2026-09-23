<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\CatalogSubject;
use App\Enum\GradeLevel;
use Symfony\Component\Validator\Constraints as Assert;

final class CatalogSubjectRequest
{
    #[Assert\NotNull(message: 'Sınıf seviyesi zorunludur.')]
    public ?GradeLevel $gradeLevel = null;

    #[Assert\NotBlank(message: 'Ders adı zorunludur.')]
    #[Assert\Length(
        min: CatalogSubject::NAME_MIN,
        max: CatalogSubject::NAME_MAX,
        minMessage: 'Ders adı en az {{ limit }} karakter olmalıdır.',
        maxMessage: 'Ders adı en fazla {{ limit }} karakter olabilir.',
    )]
    public string $name = '';

    #[Assert\Length(
        max: CatalogSubject::DESCRIPTION_MAX,
        maxMessage: 'Açıklama en fazla {{ limit }} karakter olabilir.',
    )]
    public ?string $description = null;

    #[Assert\NotNull(message: 'Sıra zorunludur.')]
    #[Assert\PositiveOrZero(message: 'Sıra sıfır veya pozitif olmalıdır.')]
    public ?int $position = 0;
}
