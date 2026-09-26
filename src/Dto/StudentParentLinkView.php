<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class StudentParentLinkView
{
    public function __construct(
        public string $reference,
        public string $displayName,
        public string $sinceLabel,
    ) {
    }
}
