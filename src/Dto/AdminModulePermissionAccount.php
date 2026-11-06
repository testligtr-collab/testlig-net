<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class AdminModulePermissionAccount
{
    public function __construct(
        public string $id,
        public string $fullName,
        public string $email,
        public string $status,
        public string $roleLabel,
        public int $directGrantCount,
    ) {
    }
}
