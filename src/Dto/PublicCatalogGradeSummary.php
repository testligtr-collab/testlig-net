<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Per-grade totals for the public catalog entry points: published subjects that
 * have at least one published unit, and the published units under them.
 */
final readonly class PublicCatalogGradeSummary
{
    public function __construct(
        public int $grade,
        public int $subjectCount,
        public int $unitCount,
    ) {
    }
}
