<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionQuestionOption
{
    public function __construct(
        public string $reference,
        public string $code,
        public string $stem,
        public string $subjectName,
        public int $grade,
    ) {
    }
}
