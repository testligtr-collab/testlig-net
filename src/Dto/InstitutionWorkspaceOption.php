<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\InstitutionMembershipRole;

final readonly class InstitutionWorkspaceOption
{
    public function __construct(
        public string $reference,
        public string $institutionName,
        public string $roleLabel,
        public InstitutionMembershipRole $role,
    ) {
    }
}
