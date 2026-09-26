<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ParentChildView
{
    /**
     * @param list<string>                $subjectNames
     * @param list<ParentTestSummaryView> $recentTests
     */
    public function __construct(
        public string $reference,
        public string $displayName,
        public string $gradeLabel,
        public array $subjectNames,
        public array $recentTests,
    ) {
    }
}
