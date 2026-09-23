<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\CatalogUnit;
use Symfony\Component\Validator\Constraints as Assert;

final class CatalogUnitRequest
{
    #[Assert\NotBlank(message: 'Ünite adı zorunludur.')]
    #[Assert\Length(
        min: CatalogUnit::NAME_MIN,
        max: CatalogUnit::NAME_MAX,
        minMessage: 'Ünite adı en az {{ limit }} karakter olmalıdır.',
        maxMessage: 'Ünite adı en fazla {{ limit }} karakter olabilir.',
    )]
    public string $name = '';

    #[Assert\Length(
        max: CatalogUnit::DESCRIPTION_MAX,
        maxMessage: 'Açıklama en fazla {{ limit }} karakter olabilir.',
    )]
    public ?string $description = null;

    #[Assert\NotNull(message: 'Sıra zorunludur.')]
    #[Assert\PositiveOrZero(message: 'Sıra sıfır veya pozitif olmalıdır.')]
    public ?int $position = 0;
}
