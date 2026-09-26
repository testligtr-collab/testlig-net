<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Role-scoped counts for the content workspace. No sample data.
 */
final readonly class ContentWorkspaceSummaryView
{
    public function __construct(
        public int $draftContents,
        public int $reviewContents,
        public int $myQuestions,
        public int $myTests,
        public bool $ownContentCounts,
        public bool $canCreateContent,
        public bool $canCreateQuestion,
        public bool $canCreateTest,
        public bool $canOpenAdminHome,
    ) {
    }
}
