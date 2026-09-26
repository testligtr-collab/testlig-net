<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionWorkspaceOverview
{
    public function __construct(
        public string $institutionName,
        public string $typeLabel,
        public string $statusLabel,
        public string $roleLabel,
        public string $createdAtLabel,
        public bool $canSwitch,
        public int $activeTeacherCount,
        public int $activeStudentCount,
        public int $classroomCount,
        public int $publishedTestCount,
        public int $activeDeliveryCount,
    ) {
    }
}
