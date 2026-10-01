<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PublicCatalogSubjectCard
{
    public function __construct(
        public int $grade,
        public string $name,
        public string $slug,
        public ?string $description,
        public int $unitCount,
        public int $topicCount,
    ) {
    }
}
