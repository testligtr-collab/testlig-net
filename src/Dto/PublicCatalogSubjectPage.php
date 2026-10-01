<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PublicCatalogSubjectPage
{
    /**
     * @param list<PublicCatalogUnitCard> $units
     */
    public function __construct(
        public PublicCatalogSubjectCard $subject,
        public array $units,
    ) {
    }
}
