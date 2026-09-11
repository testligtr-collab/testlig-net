<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One percentage distribution bucket for cohort score histograms.
 */
final class PercentageDistributionBucket
{
    public function __construct(
        private readonly string $label,
        private readonly int $count,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * @return array{label: string, count: int}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'count' => $this->count,
        ];
    }
}
