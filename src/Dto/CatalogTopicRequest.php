<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\CatalogTopic;
use Symfony\Component\Validator\Constraints as Assert;

final class CatalogTopicRequest
{
    #[Assert\NotBlank(message: 'Konu adı zorunludur.')]
    #[Assert\Length(
        min: CatalogTopic::NAME_MIN,
        max: CatalogTopic::NAME_MAX,
        minMessage: 'Konu adı en az {{ limit }} karakter olmalıdır.',
        maxMessage: 'Konu adı en fazla {{ limit }} karakter olabilir.',
    )]
    public string $name = '';

    #[Assert\Length(
        max: CatalogTopic::SUMMARY_MAX,
        maxMessage: 'Özet en fazla {{ limit }} karakter olabilir.',
    )]
    public ?string $summary = null;

    #[Assert\NotNull(message: 'Sıra zorunludur.')]
    #[Assert\PositiveOrZero(message: 'Sıra sıfır veya pozitif olmalıdır.')]
    public ?int $position = 0;

    #[Assert\Range(
        min: CatalogTopic::ESTIMATED_MINUTES_MIN,
        max: CatalogTopic::ESTIMATED_MINUTES_MAX,
        notInRangeMessage: 'Tahmini süre {{ min }}–{{ max }} dakika olmalıdır.',
    )]
    public ?int $estimatedMinutes = null;
}
