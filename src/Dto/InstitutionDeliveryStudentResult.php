<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionDeliveryStudentResult
{
    public function __construct(
        public string $name,
        public string $statusLabel,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?int $correct,
        public ?int $incorrect,
        public ?int $unanswered,
        public ?string $earned,
        public ?string $maximum,
        public ?string $percentage,
    ) {
    }
}
