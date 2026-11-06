<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class AdminModulePermissionDetail
{
    /**
     * @param list<string> $grantCodes
     */
    public function __construct(
        public string $id,
        public string $fullName,
        public string $email,
        public string $status,
        public bool $appliesNow,
        public string $roleLabel,
        public array $grantCodes,
        public int $version,
    ) {
    }
}
