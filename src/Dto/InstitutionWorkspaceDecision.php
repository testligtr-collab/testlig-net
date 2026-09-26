<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\InstitutionMembership;

final readonly class InstitutionWorkspaceDecision
{
    /**
     * @param list<InstitutionWorkspaceOption> $options
     */
    public function __construct(
        public string $outcome,
        public array $options,
        public ?InstitutionMembership $selected,
    ) {
    }
}
