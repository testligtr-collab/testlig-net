<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class WorkspaceDashboardAction
{
    public function __construct(
        public string $label,
        public string $href,
    ) {
    }
}
