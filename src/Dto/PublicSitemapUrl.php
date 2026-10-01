<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PublicSitemapUrl
{
    public function __construct(
        public string $path,
        public ?\DateTimeImmutable $lastModified,
    ) {
    }
}
