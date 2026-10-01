<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PublicCatalogUnitCard
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $description,
        public int $topicCount,
        public ?string $sourceUrl,
    ) {
    }
}
