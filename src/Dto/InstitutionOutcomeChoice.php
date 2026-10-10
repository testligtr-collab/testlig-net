<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionOutcomeChoice
{
    public function __construct(
        public string $reference,
        public string $code,
        public string $description,
    ) {
    }
}
