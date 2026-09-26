<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class StudentOpenLinkCodeView
{
    public function __construct(
        public string $expiresLabel,
    ) {
    }
}
