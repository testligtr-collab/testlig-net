<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PublicCatalogUnitPage
{
    /**
     * @param list<PublicCatalogTopicCard> $topics
     */
    public function __construct(
        public PublicCatalogSubjectCard $subject,
        public PublicCatalogUnitCard $unit,
        public array $topics,
    ) {
    }
}
