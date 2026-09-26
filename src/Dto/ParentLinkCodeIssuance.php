<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Plain code exists only in this object. It is not read back from the database.
 */
final readonly class ParentLinkCodeIssuance
{
    public function __construct(
        public string $displayCode,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
