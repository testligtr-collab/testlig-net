<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionTestRow
{
    public function __construct(
        public string $reference,
        public string $title,
        public string $gradeLabel,
        public string $statusLabel,
        public int $itemCount,
        public ?string $publishedAtLabel,
        public ?string $deliveryLabel,
    ) {
    }
}
