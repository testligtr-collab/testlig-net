<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionQuestionResolution
{
    /**
     * @param list<InstitutionQuestionSelection> $items
     */
    public function __construct(
        public bool $catalogEmpty,
        public bool $rejected,
        public array $items,
    ) {
    }
}
