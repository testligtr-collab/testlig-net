<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionEnrolledStudent
{
    /**
     * @param list<InstitutionTransferTarget> $transferTargets
     */
    public function __construct(
        public string $name,
        public ?string $gradeLabel,
        public string $enrollmentReference,
        public array $transferTargets,
    ) {
    }
}
