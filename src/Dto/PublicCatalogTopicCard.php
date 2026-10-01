<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PublicCatalogTopicCard
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $summary,
    ) {
    }
}
