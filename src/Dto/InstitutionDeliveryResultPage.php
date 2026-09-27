<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionDeliveryResultPage
{
    /**
     * @param list<InstitutionDeliveryStudentResult> $students
     */
    public function __construct(
        public string $title,
        public string $classroomName,
        public string $windowLabel,
        public int $recipientCount,
        public int $notStarted,
        public int $inProgress,
        public int $completed,
        public int $expired,
        public string $completionRate,
        public ?string $averagePercentage,
        public ?string $highestPercentage,
        public ?string $lowestPercentage,
        public array $students,
        public int $page,
        public int $pageCount,
    ) {
    }
}
