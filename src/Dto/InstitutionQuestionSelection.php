<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionQuestionSelection
{
    public function __construct(
        public string $questionId,
        public string $revisionId,
        public string $subjectId,
    ) {
    }
}
